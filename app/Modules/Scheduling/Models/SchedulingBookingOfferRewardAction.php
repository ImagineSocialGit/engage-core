<?php

namespace App\Modules\Scheduling\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

class SchedulingBookingOfferRewardAction extends Model
{
    protected $fillable = [
        'scheduling_booking_offer_reward_id',
        'provider',
        'payload',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'scheduling_booking_offer_reward_id' => 'integer',
            'payload' => 'array',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $action): void {
            if (! is_string($action->provider) || trim($action->provider) === '') {
                throw new InvalidArgumentException(
                    'Scheduling booking offer reward actions require a provider.',
                );
            }

            if (! is_array($action->payload)) {
                throw new InvalidArgumentException(
                    'Scheduling booking offer reward action payloads must be arrays.',
                );
            }
        });
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(
            SchedulingBookingOfferReward::class,
            'scheduling_booking_offer_reward_id',
        );
    }
}