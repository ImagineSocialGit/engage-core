<?php

namespace App\Modules\FlowRoutes\Data\Points;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

class WaitPointDefinition
{
    /**
     * @param array<string, mixed> $source
     */
    public function __construct(
        public readonly ?CarbonImmutable $resumeAt,
        public readonly string $mode,
        public readonly string $timezone,
        public readonly array $source = [],
        public readonly ?string $invalidReason = null,
    ) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    public static function from(
        array $definition,
        array $settings = [],
        ?CarbonInterface $now = null,
    ): self {
        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        $timezone = self::stringValue($definition, $settings, 'timezone')
            ?? (string) config('app.timezone', 'UTC');

        $resumeAt = self::stringValue($definition, $settings, 'resume_at');

        if ($resumeAt !== null) {
            try {
                return new self(
                    resumeAt: CarbonImmutable::parse($resumeAt, $timezone)->utc(),
                    mode: 'resume_at',
                    timezone: $timezone,
                    source: [
                        'field' => 'resume_at',
                        'value' => $resumeAt,
                    ],
                );
            } catch (Throwable) {
                return new self(
                    resumeAt: null,
                    mode: 'resume_at',
                    timezone: $timezone,
                    source: [
                        'field' => 'resume_at',
                        'value' => $resumeAt,
                    ],
                    invalidReason: 'invalid_wait_resume_at_datetime',
                );
            }
        }

        $duration = self::durationSeconds($definition, $settings);

        if ($duration === null) {
            return new self(
                resumeAt: null,
                mode: 'duration',
                timezone: $timezone,
                source: [],
                invalidReason: 'missing_wait_duration_or_resume_at',
            );
        }

        if ($duration < 0) {
            return new self(
                resumeAt: null,
                mode: 'duration',
                timezone: $timezone,
                source: [
                    'seconds' => $duration,
                ],
                invalidReason: 'wait_duration_cannot_be_negative',
            );
        }

        return new self(
            resumeAt: $now->addSeconds($duration),
            mode: 'duration',
            timezone: $timezone,
            source: [
                'seconds' => $duration,
            ],
        );
    }

    public function isValid(): bool
    {
        return $this->invalidReason === null && $this->resumeAt instanceof CarbonImmutable;
    }

    public function isImmediate(?CarbonInterface $now = null): bool
    {
        if (! $this->resumeAt instanceof CarbonImmutable) {
            return false;
        }

        $now = $now
            ? CarbonImmutable::instance($now)->utc()
            : CarbonImmutable::now('UTC');

        return $this->resumeAt->lessThanOrEqualTo($now);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetaPayload(): array
    {
        return [
            'mode' => $this->mode,
            'timezone' => $this->timezone,
            'resume_at' => $this->resumeAt?->toISOString(),
            'source' => $this->source,
            'invalid_reason' => $this->invalidReason,
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    private static function stringValue(array $definition, array $settings, string $key): ?string
    {
        $value = $definition[$key] ?? $settings[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    private static function durationSeconds(array $definition, array $settings): ?int
    {
        $seconds = self::numericValue($definition, $settings, 'seconds');

        if ($seconds !== null) {
            return $seconds;
        }

        $minutes = self::numericValue($definition, $settings, 'minutes');

        if ($minutes !== null) {
            return $minutes * 60;
        }

        $hours = self::numericValue($definition, $settings, 'hours');

        if ($hours !== null) {
            return $hours * 60 * 60;
        }

        $days = self::numericValue($definition, $settings, 'days');

        if ($days !== null) {
            return $days * 24 * 60 * 60;
        }

        $weeks = self::numericValue($definition, $settings, 'weeks');

        return $weeks !== null ? $weeks * 7 * 24 * 60 * 60 : null;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $settings
     */
    private static function numericValue(array $definition, array $settings, string $key): ?int
    {
        $value = $definition[$key] ?? $settings[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }
}