<?php

namespace App\Support\ConfigContracts\Data;

use InvalidArgumentException;

final class ConfigContractTargetContext
{
    public const MODE_CURRENT = 'current';
    public const MODE_PROPOSED = 'proposed';

    private const MODES = [
        self::MODE_CURRENT,
        self::MODE_PROPOSED,
    ];

    /**
     * @param array<string, mixed>|null $config
     * @param array<int, string>|null $enabledModules
     */
    private function __construct(
        public readonly string $mode,
        private readonly ?array $config = null,
        public readonly ?string $presetKey = null,
        public readonly ?array $enabledModules = null,
    ) {
        if (! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException("Unsupported config contract target context mode [{$mode}].");
        }
    }

    public static function current(?string $presetKey = null): self
    {
        return new self(
            mode: self::MODE_CURRENT,
            presetKey: self::normalizeNullableString($presetKey),
        );
    }

    /**
     * @param array<string, mixed> $config
     * @param array<int, string>|null $enabledModules
     */
    public static function proposed(
        array $config,
        ?string $presetKey = null,
        ?array $enabledModules = null,
    ): self {
        return new self(
            mode: self::MODE_PROPOSED,
            config: $config,
            presetKey: self::normalizeNullableString($presetKey),
            enabledModules: $enabledModules,
        );
    }

    public function isCurrent(): bool
    {
        return $this->mode === self::MODE_CURRENT;
    }

    public function isProposed(): bool
    {
        return $this->mode === self::MODE_PROPOSED;
    }

    public function config(string $key, mixed $default = null): mixed
    {
        if ($this->isCurrent()) {
            return config($key, $default);
        }

        return data_get($this->config ?? [], $key, $default);
    }

    private static function normalizeNullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
