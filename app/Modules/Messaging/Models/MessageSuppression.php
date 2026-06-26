<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MessageSuppression extends Model
{
    public const REASON_BOUNCE = 'bounce';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_MANUAL = 'manual';
    public const REASON_PROVIDER = 'provider';
    public const REASON_INVALID_DESTINATION = 'invalid_destination';
    public const REASON_REPEATED_FAILURE = 'repeated_failure';

    public const PROVIDER_TWILIO = 'twilio';
    public const PROVIDER_TELNYX = 'telnyx';
    public const PROVIDER_RESEND = 'resend';

    protected $fillable = [
        'channel',
        'destination',
        'reason',
        'provider',
        'source_event_id',
        'suppressed_at',
        'released_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
            'released_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at');
    }

    public function scopeReleased(Builder $query): Builder
    {
        return $query->whereNotNull('released_at');
    }

    public function scopeForDestination(Builder $query, string $channel, string $destination): Builder
    {
        return $query
            ->where('channel', $channel)
            ->where('destination', $destination);
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }
}