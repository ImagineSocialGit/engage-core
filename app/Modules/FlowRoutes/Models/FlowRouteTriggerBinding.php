<?php

namespace App\Modules\FlowRoutes\Models;

use Database\Factories\FlowRouteTriggerBindingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FlowRouteTriggerBinding extends Model
{
    use HasFactory;

    protected static function newFactory(): FlowRouteTriggerBindingFactory
    {
        return FlowRouteTriggerBindingFactory::new();
    }

    protected $fillable = [
        'trigger_type',
        'trigger_key',
        'flow_route_id',
        'context_type',
        'context_id',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'flow_route_id' => 'integer',
        'context_id' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeForTrigger(Builder $query, string $triggerType, ?string $triggerKey = null): Builder
    {
        $query->where('trigger_type', $triggerType);

        if ($triggerKey !== null) {
            $query->where('trigger_key', $triggerKey);
        }

        return $query;
    }

    public function scopeForContext(
        Builder $query,
        ?string $contextType = null,
        int|string|null $contextId = null,
    ): Builder {
        if ($contextType === null && $contextId === null) {
            return $query
                ->whereNull('context_type')
                ->whereNull('context_id');
        }

        return $query
            ->where('context_type', $contextType)
            ->where('context_id', $contextId);
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query
            ->whereNull('context_type')
            ->whereNull('context_id');
    }
}