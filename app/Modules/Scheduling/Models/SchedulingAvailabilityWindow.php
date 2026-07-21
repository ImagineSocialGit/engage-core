<?php

namespace App\Modules\Scheduling\Models;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use Database\Factories\SchedulingAvailabilityWindowFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use InvalidArgumentException;

class SchedulingAvailabilityWindow extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_PROVIDER = 'provider';

    protected $attributes = [
        'window_type' => SchedulingAvailabilityWindowType::Weekly->value,
        'timezone' => 'UTC',
        'is_available' => true,
        'source' => self::SOURCE_MANUAL,
    ];

    protected $fillable = [
        'bookable_service_id',
        'scheduling_host_id',
        'window_type',
        'timezone',
        'weekday',
        'start_time',
        'end_time',
        'starts_at',
        'ends_at',
        'capacity',
        'is_available',
        'source',
        'meta',
    ];

    protected static function newFactory(): SchedulingAvailabilityWindowFactory
    {
        return SchedulingAvailabilityWindowFactory::new();
    }

    protected static function booted(): void
    {
        static::saving(function (self $window): void {
            $window->assertValidDefinition();
        });
    }

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'scheduling_host_id' => 'integer',
            'window_type' => SchedulingAvailabilityWindowType::class,
            'weekday' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'is_available' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function schedulingHost(): BelongsTo
    {
        return $this->belongsTo(SchedulingHost::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    public function scopeUnavailable(Builder $query): Builder
    {
        return $query->where('is_available', false);
    }

    public function scopeWeekly(Builder $query): Builder
    {
        return $query->where(
            'window_type',
            SchedulingAvailabilityWindowType::Weekly->value,
        );
    }

    public function scopeAbsolute(Builder $query): Builder
    {
        return $query->where(
            'window_type',
            SchedulingAvailabilityWindowType::Absolute->value,
        );
    }

    public function scopeForService(
        Builder $query,
        BookableService|int $service,
    ): Builder {
        return $query->where(
            'bookable_service_id',
            $service instanceof BookableService ? $service->getKey() : $service,
        );
    }

    public function scopeForHost(
        Builder $query,
        SchedulingHost|int $host,
    ): Builder {
        return $query->where(
            'scheduling_host_id',
            $host instanceof SchedulingHost ? $host->getKey() : $host,
        );
    }

    public function scopeServiceWide(Builder $query): Builder
    {
        return $query
            ->whereNotNull('bookable_service_id')
            ->whereNull('scheduling_host_id');
    }

    public function scopeHostWide(Builder $query): Builder
    {
        return $query
            ->whereNull('bookable_service_id')
            ->whereNotNull('scheduling_host_id');
    }

    public function scopeServiceHostSpecific(Builder $query): Builder
    {
        return $query
            ->whereNotNull('bookable_service_id')
            ->whereNotNull('scheduling_host_id');
    }

    private function assertValidDefinition(): void
    {
        if ($this->bookable_service_id === null && $this->scheduling_host_id === null) {
            throw new InvalidArgumentException(
                'Scheduling availability windows require a service, a host, or both.',
            );
        }

        $timezone = trim((string) $this->timezone);

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(
                "Scheduling availability timezone [{$timezone}] is invalid.",
            );
        }

        if ($this->capacity !== null && $this->capacity < 1) {
            throw new InvalidArgumentException(
                'Scheduling availability capacity must be at least 1 when provided.',
            );
        }

        match ($this->window_type) {
            SchedulingAvailabilityWindowType::Weekly => $this->assertValidWeeklyDefinition(),
            SchedulingAvailabilityWindowType::Absolute => $this->assertValidAbsoluteDefinition(),
        };
    }

    private function assertValidWeeklyDefinition(): void
    {
        if ($this->weekday === null || $this->weekday < 0 || $this->weekday > 6) {
            throw new InvalidArgumentException(
                'Weekly scheduling availability requires weekday 0 through 6.',
            );
        }

        $start = $this->timeInSeconds($this->start_time);
        $end = $this->timeInSeconds($this->end_time);

        if ($start === null || $end === null || $start >= $end) {
            throw new InvalidArgumentException(
                'Weekly scheduling availability requires a valid start_time before end_time.',
            );
        }

        if ($this->starts_at !== null || $this->ends_at !== null) {
            throw new InvalidArgumentException(
                'Weekly scheduling availability cannot include absolute starts_at or ends_at values.',
            );
        }
    }

    private function assertValidAbsoluteDefinition(): void
    {
        if (! $this->starts_at instanceof DateTimeInterface
            || ! $this->ends_at instanceof DateTimeInterface
            || $this->starts_at >= $this->ends_at
        ) {
            throw new InvalidArgumentException(
                'Absolute scheduling availability requires starts_at before ends_at.',
            );
        }

        if ($this->weekday !== null
            || $this->start_time !== null
            || $this->end_time !== null
        ) {
            throw new InvalidArgumentException(
                'Absolute scheduling availability cannot include weekly weekday or time values.',
            );
        }
    }

    private function timeInSeconds(mixed $value): ?int
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(?<hour>[01]\\d|2[0-3]):(?<minute>[0-5]\\d)(?::(?<second>[0-5]\\d))?$/', $value, $matches) !== 1) {
            return null;
        }

        return ((int) $matches['hour'] * 3600)
            + ((int) $matches['minute'] * 60)
            + (int) ($matches['second'] ?? 0);
    }
}