<?php

namespace App\Modules\FlowRoutes\Data\Points;

class ChangeStatusPointDefinition
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly ?int $contactStatusId = null,
        public readonly ?string $contactStatusKey = null,
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
            reason: self::string($source, ['reason']) ?? 'flow_route_change_status',
            force: self::bool($source, 'force'),
            onSameStatus: self::string($source, ['on_same_status']) ?? 'skipped',
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
     * @return array<string, mixed>
     */
    private static function meta(array $source): array
    {
        $meta = $source['meta'] ?? [];

        return is_array($meta) ? $meta : [];
    }
}