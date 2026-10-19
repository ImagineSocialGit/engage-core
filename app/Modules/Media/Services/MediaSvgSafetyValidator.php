<?php

namespace App\Modules\Media\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use RuntimeException;

final class MediaSvgSafetyValidator
{
    private const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

    private const XLINK_NAMESPACE = 'http://www.w3.org/1999/xlink';

    private const ALLOWED_ELEMENTS = [
        'svg',
        'g',
        'image',
        'defs',
        'symbol',
        'use',
        'path',
        'rect',
        'circle',
        'ellipse',
        'line',
        'polyline',
        'polygon',
        'text',
        'tspan',
        'lineargradient',
        'radialgradient',
        'stop',
        'clippath',
        'mask',
        'pattern',
        'style',
        'title',
        'desc',
        'metadata',
    ];

    public function assertSafe(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The SVG upload could not be read.');
        }

        $bytes = file_get_contents($path);

        if (! is_string($bytes)) {
            throw new RuntimeException('The SVG upload could not be read.');
        }

        $this->assertSafeBytes($bytes);
    }

    public function assertSafeBytes(string $bytes): void
    {
        if ($bytes === '') {
            throw new RuntimeException('The SVG upload is empty.');
        }

        if (strlen($bytes) > $this->maximumBytes()) {
            throw new RuntimeException('The SVG upload exceeds the configured size limit.');
        }

        if (str_contains($bytes, "\0")
            || preg_match('/<!DOCTYPE|<!ENTITY/i', $bytes) === 1
        ) {
            throw new RuntimeException('The SVG upload contains unsupported XML declarations.');
        }

        if (! class_exists(DOMDocument::class)) {
            throw new RuntimeException('SVG safety validation is unavailable.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML(
                $bytes,
                LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($loaded !== true || ! $document->documentElement instanceof DOMElement) {
            throw new RuntimeException('The SVG upload is not valid XML.');
        }

        $root = $document->documentElement;

        if (strtolower((string) $root->localName) !== 'svg'
            || ! $this->allowedElementNamespace($root)
        ) {
            throw new RuntimeException('The uploaded XML document is not a supported SVG.');
        }

        $xpath = new DOMXPath($document);
        $processingInstructions = $xpath->query('//processing-instruction()');

        if ($processingInstructions !== false && $processingInstructions->length > 0) {
            throw new RuntimeException('The SVG upload contains unsupported processing instructions.');
        }

        $this->assertSafeElement($root);

        foreach ($root->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement) {
                $this->assertSafeElement($element);
            }
        }
    }

    private function assertSafeElement(DOMElement $element): void
    {
        $name = strtolower((string) $element->localName);

        if (! in_array($name, self::ALLOWED_ELEMENTS, true)
            || ! $this->allowedElementNamespace($element)
        ) {
            throw new RuntimeException("SVG element [{$name}] is not supported.");
        }

        foreach ($element->attributes as $attribute) {
            $attributeName = strtolower((string) $attribute->nodeName);
            $localName = strtolower((string) $attribute->localName);
            $value = trim((string) $attribute->nodeValue);

            if (str_starts_with($localName, 'on')) {
                throw new RuntimeException('SVG event-handler attributes are not allowed.');
            }

            if ($attributeName === 'xmlns'
                || str_starts_with($attributeName, 'xmlns:')
            ) {
                if (! in_array($value, [
                    self::SVG_NAMESPACE,
                    self::XLINK_NAMESPACE,
                ], true)) {
                    throw new RuntimeException('The SVG upload contains an unsupported XML namespace.');
                }

                continue;
            }

            if ($localName === 'href') {
                if ($value !== '' && str_starts_with($value, '#')) {
                    continue;
                }

                if ($name === 'image' && $this->isSafeEmbeddedRasterImage($value)) {
                    continue;
                }

                throw new RuntimeException(
                    'SVG links must reference a local fragment or an embedded raster image.',
                );
            }

            $this->assertSafeValue($value);
        }

        if ($name === 'style') {
            $this->assertSafeValue((string) $element->textContent);
        }
    }

    private function assertSafeValue(string $value): void
    {
        $lower = strtolower($value);

        foreach ([
            'javascript:',
            'vbscript:',
            'data:',
            'expression(',
            '@import',
            'behavior:',
            '-moz-binding',
        ] as $blocked) {
            if (str_contains($lower, $blocked)) {
                throw new RuntimeException('The SVG upload contains unsafe active content.');
            }
        }

        if (preg_match_all('/url\(\s*([^)]+)\s*\)/i', $value, $matches) > 0) {
            foreach ($matches[1] as $target) {
                $target = trim((string) $target, " \t\n\r\0\x0B\"'");

                if ($target === '' || ! str_starts_with($target, '#')) {
                    throw new RuntimeException('SVG resource URLs must reference a local fragment.');
                }
            }
        }

        if (preg_match('~(?:^|[\s("\'])https?://~i', $value) === 1
            || preg_match('~(?:^|[\s("\'])//~', $value) === 1
        ) {
            throw new RuntimeException('External SVG resource URLs are not allowed.');
        }
    }

    private function isSafeEmbeddedRasterImage(string $value): bool
    {
        if (preg_match(
            '/\Adata:image\/(png|jpeg|gif|webp);base64,([A-Za-z0-9+\/=\r\n]+)\z/i',
            $value,
            $matches,
        ) !== 1) {
            return false;
        }

        $encoded = preg_replace('/\s+/', '', $matches[2]);

        if (! is_string($encoded) || $encoded === '') {
            return false;
        }

        $bytes = base64_decode($encoded, true);

        if (! is_string($bytes) || $bytes === '') {
            return false;
        }

        return match (strtolower($matches[1])) {
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpeg' => str_starts_with($bytes, "\xff\xd8\xff"),
            'gif' => str_starts_with($bytes, 'GIF87a')
                || str_starts_with($bytes, 'GIF89a'),
            'webp' => strlen($bytes) >= 12
                && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP',
            default => false,
        };
    }

    private function allowedElementNamespace(DOMElement $element): bool
    {
        return $element->namespaceURI === null
            || $element->namespaceURI === ''
            || $element->namespaceURI === self::SVG_NAMESPACE;
    }

    private function maximumBytes(): int
    {
        return max(
            1,
            (int) config('media.svg.max_kilobytes', 2048),
        ) * 1024;
    }
}