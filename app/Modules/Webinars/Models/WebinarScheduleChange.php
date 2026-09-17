<?php

namespace App\Modules\Webinars\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebinarScheduleChange extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DISPATCHING = 'dispatching';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'webinar_id', 'previous_starts_at', 'current_starts_at',
        'previous_timezone', 'current_timezone', 'status', 'channels',
        'last_registration_id', 'messages_queued', 'messages_cancelled', 'queued_at', 'completed_at',
        'notification_mode', 'template_version_ids',
    ];

    protected $casts = [
        'previous_starts_at' => 'datetime',
        'current_starts_at' => 'datetime',
        'channels' => 'array',
        'template_version_ids' => 'array',
        'last_registration_id' => 'integer',
        'messages_queued' => 'integer',
        'messages_cancelled' => 'integer',
        'queued_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function webinar(): BelongsTo
    {
        return $this->belongsTo(Webinar::class);
    }
}