<?php

namespace App\Modules\Scheduling\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use InvalidArgumentException;

class BookingHold extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_RELEASED = 'released';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_CONVERTED,
        self::STATUS_RELEASED,
        self::STATUS_EXPIRED,
    ];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'hold_id',
        'bookable_slot_offer_id',
        'bookable_service_id',
        'scheduling_host_id',
        'appointment_id',
        'idempotency_key',
        'status',
        'starts_at',
        'ends_at',
        'occupancy_starts_at',
        'occupancy_ends_at',
        'capacity',
        'held_at',
        'expires_at',
        'released_at',
        'converted_at',
        'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $hold): void {
            if (! is_string($hold->hold_id) || trim($hold->hold_id) === '') {
                $hold->hold_id = (string) Str::uuid();
            } else {
                $hold->hold_id = trim($hold->hold_id);
            }

            $hold->held_at ??= now();
        });

        static::saving(function (self $hold): void {
            $hold->assertValidDefinition();
        });
    }

    protected function casts(): array
    {
        return [
            'bookable_slot_offer_id' => 'integer',
            'bookable_service_id' => 'integer',
            'scheduling_host_id' => 'integer',
            'appointment_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'occupancy_starts_at' => 'immutable_datetime',
            'occupancy_ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'held_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'converted_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }

    public function bookableSlotOffer(): BelongsTo
    {
        return $this->belongsTo(BookableSlotOffer::class);
    }

    public function bookableService(): BelongsTo
    {
        return $this->belongsTo(BookableService::class);
    }

    public function schedulingHost(): BelongsTo
    {
        return $this->belongsTo(SchedulingHost::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function scopeEffectivelyActive(
        Builder $query,
        ?CarbonInterface $at = null,
    ): Builder {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', $at);
    }

    public function scopeDueForExpiration(
        Builder $query,
        ?CarbonInterface $at = null,
    ): Builder {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '<=', $at);
    }

    public function scopeOverlappingOccupancy(
        Builder $query,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Builder {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();

        return $query
            ->where('occupancy_starts_at', '<', $endsAt)
            ->where('occupancy_ends_at', '>', $startsAt);
    }

    public function isEffectivelyActive(?CarbonInterface $at = null): bool
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $this->status === self::STATUS_ACTIVE
            && $this->expires_at !== null
            && CarbonImmutable::instance($this->expires_at)->utc()->greaterThan($at);
    }

    public function remainingSeconds(?CarbonInterface $at = null): int
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        if (! $this->isEffectivelyActive($at)) {
            return 0;
        }

        return max(
            0,
            CarbonImmutable::instance($this->expires_at)->utc()->getTimestamp()
                - $at->getTimestamp(),
        );
    }

    private function assertValidDefinition(): void
    {
        if (! in_array($this->status, self::STATUSES, true)) {
            throw new InvalidArgumentException(
                "Unsupported booking hold status [{$this->status}].",
            );
        }

        foreach ([
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'occupancy_starts_at' => $this->occupancy_starts_at,
            'occupancy_ends_at' => $this->occupancy_ends_at,
            'held_at' => $this->held_at,
            'expires_at' => $this->expires_at,
        ] as $field => $value) {
            if ($value === null) {
                throw new InvalidArgumentException(
                    "Booking holds require [{$field}].",
                );
            }
        }

        if ($this->starts_at->greaterThanOrEqualTo($this->ends_at)) {
            throw new InvalidArgumentException(
                'Booking holds require starts_at before ends_at.',
            );
        }

        if ($this->occupancy_starts_at->greaterThan($this->starts_at)
            || $this->occupancy_ends_at->lessThan($this->ends_at)
            || $this->occupancy_starts_at->greaterThanOrEqualTo($this->occupancy_ends_at)
        ) {
            throw new InvalidArgumentException(
                'Booking hold occupancy must contain the complete slot interval.',
            );
        }

        if ($this->held_at->greaterThanOrEqualTo($this->expires_at)) {
            throw new InvalidArgumentException(
                'Booking holds require held_at before expires_at.',
            );
        }

        if ((int) $this->capacity < 1) {
            throw new InvalidArgumentException(
                'Booking hold capacity must be at least 1.',
            );
        }
    }
}