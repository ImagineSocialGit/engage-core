<?php

namespace App\Support\AutomationOpportunities\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AutomationOpportunity extends Model
{
    public const STATUS_OBSERVING = 'observing';
    public const STATUS_ELIGIBLE = 'eligible';
    public const STATUS_SUGGESTED = 'suggested';
    public const STATUS_DISMISSED = 'dismissed';
    public const STATUS_CONVERTED = 'converted';
    public const STATUS_INVALIDATED = 'invalidated';

    public const STATUSES = [
        self::STATUS_OBSERVING,
        self::STATUS_ELIGIBLE,
        self::STATUS_SUGGESTED,
        self::STATUS_DISMISSED,
        self::STATUS_CONVERTED,
        self::STATUS_INVALIDATED,
    ];

    protected $fillable = [
        'action_key',
        'fingerprint',
        'capability_key',
        'status',
        'occurrence_count',
        'distinct_subject_count',
        'distinct_actor_count',
        'first_occurred_at',
        'last_occurred_at',
        'eligible_at',
        'suggested_at',
        'dismissed_at',
        'dismissed_until',
        'converted_at',
        'invalidated_at',
        'context',
        'meta',
    ];

    protected $casts = [
        'occurrence_count' => 'integer',
        'distinct_subject_count' => 'integer',
        'distinct_actor_count' => 'integer',
        'first_occurred_at' => 'datetime',
        'last_occurred_at' => 'datetime',
        'eligible_at' => 'datetime',
        'suggested_at' => 'datetime',
        'dismissed_at' => 'datetime',
        'dismissed_until' => 'datetime',
        'converted_at' => 'datetime',
        'invalidated_at' => 'datetime',
        'context' => 'array',
        'meta' => 'array',
    ];

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

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeEligible(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ELIGIBLE)
            ->whereNotNull('eligible_at');
    }

    public function scopeSuggestionAvailable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [
                self::STATUS_ELIGIBLE,
                self::STATUS_SUGGESTED,
            ])
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('dismissed_until')
                    ->orWhere('dismissed_until', '<=', now());
            });
    }

    public function isDismissed(): bool
    {
        return $this->status === self::STATUS_DISMISSED;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isInvalidated(): bool
    {
        return $this->status === self::STATUS_INVALIDATED;
    }
}
