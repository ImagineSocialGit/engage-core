<?php

namespace App\Modules\FlowRoutes\Data\Points;

class ChangeStatusPointDefinition
{
    /**
     * @param array<string, mixed> $meta
     * @param array<int, string> $fromContactStatusKeys
     */
    public function __construct(
        public readonly ?int $contactStatusId = null,
        public readonly ?string $contactStatusKey = null,
        public readonly array $fromContactStatusKeys = [],
        public readonly ?string $reason = null,
        public readonly bool $force = false,
        public readonly ?string $onSameStatus = null,
        public readonly ?string $invalidReason = null,
        public readonly array $meta = [],
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(array $definition, array $settings = []): self
    {
        $source = array_replace_recursive($definition, $settings);
        [$fromContactStatusKeys, $sourceStatusesAreInvalid] = self::stringList(
            source: $source,
            key: 'from_contact_status_keys',
        );

        $contactStatusId = self::int(
            source: $source,
            keys: [
                'contact_status_id',
                'status_id',
                'target_contact_status_id',
                'target_status_id',
            ],
        );

        $contactStatusKey = self::string(
            source: $source,
            keys: [
                'contact_status_key',
                'status_key',
                'target_contact_status_key',
                'target_status_key',
            ],
        );

        if ($contactStatusId === null && $contactStatusKey === null) {
            return new self(
                contactStatusId: null,
                contactStatusKey: null,
                fromContactStatusKeys: $fromContactStatusKeys,
                reason: self::string($source, ['reason']) ?? 'flow_route_change_status',
                force: self::bool($source, 'force'),
                onSameStatus: self::string($source, ['on_same_status']) ?? 'skipped',
                invalidReason: 'change_status_missing_target_status',
                meta: self::meta($source),
            );
        }

        return new self(
            contactStatusId: $contactStatusId,
            contactStatusKey: $contactStatusKey,
            fromContactStatusKeys: $fromContactStatusKeys,
            reason: self::string($source, ['reason']) ?? 'flow_route_change_status',
            force: self::bool($source, 'force'),
            onSameStatus: self::string($source, ['on_same_status']) ?? 'skipped',
            invalidReason: $sourceStatusesAreInvalid
                ? 'change_status_invalid_source_statuses'
                : null,
            meta: self::meta($source),
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null
            && ($this->contactStatusId !== null || $this->contactStatusKey !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'contact_status_id' => $this->contactStatusId,
            'contact_status_key' => $this->contactStatusKey,
            'from_contact_status_keys' => $this->fromContactStatusKeys,
            'reason' => $this->reason,
            'force' => $this->force,
            'on_same_status' => $this->onSameStatus,
            'meta' => $this->meta,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private static function int(array $source, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private static function string(array $source, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $source[$key] ?? null;

            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $source
     */
    private static function bool(array $source, string $key): bool
    {
        return (bool) ($source[$key] ?? false);
    }

    /**
     * @param array<string, mixed> $source
     * @return array{0: array<int, string>, 1: bool}
     */
    private static function stringList(array $source, string $key): array
    {
        if (! array_key_exists($key, $source)) {
            return [[], false];
        }

        $values = $source[$key];

        if (! is_array($values) || ! array_is_list($values)) {
            return [[], true];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                return [[], true];
            }

            $normalized[] = trim($value);
        }

        return [array_values(array_unique($normalized)), false];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private static function meta(array $source): array
    {
        $meta = $source['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}