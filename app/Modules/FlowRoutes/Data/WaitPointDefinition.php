<?php

namespace App\Modules\FlowRoutes\Data;

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

        $until = self::stringValue($definition, $settings, 'resume_at')
            ?? self::stringValue($definition, $settings, 'wait_until')
            ?? self::stringValue($definition, $settings, 'until');

        if ($until !== null) {
            try {
                return new self(
                    resumeAt: CarbonImmutable::parse($until, $timezone)->utc(),
                    mode: 'until',
                    timezone: $timezone,
                    source: [
                        'field' => 'until',
                        'value' => $until,
                    ],
                );
            } catch (Throwable) {
                return new self(
                    resumeAt: null,
                    mode: 'until',
                    timezone: $timezone,
                    source: [
                        'field' => 'until',
                        'value' => $until,
                    ],
                    invalidReason: 'invalid_wait_until_datetime',
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
                invalidReason: 'missing_wait_duration_or_until',
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
        $duration = $definition['duration'] ?? $settings['duration'] ?? null;

        if (is_numeric($duration)) {
            return (int) $duration;
        }

        if (is_array($duration)) {
            $value = $duration['value'] ?? null;
            $unit = $duration['unit'] ?? 'seconds';

            if (! is_numeric($value) || ! is_string($unit)) {
                return null;
            }

            return self::secondsFor((int) $value, $unit);
        }

        $seconds = self::numericValue($definition, $settings, 'duration_seconds')
            ?? self::numericValue($definition, $settings, 'seconds');

        if ($seconds !== null) {
            return $seconds;
        }

        $minutes = self::numericValue($definition, $settings, 'duration_minutes')
            ?? self::numericValue($definition, $settings, 'minutes');

        if ($minutes !== null) {
            return $minutes * 60;
        }

        $hours = self::numericValue($definition, $settings, 'duration_hours')
            ?? self::numericValue($definition, $settings, 'hours');

        if ($hours !== null) {
            return $hours * 60 * 60;
        }

        $days = self::numericValue($definition, $settings, 'duration_days')
            ?? self::numericValue($definition, $settings, 'days');

        if ($days !== null) {
            return $days * 24 * 60 * 60;
        }

        $weeks = self::numericValue($definition, $settings, 'duration_weeks')
            ?? self::numericValue($definition, $settings, 'weeks');

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

    private static function secondsFor(int $value, string $unit): ?int
    {
        return match (strtolower(trim($unit))) {
            'second', 'seconds', 'sec', 'secs' => $value,
            'minute', 'minutes', 'min', 'mins' => $value * 60,
            'hour', 'hours', 'hr', 'hrs' => $value * 60 * 60,
            'day', 'days' => $value * 24 * 60 * 60,
            'week', 'weeks' => $value * 7 * 24 * 60 * 60,
            default => null,
        };
    }
}