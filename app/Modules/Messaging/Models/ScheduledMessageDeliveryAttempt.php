<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledMessageDeliveryAttempt extends Model
{
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_SUBMITTING = 'submitting';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';
    public const STATUS_RELEASED = 'released';
    public const STATUS_RECOVERED = 'recovered';

    protected $fillable = [
        'scheduled_message_id',
        'attempt_number',
        'claim_token',
        'status',
        'claimed_at',
        'lease_expires_at',
        'provider_submission_started_at',
        'completed_at',
        'destination',
        'provider',
        'provider_message_id',
        'reason_code',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_message_id' => 'integer',
            'attempt_number' => 'integer',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
            'provider_submission_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function scheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                self::STATUS_CLAIMED,
                self::STATUS_SUBMITTING,
            ])
            ->whereNull('completed_at');
    }

    public function isActive(): bool
    {
        return $this->completed_at === null
            && in_array($this->status, [
                self::STATUS_CLAIMED,
                self::STATUS_SUBMITTING,
            ], true);
    }
}