<?php

namespace App\Modules\Scheduling\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class SchedulingResourceOccupancy extends Model
{
    protected $fillable = [
        'scheduling_resource_id',
        'scheduling_host_id',
        'appointment_id',
        'booking_hold_id',
        'quantity',
        'occupancy_starts_at',
        'occupancy_ends_at',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $occupancy): void {
            $occupancy->assertValidDefinition();
        });
    }

    protected function casts(): array
    {
        return [
            'scheduling_resource_id' => 'integer',
            'scheduling_host_id' => 'integer',
            'appointment_id' => 'integer',
            'booking_hold_id' => 'integer',
            'quantity' => 'integer',
            'occupancy_starts_at' => 'immutable_datetime',
            'occupancy_ends_at' => 'immutable_datetime',
        ];
    }

    public function schedulingResource(): BelongsTo
    {
        return $this->belongsTo(SchedulingResource::class);
    }

    public function schedulingHost(): BelongsTo
    {
        return $this->belongsTo(SchedulingHost::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function bookingHold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class);
    }

    private function assertValidDefinition(): void
    {
        $ownerCount = ($this->appointment_id !== null ? 1 : 0)
            + ($this->booking_hold_id !== null ? 1 : 0);

        if ($ownerCount !== 1) {
            throw new InvalidArgumentException(
                'A scheduling resource occupancy must belong to exactly one Appointment or BookingHold.',
            );
        }

        if ((int) $this->quantity < 1) {
            throw new InvalidArgumentException(
                'Scheduling resource occupancy quantity must be at least one.',
            );
        }

        if ($this->occupancy_starts_at === null || $this->occupancy_ends_at === null) {
            throw new InvalidArgumentException(
                'Scheduling resource occupancy requires start and end instants.',
            );
        }

        $startsAt = CarbonImmutable::instance($this->occupancy_starts_at)->utc();
        $endsAt = CarbonImmutable::instance($this->occupancy_ends_at)->utc();

        if (! $endsAt->greaterThan($startsAt)) {
            throw new InvalidArgumentException(
                'Scheduling resource occupancy must end after it starts.',
            );
        }
    }
}