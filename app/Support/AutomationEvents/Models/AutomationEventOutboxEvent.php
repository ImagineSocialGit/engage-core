<?php

namespace App\Support\AutomationEvents\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AutomationEventOutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'event_id',
        'idempotency_key',
        'event_key',
        'contact_id',
        'subject_type',
        'subject_id',
        'occurred_at',
        'payload',
        'meta',
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
            'contact_id' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'payload' => 'array',
            'meta' => 'array',
            'available_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function scopeReadyToPublish(Builder $query): Builder
    {
        $now = now();

        return $query->where(function (Builder $ready) use ($now): void {
            $ready
                ->where(function (Builder $pending) use ($now): void {
                    $pending
                        ->where('status', self::STATUS_PENDING)
                        ->where('available_at', '<=', $now);
                })
                ->orWhere(function (Builder $processing) use ($now): void {
                    $processing
                        ->where('status', self::STATUS_PROCESSING)
                        ->where('claim_expires_at', '<=', $now);
                });
        });
    }
}