<?php

namespace App\Modules\Messaging\Payloads;

use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Stringable;

class SmsPayload implements SmsMessage
{
    public function __construct(
        public readonly string $to,
        public readonly string $channel,
        public readonly string $purpose,
        public readonly string $scope,
        public readonly string $messageType,
        public readonly ?string $message = null,
        public readonly array $tokens = [],
        public readonly ?string $sourceIp = null,
        public readonly array $meta = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        $to = $payload['to']
            ?? $payload['phone']
            ?? $payload['contact_phone']
            ?? null;

        $channel = $payload['channel'] ?? 'sms';
        $purpose = $payload['purpose'] ?? null;
        $scope = $payload['scope'] ?? null;
        $messageType = $payload['message_type'] ?? null;

        $message = $payload['message']
            ?? $payload['body']
            ?? $payload['message_body']
            ?? null;

        if (! is_string($to) || trim($to) === '') {
            throw new InvalidArgumentException('SMS payload requires a destination phone number.');
        }

        if (! is_string($purpose) || trim($purpose) === '') {
            throw new InvalidArgumentException('SMS payload requires a purpose.');
        }

        if (! is_string($scope) || trim($scope) === '') {
            throw new InvalidArgumentException('SMS payload requires a scope.');
        }

        if (! is_string($messageType) || trim($messageType) === '') {
            throw new InvalidArgumentException('SMS payload requires a message_type.');
        }

        return new self(
            to: trim($to),
            channel: trim((string) $channel),
            purpose: trim($purpose),
            scope: trim($scope),
            messageType: trim($messageType),
            message: is_string($message) ? $message : null,
            tokens: self::resolveTokens($payload),
            sourceIp: self::nullableString($payload['source_ip'] ?? $payload['request_ip'] ?? null),
            meta: is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
        );
    }

    public function to(): string
    {
        return $this->to;
    }

    public function message(): string
    {
        $body = $this->message;

        if (! is_string($body) || trim($body) === '') {
            $body = config($this->payloadConfigPath('message'));
        }

        if (! is_string($body) || trim($body) === '') {
            throw new InvalidArgumentException(
                "SMS message body is not configured for [{$this->channel}.{$this->purpose}.{$this->scope}.{$this->messageType}]."
            );
        }

        $message = trim($this->interpolate($body));

        if ($this->shouldPrefixBrand()) {
            return trim(config('brand.name', config('app.name')).': '.$message);
        }

        return $message;
    }

    public function kind(): string
    {
        return $this->messageType;
    }

    public function purpose(): string
    {
        return $this->purpose;
    }

    public function devPayload(): array
    {
        return [
            'to' => $this->to(),
            'kind' => $this->kind(),
            'channel' => $this->channel,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'message_type' => $this->messageType,
            'message' => $this->message(),
            'tokens' => $this->tokens,
            'meta' => $this->meta,
            'source_ip' => $this->sourceIp(),
        ];
    }

    public function sourceIp(): ?string
    {
        return $this->sourceIp;
    }

    private function payloadConfigPath(string $key): string
    {
        return MessageDefinitionConfigPath::payloadField(
            channel: $this->channel,
            purpose: $this->purpose,
            scope: $this->scope,
            messageType: $this->messageType,
            field: $key,
        );
    }

    private function shouldPrefixBrand(): bool
    {
        $configured = config($this->payloadConfigPath('prefix_brand'));

        if (is_bool($configured)) {
            return $configured;
        }

        return (bool) ($this->meta['prefix_brand'] ?? false);
    }

    private function interpolate(string $value): string
    {
        $replacements = [];

        foreach (Arr::dot($this->tokens) as $key => $tokenValue) {
            if (! self::isStringableValue($tokenValue)) {
                continue;
            }

            $tokenValue = (string) $tokenValue;

            $replacements['{'.$key.'}'] = $tokenValue;
            $replacements[':'.$key] = $tokenValue;
        }

        return strtr($value, $replacements);
    }

    private static function resolveTokens(array $payload): array
    {
        $tokens = [];

        if (is_array($payload['runtime_context'] ?? null)) {
            $tokens = array_replace_recursive($tokens, $payload['runtime_context']);
        }

        if (is_array($payload['context'] ?? null)) {
            $tokens = array_replace_recursive($tokens, $payload['context']);
        }

        if (is_array($payload['tokens'] ?? null)) {
            $tokens = array_replace_recursive($tokens, $payload['tokens']);
        }

        return $tokens;
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
