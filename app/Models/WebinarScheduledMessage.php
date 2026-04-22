<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarScheduledMessage extends Model
{
    protected $fillable = [
        'webinar_registration_id',
        'channel',
        'message_type',
        'status',
        'send_at',
        'sent_at',
        'skipped_at',
        'failed_at',
        'failure_reason',
        'meta',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
        'skipped_at' => 'datetime',
        'failed_at' => 'datetime',
        'meta' => 'array',
    ];

    public function registration(): BelongsTo
    {
        return $this->belongsTo(WebinarRegistration::class, 'webinar_registration_id');
    }
}