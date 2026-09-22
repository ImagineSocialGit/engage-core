<?php

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledReportSubscriptionRecipient extends Model
{
    protected $table = 'reporting_scheduled_report_recipients';

    protected $fillable = [
        'scheduled_report_subscription_id',
        'recipient_type',
        'recipient_id',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_report_subscription_id' => 'integer',
            'recipient_id' => 'integer',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            ScheduledReportSubscription::class,
            'scheduled_report_subscription_id',
        );
    }
}