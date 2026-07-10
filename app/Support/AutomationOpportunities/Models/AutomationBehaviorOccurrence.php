<?php

namespace App\Support\AutomationOpportunities\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AutomationBehaviorOccurrence extends Model
{
    protected $fillable = [
        'action_key',
        'actor_type',
        'actor_id',
        'subject_type',
        'subject_id',
        'capability_key',
        'fingerprint',
        'fingerprint_parts',
        'context',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'actor_id' => 'integer',
        'subject_id' => 'integer',
        'fingerprint_parts' => 'array',
        'context' => 'array',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForAction(Builder $query, string $actionKey): Builder
    {
        return $query->where('action_key', $actionKey);
    }

    public function scopeForFingerprint(Builder $query, string $fingerprint): Builder
    {
        return $query->where('fingerprint', $fingerprint);
    }

    public function scopeForCapability(Builder $query, string $capabilityKey): Builder
    {
        return $query->where('capability_key', $capabilityKey);
    }

    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }
}
