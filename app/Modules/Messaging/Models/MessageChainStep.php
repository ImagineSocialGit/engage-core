<?php

namespace App\Modules\Messaging\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MessageChainStep extends Model
{
    public const TIMING_IMMEDIATE = 'immediate';
    public const TIMING_DELAY = 'delay';
    public const TIMING_ANCHORED = 'anchored';
    public const TIMING_NEXT_DAY_AT = 'next_day_at';

    public const VARIANT_STRATEGY_FIRST_AVAILABLE = 'first_available';
    public const VARIANT_STRATEGY_SEND_ALL_ELIGIBLE = 'send_all_eligible';
    public const VARIANT_STRATEGY_DEPENDENCY_AWARE = 'dependency_aware';

    public const ADVANCE_ALL_TERMINAL = 'all_terminal';
    public const ADVANCE_FIRST_SENT = 'first_sent';
    public const ADVANCE_FIRST_TERMINAL = 'first_terminal';

    protected $fillable = [
        'message_chain_version_id',
        'key',
        'name',
        'sort_order',
        'timing_type',
        'anchor_key',
        'offset_seconds',
        'day_offset',
        'local_time',
        'variant_strategy',
        'advance_policy',
        'conditions',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'message_chain_version_id' => 'integer',
            'sort_order' => 'integer',
            'offset_seconds' => 'integer',
            'day_offset' => 'integer',
            'conditions' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function messageChainVersion(): BelongsTo
    {
        return $this->belongsTo(MessageChainVersion::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MessageChainStepVariant::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function currentEnrollments(): HasMany
    {
        return $this->hasMany(
            MessageChainEnrollment::class,
            'current_message_chain_step_id',
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}