<?php

namespace App\Messaging\Payloads;

use App\Contracts\Messaging\Email\EmailMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Stringable;

class EmailPayload implements EmailMessage
{
    private const DEFAULT_VIEW = 'email';

    public function __construct(
        public readonly string $to,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly ?string $subject = null,
        public readonly ?string $body = null,
        public readonly ?string $view = null,
        public readonly array $tokens = [],
        public readonly ?string $sourceIp = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        $to = $payload['to']
            ?? $payload['email']
            ?? $payload['contact_email']
            ?? null;

        if (! is_string($to) || trim($to) === '') {
            throw new InvalidArgumentException('Email payload requires a destination email address.');
        }

        return new self(
            to: trim($to),
            channel: trim((string) ($payload['channel'] ?? 'email')),
            purpose: trim((string) ($payload['purpose'] ?? '')),
            scope: trim((string) ($payload['scope'] ?? '')),
            messageType: trim((string) ($payload['message_type'] ?? '')),

            subject: self::nullableString($payload['subject'] ?? null),

            body: self::nullableString(
                $payload['body']
                    ?? $payload['message']
                    ?? $payload['message_body']
                    ?? null
            ),

            view: self::nullableString($payload['view'] ?? null),

            tokens: self::resolveTokens($payload),

            sourceIp: self::nullableString(
                $payload['source_ip']
                    ?? $payload['request_ip']
                    ?? null
            ),

            meta: is_array($payload['meta'] ?? null)
                ? $payload['meta']
                : [],
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function subject(): string
    {
        $subject = $this->subject
            ?? config($this->payloadConfigPath('subject'));

        if (! is_string($subject) || trim($subject) === '') {
            throw new InvalidArgumentException(
                "Email subject is not configured for [{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}]."
            );
        }

        return trim($this->interpolate($subject));
    }

    public function text(): string
    {
        $body = $this->body
            ?? config($this->payloadConfigPath('body'));

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException(
                "Email body is not configured for [{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}]."
            );
        }

        return trim($this->interpolate($body));
    }

    public function html(): string
    {
        return View::make(
            $this->view(),
            [
                ...$this->tokens,

                'subject' => $this->subject(),

                'headline' => $this->interpolate(
                    (string) (
                        config($this->payloadConfigPath('headline'))
                        ?? $this->subject()
                    )
                ),

                'preheader' => $this->configValue('preheader'),

                'body' => $this->bodyLines(),

                'details' => $this->configArray('details'),

                'cta' => $this->interpolateRecursive(
                    $this->configArray('cta')
                ),

                'secondary_link' => $this->interpolateRecursive(
                    $this->configArray('secondary_link')
                ),

                'footer' => $this->configValue('footer'),

                'unsubscribeUrl' => $this->configValue('unsubscribe_url'),

                'transactionalOptOutUrl' => $this->configValue('transactional_opt_out_url'),
            ]
        )->render();
    }

    public function mailable(): Mailable
    {
        return new class($this->subject(), $this->html()) extends Mailable {
            public function __construct(
                private readonly string $subjectLine,
                private readonly string $htmlBody,
            ) {}

            public function build(): self
            {
                return $this
                    ->subject($this->subjectLine)
                    ->html($this->htmlBody);
            }
        };
    }

    public function kind(): string
    {
        return $this->messageType;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to,
            'subject' => $this->subject(),
            'text' => $this->text(),
            'view' => $this->view(),
            'tokens' => $this->tokens,
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }

    private function view(): string
    {
        return $this->view
            ?? config($this->payloadConfigPath('view'))
            ?? self::DEFAULT_VIEW;
    }

    private function bodyLines(): array
    {
        return array_values(array_filter(
            preg_split('/\r\n|\n|\r/', $this->text()) ?: []
        ));
    }

    private function configValue(string $key): ?string
    {
        $value = config($this->payloadConfigPath($key));

        if (! is_string($value)) {
            return null;
        }

        return $this->interpolate($value);
    }

    private function configArray(string $key): array
    {
        $value = config($this->payloadConfigPath($key));

        return is_array($value)
            ? $value
            : [];
    }

    private function payloadConfigPath(string $key): string
    {
        return "messaging.{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}.payload.{$key}";
    }

    private function interpolate(string $value): string
    {
        $replacements = [];

        foreach (Arr::dot($this->tokens) as $key => $tokenValue) {
            if (! self::isStringableValue($tokenValue)) {
                continue;
            }

            $replacements["{{$key}}"] = (string) $tokenValue;
            $replacements[":{$key}"] = (string) $tokenValue;
        }

        return strtr($value, $replacements);
    }

    private function interpolateRecursive(array $values): array
    {
        array_walk_recursive($values, function (&$value) {
            if (is_string($value)) {
                $value = $this->interpolate($value);
            }
        });

        return $values;
    }

    private static function resolveTokens(array $payload): array
    {
        return array_replace_recursive(
            is_array($payload['runtime_context'] ?? null) ? $payload['runtime_context'] : [],
            is_array($payload['context'] ?? null) ? $payload['context'] : [],
            is_array($payload['tokens'] ?? null) ? $payload['tokens'] : [],
        );
    }

    private static function isStringableValue(mixed $value): bool
    {
        return is_scalar($value) || $value instanceof Stringable;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}