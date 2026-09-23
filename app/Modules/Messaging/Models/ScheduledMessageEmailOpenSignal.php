<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageEmailOpenSignal extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scheduled_message_id',
        'delivery_attempt_id',
        'provider',
        'provider_message_id',
        'occurrence_count',
        'first_occurred_at',
        'last_occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'delivery_attempt_id' => 'integer',
            'occurrence_count' => 'integer',
            'first_occurred_at' => 'datetime',
            'last_occurred_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function deliveryAttempt(): BelongsTo
    {
        return $this->belongsTo(
            ScheduledMessageDeliveryAttempt::class,
            'delivery_attempt_id',
        );
    }
}