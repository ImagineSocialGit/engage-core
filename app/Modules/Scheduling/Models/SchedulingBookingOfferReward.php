<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class SchedulingBookingOfferReward extends Model
{
    protected $fillable = [
        'scheduling_booking_offer_id',
        'name',
        'max_claim_number',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scheduling_booking_offer_id' => 'integer',
            'max_claim_number' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $reward): void {
            if (! is_string($reward->name) || trim($reward->name) === '') {
                throw new InvalidArgumentException(
                    'Scheduling booking offer rewards require a name.',
                );
            }

            if ((int) $reward->max_claim_number < 1) {
                throw new InvalidArgumentException(
                    'Scheduling booking offer reward thresholds must be at least 1.',
                );
            }
        });
    }

    public function bookingOffer(): BelongsTo
    {
        return $this->belongsTo(
            SchedulingBookingOffer::class,
            'scheduling_booking_offer_id',
        );
    }

    public function actions(): HasMany
    {
        return $this->hasMany(SchedulingBookingOfferRewardAction::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}