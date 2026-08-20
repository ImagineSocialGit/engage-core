<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use InvalidArgumentException;

class MessageTemplateCompositionSchema
{
    /**
     * @return array{
     *     scope_type: string,
     *     channel: string,
     *     client_key: ?string,
     *     context_key: ?string,
     *     family_key: ?string,
     *     message_template_id: ?int,
     *     payload: array<string, mixed>
     * }
     */
    public function normalize(
        string $scopeType,
        string $channel,
        array $payload,
        ?string $clientKey = null,
        ?string $contextKey = null,
        ?string $familyKey = null,
        ?int $messageTemplateId = null,
    ): array {
        $scopeType = $this->segment($scopeType, 32, 'Composition scope');
        $channel = $this->segment($channel, 32, 'Composition channel');
        $clientKey = $this->nullableSegment($clientKey, 96, 'Composition client key');
        $contextKey = $this->nullableSegment($contextKey, 191, 'Composition context key');
        $familyKey = $this->nullableSegment($familyKey, 191, 'Composition family key');
        $messageTemplateId = $messageTemplateId !== null && $messageTemplateId > 0
            ? $messageTemplateId
            : null;

        if (! in_array($channel, ['email', 'sms'], true)) {
            throw new InvalidArgumentException(
                "Unsupported composition channel [{$channel}].",
            );
        }

        $this->assertScopeSelectors(
            scopeType: $scopeType,
            clientKey: $clientKey,
            contextKey: $contextKey,
            familyKey: $familyKey,
            messageTemplateId: $messageTemplateId,
        );

        $payload = $this->validatePayload($channel, $payload);

        return [
            'scope_type' => $scopeType,
            'channel' => $channel,
            'client_key' => $clientKey,
            'context_key' => $contextKey,
            'family_key' => $familyKey,
            'message_template_id' => $messageTemplateId,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validatePayload(string $channel, array $payload): array
    {
        if ($payload === []) {
            throw new InvalidArgumentException(
                'A composition layer must contain at least one payload field.',
            );
        }

        $allowed = $channel === 'email'
            ? ['subject', 'body', 'footer', 'cta', 'ctas', 'secondary_link']
            : ['message'];

        foreach ($payload as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException(
                    "Unsupported {$channel} composition field [".(string) $key.'].',
                );
            }

            if ($value === null) {
                continue;
            }

            if (in_array($key, ['subject', 'body', 'footer', 'message'], true)) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException(
                        "Composition field [{$key}] must be a string or null.",
                    );
                }

                continue;
            }

            if (in_array($key, ['cta', 'secondary_link'], true)) {
                $this->assertLink($key, $value);

                continue;
            }

            if ($key === 'ctas') {
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new InvalidArgumentException(
                        'Composition field [ctas] must be a list of links or null.',
                    );
                }

                foreach ($value as $index => $link) {
                    $this->assertLink("ctas.{$index}", $link);
                }
            }
        }

        return $payload;
    }

    private function assertScopeSelectors(
        string $scopeType,
        ?string $clientKey,
        ?string $contextKey,
        ?string $familyKey,
        ?int $messageTemplateId,
    ): void {
        $valid = match ($scopeType) {
            MessageTemplateCompositionLayer::SCOPE_PLATFORM =>
                $clientKey === null
                && $contextKey === null
                && $familyKey === null
                && $messageTemplateId === null,

            MessageTemplateCompositionLayer::SCOPE_CLIENT =>
                $clientKey !== null
                && $contextKey === null
                && $familyKey === null
                && $messageTemplateId === null,

            MessageTemplateCompositionLayer::SCOPE_FAMILY =>
                $clientKey !== null
                && $contextKey === null
                && $familyKey !== null
                && $messageTemplateId === null,

            MessageTemplateCompositionLayer::SCOPE_CONTEXT =>
                $clientKey !== null
                && $contextKey !== null
                && $familyKey === null
                && $messageTemplateId === null,

            MessageTemplateCompositionLayer::SCOPE_CONTEXT_FAMILY =>
                $clientKey !== null
                && $contextKey !== null
                && $familyKey !== null
                && $messageTemplateId === null,

            MessageTemplateCompositionLayer::SCOPE_MESSAGE =>
                $clientKey === null
                && $contextKey === null
                && $familyKey === null
                && $messageTemplateId !== null,

            default => false,
        };

        if (! $valid) {
            throw new InvalidArgumentException(
                "Invalid selector combination for composition scope [{$scopeType}].",
            );
        }
    }

    private function assertLink(string $key, mixed $value): void
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException(
                "Composition field [{$key}] must be a keyed link payload or null.",
            );
        }

        $unsupported = array_diff(array_keys($value), ['label', 'url']);

        if ($unsupported !== []) {
            throw new InvalidArgumentException(
                "Composition field [{$key}] contains unsupported key [{$unsupported[0]}].",
            );
        }

        foreach (['label', 'url'] as $field) {
            if (array_key_exists($field, $value)
                && $value[$field] !== null
                && ! is_string($value[$field])
            ) {
                throw new InvalidArgumentException(
                    "Composition field [{$key}.{$field}] must be a string or null.",
                );
            }
        }
    }

    private function segment(string $value, int $maximumLength, string $label): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));

        if ($value === '' || mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "{$label} must contain between 1 and {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableSegment(
        ?string $value,
        int $maximumLength,
        string $label,
    ): ?string {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->segment($value, $maximumLength, $label);
    }
}