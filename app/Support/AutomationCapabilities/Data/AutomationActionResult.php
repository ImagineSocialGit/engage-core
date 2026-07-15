<?php

namespace App\Support\AutomationCapabilities\Data;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AutomationActionResult
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_BLOCKED,
        self::STATUS_FAILED,
    ];

    /**
     * @param array<int, Model> $artifacts
     * @param array<string, mixed> $correlation
     * @param array<string, mixed> $output
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $artifacts = [],
        public readonly ?string $correlationKey = null,
        public readonly ?string $correlationType = null,
        public readonly array $correlation = [],
        public readonly array $output = [],
        public readonly array $meta = [],
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unsupported automation action result status [{$status}].");
        }

        foreach ($artifacts as $artifact) {
            if (! $artifact instanceof Model) {
                throw new InvalidArgumentException('Automation action result artifacts must be Eloquent models.');
            }
        }
    }

    public function primaryArtifact(): ?Model
    {
        $artifact = $this->artifacts[0] ?? null;

        return $artifact instanceof Model ? $artifact : null;
    }

    /** @param array<int, Model> $artifacts @param array<string, mixed> $correlation @param array<string, mixed> $output @param array<string, mixed> $meta */
    public static function completed(
        ?string $reason = null,
        array $artifacts = [],
        ?string $correlationKey = null,
        ?string $correlationType = null,
        array $correlation = [],
        array $output = [],
        array $meta = [],
    ): self {
        return new self(self::STATUS_COMPLETED, $reason, $artifacts, $correlationKey, $correlationType, $correlation, $output, $meta);
    }

    /** @param array<string, mixed> $output @param array<string, mixed> $meta */
    public static function skipped(?string $reason = null, array $output = [], array $meta = []): self
    {
        return new self(self::STATUS_SKIPPED, $reason, output: $output, meta: $meta);
    }

    /** @param array<string, mixed> $output @param array<string, mixed> $meta */
    public static function blocked(?string $reason = null, array $output = [], array $meta = []): self
    {
        return new self(self::STATUS_BLOCKED, $reason, output: $output, meta: $meta);
    }

    /** @param array<string, mixed> $output @param array<string, mixed> $meta */
    public static function failed(?string $reason = null, array $output = [], array $meta = []): self
    {
        return new self(self::STATUS_FAILED, $reason, output: $output, meta: $meta);
    }
}
