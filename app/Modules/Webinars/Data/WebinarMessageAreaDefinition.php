<?php

namespace App\Modules\Webinars\Data;

use InvalidArgumentException;

final readonly class WebinarMessageAreaDefinition
{
    public const KIND_TEMPLATE = 'template';
    public const KIND_CONSENT_ACKNOWLEDGEMENT = 'consent_acknowledgement';

    /**
     * @param array<int, string> $usageTypes
     * @param array<int, string> $profileContextKeys
     * @param array<string, mixed>|null $consolidationPrimary
     */
    public function __construct(
        public string $key,
        public bool $enabled,
        public bool $disableable,
        public string $kind,
        public string $label,
        public string $description,
        public string $purpose,
        public string $scope,
        public string $surface,
        public string $messageType,
        public string $dispatchKey,
        public bool|string $required,
        public array $usageTypes,
        public array $profileContextKeys,
        public bool $managedByMessaging,
        public ?string $consolidationPolicy,
        public ?array $consolidationPrimary,
        public int $sortOrder,
    ) {}

    /**
     * @param array<string, mixed> $config
     */
    public static function fromConfig(string $key, array $config): self
    {
        $key = self::normalizeSegment($key);
        $kind = self::normalizeSegment((string) ($config['kind'] ?? self::KIND_TEMPLATE));

        if (! in_array($kind, [self::KIND_TEMPLATE, self::KIND_CONSENT_ACKNOWLEDGEMENT], true)) {
            throw new InvalidArgumentException(
                "Webinar message area [{$key}] has unsupported kind [{$kind}]."
            );
        }

        $enabled = (bool) ($config['enabled'] ?? true);
        $disableable = $kind === self::KIND_TEMPLATE
            ? (bool) ($config['disableable'] ?? true)
            : false;
        $managedByMessaging = (bool) ($config['managed_by_messaging'] ?? ($kind === self::KIND_CONSENT_ACKNOWLEDGEMENT));

        if (
            $kind === self::KIND_CONSENT_ACKNOWLEDGEMENT
            && array_key_exists('disableable', $config)
            && $config['disableable'] !== false
        ) {
            throw new InvalidArgumentException(
                "Webinar message area [{$key}] is consent-driven and must remain non-disableable."
            );
        }

        if ($kind === self::KIND_CONSENT_ACKNOWLEDGEMENT && ! $managedByMessaging) {
            throw new InvalidArgumentException(
                "Webinar message area [{$key}] is a consent acknowledgement and must remain Messaging-managed."
            );
        }

        if (! $enabled && ! $disableable) {
            throw new InvalidArgumentException(
                "Webinar message area [{$key}] is consent-driven and cannot be disabled directly. Disable its consent surface or channel instead."
            );
        }

        $required = $config['required'] ?? false;

        if (! is_bool($required) && (! is_string($required) || trim($required) === '')) {
            throw new InvalidArgumentException(
                "Webinar message area [{$key}] has invalid [required] semantics."
            );
        }

        $profileContextKeys = self::normalizeList($config['profile_context_keys'] ?? [$key]);

        if (! in_array($key, $profileContextKeys, true)) {
            $profileContextKeys[] = $key;
        }

        $consolidationPrimary = is_array($config['consolidation_primary'] ?? null)
            ? $config['consolidation_primary']
            : null;

        return new self(
            key: $key,
            enabled: $enabled,
            disableable: $disableable,
            kind: $kind,
            label: self::requiredString($config, 'label', $key),
            description: self::requiredString($config, 'description', $key),
            purpose: self::normalizeSegment(self::requiredString($config, 'purpose', $key)),
            scope: self::normalizeSegment(self::requiredString($config, 'scope', $key)),
            surface: self::normalizeSegment(self::requiredString($config, 'surface', $key)),
            messageType: self::normalizeSegment(self::requiredString($config, 'message_type', $key)),
            dispatchKey: self::normalizeSegment(self::requiredString($config, 'dispatch_key', $key)),
            required: is_string($required) ? self::normalizeSegment($required) : $required,
            usageTypes: self::normalizeList($config['usage_types'] ?? []),
            profileContextKeys: array_values(array_unique($profileContextKeys)),
            managedByMessaging: $managedByMessaging,
            consolidationPolicy: self::nullableSegment($config['consolidation_policy'] ?? null),
            consolidationPrimary: $consolidationPrimary,
            sortOrder: is_numeric($config['sort_order'] ?? null) ? (int) $config['sort_order'] : 0,
        );
    }

    public function isTemplate(): bool
    {
        return $this->kind === self::KIND_TEMPLATE;
    }

    public function isConsentAcknowledgement(): bool
    {
        return $this->kind === self::KIND_CONSENT_ACKNOWLEDGEMENT;
    }

    public function matchesProfileContext(?string $contextKey): bool
    {
        if (! is_string($contextKey) || trim($contextKey) === '') {
            return true;
        }

        return in_array(self::normalizeSegment($contextKey), $this->profileContextKeys, true);
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function matchesDefinition(
        array $definition,
        ?string $surface = null,
        ?string $profileContextKey = null,
    ): bool {
        if (! $this->isTemplate()) {
            return false;
        }

        $hasProfileContext = is_string($profileContextKey) && trim($profileContextKey) !== '';

        if ($hasProfileContext && ! $this->matchesProfileContext($profileContextKey)) {
            return false;
        }

        if ($surface !== null && self::normalizeSegment($surface) !== $this->surface) {
            return false;
        }

        foreach ([
            'purpose' => $this->purpose,
            'scope' => $this->scope,
        ] as $field => $expected) {
            if (self::normalizeSegment((string) ($definition[$field] ?? '')) !== $expected) {
                return false;
            }
        }

        if (
            ! $hasProfileContext
            && self::normalizeSegment((string) ($definition['message_type'] ?? '')) !== $this->messageType
        ) {
            return false;
        }

        $dispatchKeys = self::normalizeList($definition['dispatch_keys'] ?? []);

        return in_array($this->dispatchKey, $dispatchKeys, true);
    }

    /**
     * @param array<string, mixed> $item
     */
    public function matchesScheduleItem(array $item): bool
    {
        if (! $this->isTemplate()) {
            return false;
        }

        $contextKey = is_string($item['context_key'] ?? null) && trim((string) $item['context_key']) !== ''
            ? self::normalizeSegment((string) $item['context_key'])
            : null;
        $matchesByContext = $contextKey !== null && $this->matchesProfileContext($contextKey);
        $matchesByMessageType = self::normalizeSegment((string) ($item['message_type'] ?? '')) === $this->messageType;

        if (! $matchesByContext && ! $matchesByMessageType) {
            return false;
        }

        foreach ([
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'surface' => $this->surface,
            'dispatch_key' => $this->dispatchKey,
        ] as $field => $expected) {
            if (self::normalizeSegment((string) ($item[$field] ?? '')) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'enabled' => $this->enabled,
            'disableable' => $this->disableable,
            'kind' => $this->kind,
            'label' => $this->label,
            'description' => $this->description,
            'purpose' => $this->purpose,
            'scope' => $this->scope,
            'surface' => $this->surface,
            'message_type' => $this->messageType,
            'dispatch_key' => $this->dispatchKey,
            'required' => $this->required,
            'usage_types' => $this->usageTypes,
            'profile_context_keys' => $this->profileContextKeys,
            'managed_by_messaging' => $this->managedByMessaging,
            'consolidation_policy' => $this->consolidationPolicy,
            'consolidation_primary' => $this->consolidationPrimary,
            'sort_order' => $this->sortOrder,
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requiredString(array $config, string $field, string $areaKey): string
    {
        $value = $config[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(
                "Webinar message area [{$areaKey}] requires non-empty [{$field}]."
            );
        }

        return trim($value);
    }

    private static function nullableSegment(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? self::normalizeSegment($value)
            : null;
    }

    /**
     * @return array<int, string>
     */
    private static function normalizeList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? self::normalizeSegment($value)
                : null,
            $values,
        ))));
    }

    private static function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
