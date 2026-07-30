<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageRenderContext extends Model
{
    protected $fillable = [
        'scheduled_message_id',
        'values',
        'content_hash',
        'rendered_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'values' => 'array',
            'rendered_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }
}