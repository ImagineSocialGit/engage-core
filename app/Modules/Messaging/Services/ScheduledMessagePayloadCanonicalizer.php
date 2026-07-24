<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Sms\SmsMessage;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Modules\Messaging\Payloads\Internal\InternalSmsNotificationPayload;
use App\Modules\Messaging\Support\MessageDefinitionConfigPath;
use DateTimeInterface;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use JsonException;
use Stringable;

class ScheduledMessagePayloadCanonicalizer
{
    public const MAX_DEPTH = 8;

    public const MAX_LIST_ITEMS = 50;

    public const MAX_MAP_ITEMS = 100;

    public const MAX_STRING_BYTES = 32768;

    public const MAX_ENCODED_BYTES = 65536;

    private const KIND_EMAIL = 'email';

    private const KIND_SMS = 'sms';

    private const KIND_INTERNAL_EMAIL = 'internal_email';

    private const KIND_INTERNAL_SMS = 'internal_sms';

    private const KIND_GENERIC = 'generic';

    /**
     * Canonicalize a new runtime write after freezing any payload fields that
     * the payload class would otherwise resolve from configuration at send time.
     *
     * @param array<string, mixed> $payload
     * @param array<int|string, mixed> $conditions
     * @return array<string, mixed>
     */
    public function forPersistence(
        string $payloadClass,
        array $payload,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
        array $conditions = [],
    ): array {
        $payload = $this->withConfigFallbacks(
            payloadClass: $payloadClass,
            payload: $payload,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: $messageType,
        );

        return $this->canonicalize(
            payloadClass: $payloadClass,
            payload: $payload,
            conditions: $conditions,
        );
    }

    /**
     * Canonicalize an already-current payload. Runtime readers and
     * consolidation use this path and never inspect legacy token containers.
     *
     * @param array<string, mixed> $payload
     * @param array<int|string, mixed> $conditions
     * @return array<string, mixed>
     */
    public function canonicalize(
        string $payloadClass,
        array $payload,
        array $conditions = [],
    ): array {
        $kind = $this->payloadKind($payloadClass);
        $tokens = is_array($payload['tokens'] ?? null)
            ? $payload['tokens']
            : [];
        $canonical = [];

        $this->copyDestination($canonical, $payload, $kind);

        if ($kind === self::KIND_EMAIL) {
            $this->copyNullableInt(
                target: $canonical,
                key: 'contact_id',
                value: $payload['contact_id']
                    ?? data_get($tokens, 'contact.id'),
            );
        }

        match ($kind) {
            self::KIND_EMAIL => $this->copyEmailPayload($canonical, $payload),
            self::KIND_SMS => $this->copySmsPayload($canonical, $payload),
            self::KIND_INTERNAL_EMAIL => $this->copyInternalEmailPayload($canonical, $payload),
            self::KIND_INTERNAL_SMS => $this->copyInternalSmsPayload($canonical, $payload),
            default => $this->copyGenericPayload($canonical, $payload),
        };

        $projectedTokens = $this->projectReferencedTokens(
            tokens: $tokens,
            payload: $canonical,
            conditions: $conditions,
        );

        if ($projectedTokens !== []) {
            $canonical['tokens'] = $projectedTokens;
        }

        $canonical = $this->sanitizeArray($canonical, 0);
        $this->assertEncodedSize($canonical);

        return $canonical;
    }

