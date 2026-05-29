<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledMessage extends Model
{
    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'context_type',
        'context_id',
        'channel',
        'message_type',
        'purpose',
        'payload_class',
        'payload',
        'send_at',
        'status',
        'sent_at',
        'skipped_at',
        'failed_at',
        'dedupe_key',
        'failure_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'context_id' => 'integer',
            'payload' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'skipped_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }
}