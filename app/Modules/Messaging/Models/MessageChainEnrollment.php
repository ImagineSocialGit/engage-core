<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MessageChainEnrollment extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_EXITED = 'exited';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const TESTING_SURFACE_PREFIX = 'testing:';

    protected $fillable = [
        'message_chain_version_id',
        'recipient_type',
        'recipient_id',
        'context_type',
        'context_id',
        'origin_type',
        'origin_id',
        'surface',
        'current_message_chain_step_id',
        'next_action_at',
        'status',
        'dedupe_key',
        'started_at',
        'paused_at',
        'resumed_at',
        'exited_at',
        'exit_reason_code',
        'completed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_version_id' => 'integer',
            'recipient_id' => 'integer',
            'context_id' => 'integer',
            'origin_id' => 'integer',
            'current_message_chain_step_id' => 'integer',
            'next_action_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'exited_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function messageChainVersion(): BelongsTo
    {
        return $this->belongsTo(MessageChainVersion::class);
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    public function currentMessageChainStep(): BelongsTo
    {
        return $this->belongsTo(
            MessageChainStep::class,
            'current_message_chain_step_id',
        );
    }

    public function scheduledMessages(): HasMany
    {
        return $this->hasMany(ScheduledMessage::class)
            ->orderBy('id');
    }

    public function latestScheduledMessage(): HasOne
    {
        return $this->hasOne(ScheduledMessage::class)->ofMany('id', 'max');
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<=', now())
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('surface')
                    ->orWhere('surface', 'not like', self::TESTING_SURFACE_PREFIX.'%');
            });
    }

    public function isTestingSurface(): bool
    {
        return is_string($this->surface)
            && str_starts_with($this->surface, self::TESTING_SURFACE_PREFIX);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_EXITED,
            self::STATUS_COMPLETED,
            self::STATUS_CANCELLED,
        ], true);
    }
}