<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'scheduled_message_id',
        'event_type',
        'status',
        'available_at',
        'claim_token',
        'claim_expires_at',
        'attempts',
        'last_attempted_at',
        'published_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'available_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}