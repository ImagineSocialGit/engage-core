<?php

namespace App\Modules\InboundMessaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundMessageReceipt extends Model
{
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_RETRYABLE_FAILED = 'retryable_failed';

    protected $fillable = [
        'inbound_message_id',
        'client_key',
        'provider',
        'provider_event_id',
        'provider_message_id',
        'provider_event_key',
        'provider_message_key',
        'status',
        'attempts',
        'response_message',
        'last_error',
        'last_attempted_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'inbound_message_id' => 'integer',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }
}