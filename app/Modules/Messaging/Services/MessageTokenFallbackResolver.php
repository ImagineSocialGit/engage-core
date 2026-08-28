<?php

namespace App\Modules\Messaging\Services;

use Illuminate\Support\Arr;
use Stringable;

class MessageTokenFallbackResolver
{
    public const BEHAVIOR_REQUIRED = 'required';

    public const BEHAVIOR_FALLBACK_VALUE = 'fallback_value';

    public const BEHAVIOR_REPLACE_SEGMENT = 'replace_segment';

    public const BEHAVIORS = [
        self::BEHAVIOR_REQUIRED,
        self::BEHAVIOR_FALLBACK_VALUE,
        self::BEHAVIOR_REPLACE_SEGMENT,
    ];

    private const RENDERABLE_FIELDS = [
        'subject',
        'body',
        'message',
        'message_body',
        'sms_message',
        'headline',
        'preheader',
        'details',
        'cta',
        'ctas',
        'secondary_link',
        'footer',
    ];

    /**
     * Apply explicit missing-field behavior after recipient/runtime values have
     * been assembled but before the provider payload is instantiated.
     *
     * The policy is authoring/runtime control data. It is removed from the
     * provider-ready payload after it has been applied.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function apply(array $payload): array
    {
        $policies = $this->policies($payload);
        unset($payload['token_fallbacks']);

        if ($policies === []) {
            return $payload;
        }

        $tokens = is_array($payload['tokens'] ?? null)
            ? $payload['tokens']
            : [];

        foreach ($policies as $policy) {
            $token = $policy['token'];

            if (! $this->isMissing($tokens, $token)) {
                continue;
            }

            if ($policy['missing_behavior'] === self::BEHAVIOR_FALLBACK_VALUE) {
                $fallback = $policy['fallback'] ?? '';

                if (! is_string($fallback) || trim($fallback) === '') {
                    // Invalid persisted/configured policies fail safe as required.
                    continue;
                }

                data_set($tokens, $token, $fallback);

                continue;
            }

            if ($policy['missing_behavior'] === self::BEHAVIOR_REPLACE_SEGMENT) {
                $segment = $policy['segment'] ?? '';

                if ($segment !== '') {
                    $payload = $this->replaceRenderableSegment(
                        payload: $payload,
                        segment: $segment,
                        replacement: $policy['fallback'] ?? '',
                    );
                }
            }
        }

        if ($tokens !== []) {
            $payload['tokens'] = $tokens;
        } else {
            unset($payload['tokens']);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array{token: string, missing_behavior: string, fallback?: string, segment?: string}>
     */
    public function policies(array $payload): array
    {
        $raw = $payload['token_fallbacks'] ?? null;

        if (! is_array($raw) || ! array_is_list($raw)) {
            return [];
        }

        $policies = [];
        $seen = [];

        foreach ($raw as $policy) {
            if (! is_array($policy)) {
                continue;
            }

            $token = $this->nullableString($policy['token'] ?? null);
            $behavior = $this->nullableString($policy['missing_behavior'] ?? null);

            if ($token === null
                || $behavior === null
                || ! in_array($behavior, self::BEHAVIORS, true)
                || isset($seen[$token])
            ) {
                continue;
            }

            $normalized = [
                'token' => $token,
                'missing_behavior' => $behavior,
            ];

            if (in_array($behavior, [
                self::BEHAVIOR_FALLBACK_VALUE,
                self::BEHAVIOR_REPLACE_SEGMENT,
            ], true)) {
                $normalized['fallback'] = is_string($policy['fallback'] ?? null)
                    ? $policy['fallback']
                    : '';
            }

            if ($behavior === self::BEHAVIOR_REPLACE_SEGMENT) {
                $normalized['segment'] = is_string($policy['segment'] ?? null)
                    ? $policy['segment']
                    : '';
            }

            $policies[] = $normalized;
            $seen[$token] = true;
        }

        return $policies;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    public function tokenReferences(array $payload): array
    {
        unset($payload['token_fallbacks'], $payload['tokens']);

        $tokens = [];

        foreach (Arr::dot($payload) as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            preg_match_all(
                '/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/',
                $value,
                $bracedMatches,
            );
            preg_match_all(
                '/(?<![a-zA-Z0-9_]):([a-zA-Z_][a-zA-Z0-9_-]*(?:\.[a-zA-Z_][a-zA-Z0-9_-]*)*)/',
                $value,
                $colonMatches,
            );

            foreach ([
                ...($bracedMatches[1] ?? []),
                ...($colonMatches[1] ?? []),
            ] as $token) {
                if (is_string($token) && $token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /** @param array<string, mixed> $payload */
    public function hasTokenReferences(array $payload): bool
    {
        return $this->tokenReferences($payload) !== [];
    }

    public function containsTokenReference(string $value, string $token): bool
    {
        return str_contains($value, '{'.$token.'}')
            || preg_match(
                '/(?<![a-zA-Z0-9_]):'.preg_quote($token, '/').'(?![a-zA-Z0-9_.-])/',
                $value,
            ) === 1;
    }

    /**
     * @param array<string, mixed> $tokens
     */
    private function isMissing(array $tokens, string $token): bool
    {
        $sentinel = new \stdClass;
        $value = data_get($tokens, $token, $sentinel);

        if ($value === $sentinel || $value === null) {
            return true;
        }

        if (is_string($value) || $value instanceof Stringable) {
            return trim((string) $value) === '';
        }

        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function replaceRenderableSegment(
        array $payload,
        string $segment,
        string $replacement,
    ): array {
        foreach (self::RENDERABLE_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $payload[$field] = $this->replaceInValue(
                $payload[$field],
                $segment,
                $replacement,
            );
        }

        return $payload;
    }

    private function replaceInValue(
        mixed $value,
        string $segment,
        string $replacement,
    ): mixed {
        if (is_string($value)) {
            return str_replace($segment, $replacement, $value);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->replaceInValue($item, $segment, $replacement);
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }
}