<?php

namespace App\Modules\InboundMessaging\Services\ReplyProfiles;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class ReplyProfileDefinitionNormalizer
{
    public const MAX_PROFILES = 100;
    public const MAX_INTENTS = 20;
    public const MAX_RULES_PER_TYPE = 50;
    public const MAX_RULE_LENGTH = 255;

    /**
     * @return array{
     *     source: string,
     *     profiles: array<string, array<string, mixed>>
     * }
     */
    public function configured(): array
    {
        $configured = config('inbound_messaging.reply_profiles');

        if (is_array($configured) && $configured !== []) {
            return [
                'source' => 'inbound_messaging.reply_profiles',
                'profiles' => $this->profiles($configured),
            ];
        }

        $legacy = config('messaging.reply_profiles', []);

        return [
            'source' => 'messaging.reply_profiles',
            'profiles' => $this->profiles($legacy),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function profiles(mixed $profiles): array
    {
        if (! is_array($profiles)) {
            throw new InvalidArgumentException('Reply profiles must be an array.');
        }

        if (count($profiles) > self::MAX_PROFILES) {
            throw new InvalidArgumentException(
                'Reply profile configuration may not contain more than '.self::MAX_PROFILES.' profiles.',
            );
        }

        $normalized = [];

        foreach ($profiles as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidArgumentException(
                    'Each reply profile requires a non-empty string key and array definition.',
                );
            }

            $profile = $this->profile($key, $definition);
            $normalized[$profile['key']] = $profile;
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param array<string, mixed> $definition */
    public function profile(string $key, array $definition): array
    {
        $key = $this->key($key, 'Reply profile key');
        $intents = $definition['intents'] ?? null;

        if (! is_array($intents) || $intents === []) {
            throw new InvalidArgumentException(
                "Reply profile [{$key}] requires at least one intent.",
            );
        }

        if (count($intents) > self::MAX_INTENTS) {
            throw new InvalidArgumentException(
                "Reply profile [{$key}] may not contain more than ".self::MAX_INTENTS.' intents.',
            );
        }

        $normalizedIntents = [];
        $sortOrder = 10;

        foreach ($intents as $intentKey => $intent) {
            if (is_int($intentKey) && is_array($intent)) {
                $intentKey = $intent['key'] ?? null;
            }

            if (! is_string($intentKey) || ! is_array($intent)) {
                throw new InvalidArgumentException(
                    "Reply profile [{$key}] contains an invalid intent definition.",
                );
            }

            $intentKey = $this->key($intentKey, "Reply profile [{$key}] intent key");
            $exact = $this->ruleValues(
                $intent['exact'] ?? [],
                "Reply profile [{$key}] intent [{$intentKey}] exact rules",
                'exact',
            );
            $keywords = $this->ruleValues(
                $intent['keywords'] ?? [],
                "Reply profile [{$key}] intent [{$intentKey}] keyword rules",
                'keyword',
            );

            if ($exact === [] && $keywords === []) {
                throw new InvalidArgumentException(
                    "Reply profile [{$key}] intent [{$intentKey}] requires at least one exact or keyword rule.",
                );
            }

            $normalizedIntents[$intentKey] = [
                'key' => $intentKey,
                'label' => $this->requiredString(
                    $intent['label'] ?? Str::headline($intentKey),
                    "Reply profile [{$key}] intent [{$intentKey}] label",
                ),
                'description' => $this->nullableString($intent['description'] ?? null),
                'is_active' => (bool) ($intent['is_active'] ?? $intent['enabled'] ?? true),
                'sort_order' => (int) ($intent['sort_order'] ?? $sortOrder),
                'exact' => $exact,
                'keywords' => $keywords,
            ];
            $sortOrder += 10;
        }

        return [
            'key' => $key,
            'label' => $this->requiredString(
                $definition['label'] ?? Str::headline($key),
                "Reply profile [{$key}] label",
            ),
            'description' => $this->nullableString($definition['description'] ?? null),
            'is_active' => (bool) ($definition['is_active'] ?? $definition['enabled'] ?? true),
            'intents' => $normalizedIntents,
        ];
    }

    public function normalizedRuleValue(string $matchType, string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        if ($matchType === 'exact') {
            $value = preg_replace('/^[\pP\pS\s]+|[\pP\pS\s]+$/u', '', $value) ?? $value;
        }

        return trim($value);
    }

    /** @return array<int, string> */
    private function ruleValues(mixed $values, string $field, string $matchType): array
    {
        if (is_string($values)) {
            $values = preg_split('/\r\n|\r|\n/', $values) ?: [];
        }

        if (! is_array($values)) {
            throw new InvalidArgumentException("{$field} must be an array or newline-separated string.");
        }

        if (count($values) > self::MAX_RULES_PER_TYPE) {
            throw new InvalidArgumentException(
                "{$field} may not contain more than ".self::MAX_RULES_PER_TYPE.' values.',
            );
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $value = trim($value);

            if (mb_strlen($value) > self::MAX_RULE_LENGTH) {
                throw new InvalidArgumentException(
                    "{$field} values may not exceed ".self::MAX_RULE_LENGTH.' characters.',
                );
            }

            $identity = $this->normalizedRuleValue($matchType, $value);

            if ($identity !== '') {
                $normalized[$identity] ??= $value;
            }
        }

        return array_values($normalized);
    }

    private function key(string $value, string $field): string
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $value) !== 1) {
            throw new InvalidArgumentException(
                "{$field} must use lowercase letters, numbers, and single underscores.",
            );
        }

        if (mb_strlen($value) > 96) {
            throw new InvalidArgumentException("{$field} may not exceed 96 characters.");
        }

        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException("{$field} is required.");
        }

        $value = trim($value);

        if (mb_strlen($value) > 255) {
            throw new InvalidArgumentException("{$field} may not exceed 255 characters.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}