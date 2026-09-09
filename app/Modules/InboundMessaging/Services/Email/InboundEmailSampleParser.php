<?php

namespace App\Modules\InboundMessaging\Services\Email;

use Illuminate\Validation\ValidationException;

final class InboundEmailSampleParser
{
    private const MAX_RAW_BYTES = 2_097_152;
    private const MAX_BODY_BYTES = 50_000;
    private const MAX_MULTIPART_DEPTH = 4;

    /**
     * @return array{
     *     from: ?string,
     *     reply_to: ?string,
     *     subject: ?string,
     *     body: ?string,
     *     body_source: string
     * }
     */
    public function parse(string $raw): array
    {
        if ($raw === '' || strlen($raw) > self::MAX_RAW_BYTES) {
            throw ValidationException::withMessages([
                'sample_eml' => 'Choose a readable .eml file no larger than 2 MB.',
            ]);
        }

        [$headers, $body] = $this->splitEntity($raw);
        $parsedHeaders = $this->headers($headers);

        $bodyResult = $this->textBody(
            headers: $parsedHeaders,
            body: $body,
            depth: 0,
        );

        return [
            'from' => $this->headerValue($parsedHeaders, 'from'),
            'reply_to' => $this->headerValue($parsedHeaders, 'reply-to'),
            'subject' => $this->headerValue($parsedHeaders, 'subject'),
            'body' => $bodyResult['body'],
            'body_source' => $bodyResult['source'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitEntity(string $raw): array
    {
        $parts = preg_split("/\r?\n\r?\n/", $raw, 2);

        if (! is_array($parts) || count($parts) < 2) {
            return [$raw, ''];
        }

        return [(string) $parts[0], (string) $parts[1]];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function headers(string $rawHeaders): array
    {
        $lines = preg_split("/\r?\n/", $rawHeaders) ?: [];
        $unfolded = [];

        foreach ($lines as $line) {
            if ($line !== '' && preg_match('/^[ \t]/', $line) === 1 && $unfolded !== []) {
                $lastIndex = array_key_last($unfolded);
                $unfolded[$lastIndex] .= ' '.trim($line);

                continue;
            }

            $unfolded[] = $line;
        }

        $headers = [];

        foreach ($unfolded as $line) {
            if (! is_string($line) || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = $this->decodeHeader(trim($value));

            if ($name === '' || $value === '') {
                continue;
            }

            $headers[$name] ??= [];
            $headers[$name][] = $value;
        }

        return $headers;
    }

    /**
     * @param array<string, array<int, string>> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        $value = $headers[strtolower($name)][0] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function decodeHeader(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! function_exists('iconv_mime_decode')) {
            return $value;
        }

        $decoded = iconv_mime_decode(
            $value,
            ICONV_MIME_DECODE_CONTINUE_ON_ERROR,
            'UTF-8',
        );

        return is_string($decoded) && $decoded !== ''
            ? $decoded
            : $value;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array{body: ?string, source: string}
     */
    private function textBody(array $headers, string $body, int $depth): array
    {
        if ($depth > self::MAX_MULTIPART_DEPTH) {
            return ['body' => null, 'source' => 'none'];
        }

        $contentType = strtolower(
            $this->headerValue($headers, 'content-type') ?? 'text/plain',
        );

        if (str_starts_with($contentType, 'multipart/')) {
            $boundary = $this->parameter(
                $this->headerValue($headers, 'content-type'),
                'boundary',
            );

            if ($boundary === null) {
                return ['body' => null, 'source' => 'none'];
            }

            $plain = null;
            $html = null;

            foreach ($this->multipartEntities($body, $boundary) as $entity) {
                [$partHeadersRaw, $partBody] = $this->splitEntity($entity);
                $partHeaders = $this->headers($partHeadersRaw);
                $disposition = strtolower(
                    $this->headerValue($partHeaders, 'content-disposition') ?? '',
                );

                if (str_starts_with($disposition, 'attachment')) {
                    continue;
                }

                $result = $this->textBody(
                    headers: $partHeaders,
                    body: $partBody,
                    depth: $depth + 1,
                );

                if ($result['body'] === null) {
                    continue;
                }

                if ($result['source'] === 'text/plain') {
                    $plain ??= $result['body'];
                }

                if ($result['source'] === 'text/html') {
                    $html ??= $result['body'];
                }
            }

            if ($plain !== null) {
                return ['body' => $plain, 'source' => 'text/plain'];
            }

            if ($html !== null) {
                return ['body' => $html, 'source' => 'text/html'];
            }

            return ['body' => null, 'source' => 'none'];
        }

        $decoded = $this->decodeTransferEncoding(
            body: $body,
            encoding: $this->headerValue($headers, 'content-transfer-encoding'),
        );
        $decoded = $this->convertCharset(
            body: $decoded,
            charset: $this->parameter(
                $this->headerValue($headers, 'content-type'),
                'charset',
            ),
        );

        if (str_starts_with($contentType, 'text/html')) {
            return [
                'body' => $this->boundedText($this->htmlToText($decoded)),
                'source' => 'text/html',
            ];
        }

        if (! str_starts_with($contentType, 'text/')
            && $this->headerValue($headers, 'content-type') !== null
        ) {
            return ['body' => null, 'source' => 'none'];
        }

        return [
            'body' => $this->boundedText($decoded),
            'source' => 'text/plain',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function multipartEntities(string $body, string $boundary): array
    {
        $delimiter = '--'.$boundary;
        $closing = $delimiter.'--';
        $lines = preg_split("/\r?\n/", $body) ?: [];
        $entities = [];
        $current = [];
        $collecting = false;

        foreach ($lines as $line) {
            $boundaryLine = rtrim((string) $line, " \t");

            if ($boundaryLine === $delimiter || $boundaryLine === $closing) {
                if ($collecting && $current !== []) {
                    $entities[] = implode("\r\n", $current);
                }

                $current = [];
                $collecting = $boundaryLine !== $closing;

                if ($boundaryLine === $closing) {
                    break;
                }

                continue;
            }

            if ($collecting) {
                $current[] = $line;
            }
        }

        if ($collecting && $current !== []) {
            $entities[] = implode("\r\n", $current);
        }

        return $entities;
    }

    private function parameter(?string $value, string $parameter): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $parameter = preg_quote($parameter, '/');

        if (preg_match(
            '/(?:^|;)\s*'.$parameter.'\s*=\s*(?:"([^"]+)"|([^;\s]+))/iu',
            $value,
            $matches,
        ) !== 1) {
            return null;
        }

        $resolved = trim((string) (($matches[1] ?? '') !== ''
            ? $matches[1]
            : ($matches[2] ?? '')));

        return $resolved !== '' ? $resolved : null;
    }

    private function decodeTransferEncoding(string $body, ?string $encoding): string
    {
        $encoding = strtolower(trim((string) $encoding));

        if ($encoding === 'base64') {
            $decoded = base64_decode(
                preg_replace('/\s+/', '', $body) ?? $body,
                true,
            );

            return is_string($decoded) ? $decoded : '';
        }

        if ($encoding === 'quoted-printable') {
            return quoted_printable_decode($body);
        }

        return $body;
    }

    private function convertCharset(string $body, ?string $charset): string
    {
        if (! is_string($charset) || trim($charset) === '') {
            return $body;
        }

        $charset = trim($charset);

        if (strcasecmp($charset, 'UTF-8') === 0 || ! function_exists('mb_convert_encoding')) {
            return $body;
        }

        try {
            return mb_convert_encoding($body, 'UTF-8', $charset);
        } catch (\ValueError) {
            return $body;
        }
    }

    private function htmlToText(string $html): string
    {
        $html = preg_replace('/<\s*br\s*\/?>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\/\s*(p|div|li|tr|h[1-6])\s*>/iu', "\n", $html) ?? $html;
        $text = strip_tags($html);

        return html_entity_decode(
            $text,
            ENT_QUOTES | ENT_HTML5,
            'UTF-8',
        );
    }

    private function boundedText(string $body): ?string
    {
        $body = str_replace("\0", '', $body);
        $body = preg_replace("/\r\n?/", "\n", $body) ?? $body;

        if (! mb_check_encoding($body, 'UTF-8')) {
            $body = mb_convert_encoding($body, 'UTF-8', 'UTF-8');
        }

        $body = trim($body);

        if ($body === '') {
            return null;
        }

        return mb_substr($body, 0, self::MAX_BODY_BYTES);
    }
}