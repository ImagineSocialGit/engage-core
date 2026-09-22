<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ScheduledReportSubscription extends Model
{
    protected $table = 'reporting_scheduled_report_subscriptions';

    protected $fillable = [
        'uuid',
        'report_key',
        'name',
        'channel',
        'days_of_week',
        'send_time',
        'timezone',
        'parameters',
        'is_enabled',
        'last_sent_at',
        'next_send_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $subscription): void {
            if (! filled($subscription->uuid)) {
                $subscription->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'days_of_week' => 'array',
            'parameters' => 'array',
            'is_enabled' => 'boolean',
            'last_sent_at' => 'immutable_datetime',
            'next_send_at' => 'immutable_datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(
            ScheduledReportSubscriptionRecipient::class,
            'scheduled_report_subscription_id',
        );
    }
}