<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class SchedulingBookingOfferCondition extends Model
{
    protected $fillable = [
        'scheduling_booking_offer_id',
        'provider',
        'criteria',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scheduling_booking_offer_id' => 'integer',
            'criteria' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $condition): void {
            if (! is_string($condition->provider) || trim($condition->provider) === '') {
                throw new InvalidArgumentException(
                    'Scheduling booking offer conditions require a provider.',
                );
            }

            if (! is_array($condition->criteria)) {
                throw new InvalidArgumentException(
                    'Scheduling booking offer condition criteria must be an array.',
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
}