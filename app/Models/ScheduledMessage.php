<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'context_type',
        'context_id',
        'channel',
        'message_type',
        'purpose',
        'scope',
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
            'contact_id' => 'integer',
            'context_id' => 'integer',
            'payload' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'skipped_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }
}