    /**
     * Pure legacy-to-canonical transformation for versioned imports.
     *
     * @param array<string, mixed> $payload
     * @param array<int|string, mixed> $conditions
     * @return array<string, mixed>
     */
    public function canonicalizeImportedPayload(
        string $payloadClass,
        array $payload,
        array $conditions = [],
    ): array {
        $payload['tokens'] = $this->importedTokenCandidates($payload);

        return $this->canonicalize(
            payloadClass: $payloadClass,
            payload: $payload,
            conditions: $conditions,
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    public function canonicalizeImportedRecord(array $record): array
    {
        $payloadClass = $record['payload_class'] ?? null;
        $payload = $record['payload'] ?? null;

        if (! is_string($payloadClass) || ! is_array($payload)) {
            return $record;
        }

        $record['payload'] = $this->canonicalizeImportedPayload(
            payloadClass: $payloadClass,
            payload: $payload,
            conditions: is_array(data_get($record, 'meta.conditions'))
                ? data_get($record, 'meta.conditions')
                : [],
        );

        return $record;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withConfigFallbacks(
        string $payloadClass,
        array $payload,
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
    ): array {
        $kind = $this->payloadKind($payloadClass);

        $fields = match ($kind) {
            self::KIND_EMAIL => [
                'subject',
                'body',
                'view',
                'cta',
                'ctas',
                'secondary_link',
                'footer',
                'unsubscribe_url',
                'transactional_opt_out_url',
            ],
            self::KIND_SMS => [
                'message',
            ],
            default => [],
        };

        foreach ($fields as $field) {
            if ($this->hasUsableValue($payload[$field] ?? null)) {
                continue;
            }

            $value = config(MessageDefinitionConfigPath::payloadField(
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                messageType: $messageType,
                field: $field,
            ));

            if ($this->hasUsableValue($value)) {
                $payload[$field] = $value;
            }
        }

        if ($kind === self::KIND_SMS) {
            $prefixBrand = config(MessageDefinitionConfigPath::payloadField(
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                messageType: $messageType,
                field: 'prefix_brand',
            ));

            if (is_bool($prefixBrand)) {
                $payload['meta'] = array_replace_recursive(
                    is_array($payload['meta'] ?? null)
                        ? $payload['meta']
                        : [],
                    ['prefix_brand' => $prefixBrand],
                );
            }
        }

        return $payload;
    }

    private function payloadKind(string $payloadClass): string
    {
        if ($payloadClass === InternalEmailNotificationPayload::class) {
            return self::KIND_INTERNAL_EMAIL;
        }

        if ($payloadClass === InternalSmsNotificationPayload::class) {
            return self::KIND_INTERNAL_SMS;
        }

        if (is_a($payloadClass, EmailMessage::class, true)) {
            return self::KIND_EMAIL;
        }

        if (is_a($payloadClass, SmsMessage::class, true)) {
            return self::KIND_SMS;
        }

        return self::KIND_GENERIC;
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copyDestination(
        array &$canonical,
        array $payload,
        string $kind,
    ): void {
        $keys = match ($kind) {
            self::KIND_EMAIL, self::KIND_INTERNAL_EMAIL => [
                'to',
                'email',
                'contact_email',
            ],
            self::KIND_SMS, self::KIND_INTERNAL_SMS => [
                'to',
                'phone',
                'contact_phone',
            ],
            default => [
                'to',
                'email',
                'phone',
                'contact_email',
                'contact_phone',
            ],
        };

        foreach ($keys as $key) {
            $destination = $payload[$key] ?? null;

            if (is_string($destination) && trim($destination) !== '') {
                $canonical['to'] = trim($destination);

                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copyEmailPayload(array &$canonical, array $payload): void
    {
        $this->copyNullableString($canonical, 'subject', $payload['subject'] ?? null);
        $this->copyNullableString(
            $canonical,
            'body',
            $payload['body']
                ?? $payload['message']
                ?? $payload['message_body']
                ?? null,
        );
        $this->copyNullableString($canonical, 'view', $payload['view'] ?? null);
        $this->copyArray($canonical, 'cta', $payload['cta'] ?? null);
        $this->copyList($canonical, 'ctas', $payload['ctas'] ?? null);
        $this->copyArray(
            $canonical,
            'secondary_link',
            $payload['secondary_link'] ?? null,
        );
        $this->copyNullableString($canonical, 'footer', $payload['footer'] ?? null);
        $this->copyNullableString(
            $canonical,
            'unsubscribe_url',
            $payload['unsubscribe_url'] ?? null,
        );
        $this->copyNullableString(
            $canonical,
            'transactional_opt_out_url',
            $payload['transactional_opt_out_url'] ?? null,
        );
        $this->copyNullableString(
            $canonical,
            'source_ip',
            $payload['source_ip']
                ?? $payload['request_ip']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copySmsPayload(array &$canonical, array $payload): void
    {
        $this->copyNullableString(
            $canonical,
            'message',
            $payload['message']
                ?? $payload['body']
                ?? $payload['message_body']
                ?? null,
        );
        $this->copyNullableString(
            $canonical,
            'source_ip',
            $payload['source_ip']
                ?? $payload['request_ip']
                ?? null,
        );

        $prefixBrand = data_get($payload, 'meta.prefix_brand');

        if (! is_bool($prefixBrand) && is_bool($payload['prefix_brand'] ?? null)) {
            $prefixBrand = $payload['prefix_brand'];
        }

        if (is_bool($prefixBrand)) {
            $canonical['meta'] = [
                'prefix_brand' => $prefixBrand,
            ];
        }
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copyInternalEmailPayload(
        array &$canonical,
        array $payload,
    ): void {
        $this->copyNullableString(
            $canonical,
            'notification_type',
            $payload['notification_type'] ?? null,
        );
        $this->copyNullableString($canonical, 'subject', $payload['subject'] ?? null);
        $this->copyNullableString($canonical, 'headline', $payload['headline'] ?? null);
        $this->copyNullableString($canonical, 'preheader', $payload['preheader'] ?? null);
        $this->copyStringList($canonical, 'body', $payload['body'] ?? null);
        $this->copyStringMap($canonical, 'details', $payload['details'] ?? null);
        $this->copyArray($canonical, 'cta', $payload['cta'] ?? null);
        $this->copyNullableString($canonical, 'footer', $payload['footer'] ?? null);
        $this->copyNullableString(
            $canonical,
            'source_ip',
            $payload['source_ip']
                ?? $payload['request_ip']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copyInternalSmsPayload(
        array &$canonical,
        array $payload,
    ): void {
        $this->copyNullableString(
            $canonical,
            'notification_type',
            $payload['notification_type'] ?? null,
        );
        $this->copyNullableString(
            $canonical,
            'message',
            $payload['sms_message']
                ?? $payload['message']
                ?? null,
        );
        $this->copyNullableString(
            $canonical,
            'source_ip',
            $payload['source_ip']
                ?? $payload['request_ip']
                ?? null,
        );
    }

    /**
     * @param array<string, mixed> $canonical
     * @param array<string, mixed> $payload
     */
    private function copyGenericPayload(array &$canonical, array $payload): void
    {
        foreach ([
            'subject',
            'body',
            'message',
            'view',
            'footer',
            'source_ip',
            'notification_type',
        ] as $key) {
            $this->copyNullableString($canonical, $key, $payload[$key] ?? null);
        }

        foreach (['cta', 'secondary_link'] as $key) {
            $this->copyArray($canonical, $key, $payload[$key] ?? null);
        }

        $this->copyList($canonical, 'ctas', $payload['ctas'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function importedTokenCandidates(array $payload): array
    {
        return array_replace_recursive(
            $this->rootTokenCandidates($payload),
            is_array($payload['runtime_context'] ?? null)
                ? $payload['runtime_context']
                : [],
            is_array($payload['context'] ?? null)
                ? $payload['context']
                : [],
            is_array($payload['tokens'] ?? null)
                ? $payload['tokens']
                : [],
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function rootTokenCandidates(array $payload): array
    {
        $reservedKeys = [
            'to',
            'email',
            'phone',
            'contact_email',
            'contact_phone',
            'contact_id',
            'recipient_type',
            'recipient_id',
            'channel',
            'purpose',
            'scope',
            'message_type',
            'payload_class',
            'subject',
            'body',
            'message',
            'message_body',
            'sms_message',
            'view',
            'cta',
            'ctas',
            'secondary_link',
            'footer',
            'unsubscribe_url',
            'transactional_opt_out_url',
            'source_ip',
            'request_ip',
            'notification_type',
            'headline',
            'preheader',
            'details',
            'prefix_brand',
            'meta',
            'runtime_context',
            'context',
            'tokens',
        ];

        return Arr::except($payload, $reservedKeys);
    }

    /**
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function projectReferencedTokens(
        array $tokens,
        array $payload,
        array $conditions,
    ): array {
        $projected = [];

        foreach (array_values(array_unique([
            ...$this->referencedTokenPaths($payload),
            ...$this->conditionTokenPaths($conditions),
        ])) as $tokenPath) {
            $sentinel = new \stdClass;
            $value = data_get($tokens, $tokenPath, $sentinel);

            if ($value === $sentinel) {
                continue;
            }

            $originalValue = $value;
            $value = $this->sanitizeValue($originalValue, 1);

            if ($value === null && $originalValue !== null) {
                continue;
            }

            data_set($projected, $tokenPath, $value);
        }

        return $projected;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function referencedTokenPaths(array $payload): array
    {
        $tokens = [];

        foreach (Arr::dot($payload) as $value) {
            if (! is_string($value) || $value === '') {
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

    /**
     * @param array<int|string, mixed> $conditions
     * @return array<int, string>
     */
    private function conditionTokenPaths(array $conditions): array
    {
        $paths = [];

        foreach ($conditions as $key => $condition) {
            if (
                is_array($condition)
                && is_string($condition['field'] ?? null)
                && trim($condition['field']) !== ''
            ) {
                $paths[] = trim($condition['field']);

                continue;
            }

            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $path = trim($key);

            foreach ([
                '_not_in',
                '_exists',
                '_missing',
                '_filled',
                '_blank',
                '_truthy',
                '_falsy',
                '_not',
                '_gte',
                '_lte',
                '_gt',
                '_lt',
                '_in',
            ] as $suffix) {
                if (str_ends_with($path, $suffix)) {
                    $path = substr($path, 0, -strlen($suffix));

                    break;
                }
            }

            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Scheduled message payload exceeds the maximum nesting depth.',
            );
        }

        $maximumItems = array_is_list($values)
            ? self::MAX_LIST_ITEMS
            : self::MAX_MAP_ITEMS;

        if (count($values) > $maximumItems) {
            throw new InvalidArgumentException(
                'Scheduled message payload contains too many array items.',
            );
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            if (! is_int($key) && (! is_string($key) || trim($key) === '')) {
                continue;
            }

            $originalValue = $value;
            $value = $this->sanitizeValue($value, $depth + 1);

            if ($value !== null || $originalValue === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(
                'Scheduled message payload exceeds the maximum nesting depth.',
            );
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            if (strlen($value) > self::MAX_STRING_BYTES) {
                throw new InvalidArgumentException(
                    'Scheduled message payload contains an oversized string.',
                );
            }

            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value, $depth);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertEncodedSize(array $payload): void
    {
        try {
            $encoded = json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                    | JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Scheduled message payload could not be encoded as JSON.',
                previous: $exception,
            );
        }

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new InvalidArgumentException(
                'Scheduled message payload exceeds the maximum encoded size.',
            );
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyNullableString(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $target[$key] = trim($value);
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyNullableInt(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (is_numeric($value)) {
            $target[$key] = (int) $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyArray(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (is_array($value) && $value !== []) {
            $target[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyList(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (is_array($value) && array_is_list($value) && $value !== []) {
            $target[$key] = $value;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyStringList(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (is_string($value) || $value instanceof Stringable) {
            $value = [(string) $value];
        }

        if (! is_array($value)) {
            return;
        }

        $items = array_values(array_filter(array_map(
            static fn (mixed $item): ?string => is_string($item)
                && trim($item) !== ''
                    ? trim($item)
                    : null,
            $value,
        )));

        if ($items !== []) {
            $target[$key] = $items;
        }
    }

    /**
     * @param array<string, mixed> $target
     */
    private function copyStringMap(
        array &$target,
        string $key,
        mixed $value,
    ): void {
        if (! is_array($value)) {
            return;
        }

        $items = [];

        foreach ($value as $itemKey => $itemValue) {
            if (
                ! is_string($itemKey)
                || trim($itemKey) === ''
                || ! is_string($itemValue)
            ) {
                continue;
            }

            $items[trim($itemKey)] = trim($itemValue);
        }

        if ($items !== []) {
            $target[$key] = $items;
        }
    }

    private function hasUsableValue(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return $value !== null;
    }
}