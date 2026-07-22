<?php

namespace App\Modules\Scheduling\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class BookableSlotOffer extends Model
{
    protected $fillable = [
        'offer_id',
        'bookable_service_id',
        'scheduling_host_id',
        'reschedule_appointment_id',
        'starts_at',
        'ends_at',
        'display_timezone',
        'capacity',
        'remaining_capacity',
        'source_scopes',
        'source_window_ids',
        'issued_at',
        'expires_at',
        'consumed_at',
        'meta',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $offer): void {
            if (! is_string($offer->offer_id) || trim($offer->offer_id) === '') {
                $offer->offer_id = (string) Str::uuid();
            } else {
                $offer->offer_id = trim($offer->offer_id);
            }

            $offer->issued_at ??= now();
        });
    }

    protected function casts(): array
    {
        return [
            'bookable_service_id' => 'integer',
            'scheduling_host_id' => 'integer',
            'reschedule_appointment_id' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'capacity' => 'integer',
            'remaining_capacity' => 'integer',
            'source_scopes' => 'array',
            'source_window_ids' => 'array',
            'issued_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'consumed_at' => 'immutable_datetime',
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

    public function rescheduleAppointment(): BelongsTo
    {
        return $this->belongsTo(
            Appointment::class,
            'reschedule_appointment_id',
        );
    }

    public function bookingHold(): HasOne
    {
        return $this->hasOne(BookingHold::class);
    }

    public function scopeActive(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', $at);
    }

    public function scopeExpired(Builder $query, ?CarbonInterface $at = null): Builder
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $query->where('expires_at', '<=', $at);
    }

    public function scopeConsumed(Builder $query): Builder
    {
        return $query->whereNotNull('consumed_at');
    }

    public function isRescheduleOffer(): bool
    {
        return $this->reschedule_appointment_id !== null;
    }

    public function isForRescheduleOf(Appointment $appointment): bool
    {
        return $appointment->exists
            && $appointment->getKey() !== null
            && $this->reschedule_appointment_id !== null
            && (int) $this->reschedule_appointment_id === (int) $appointment->getKey();
    }

    public function isActiveAt(?CarbonInterface $at = null): bool
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        return $this->consumed_at === null
            && $this->expires_at !== null
            && CarbonImmutable::instance($this->expires_at)->utc()->greaterThan($at);
    }

    public function remainingSeconds(?CarbonInterface $at = null): int
    {
        $at = $at !== null
            ? CarbonImmutable::instance($at)->utc()
            : CarbonImmutable::now('UTC');

        if ($this->expires_at === null) {
            return 0;
        }

        return max(
            0,
            CarbonImmutable::instance($this->expires_at)->utc()->getTimestamp()
                - $at->getTimestamp(),
        );
    }
}