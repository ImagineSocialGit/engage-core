<?php

namespace App\Modules\FlowRoutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FlowRouteCapabilityBinding extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_route_capability_id',
        'context_type',
        'context_id',
        'owner_type',
        'owner_id',
        'module_key',
        'visibility',
        'sort_order',
        'label',
        'description',
        'help_text',
        'defaults',
        'constraints',
        'input_overrides',
        'output_overrides',
        'is_enabled',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'flow_route_capability_id' => 'integer',
        'context_id' => 'integer',
        'owner_id' => 'integer',
        'sort_order' => 'integer',
        'defaults' => 'array',
        'constraints' => 'array',
        'input_overrides' => 'array',
        'output_overrides' => 'array',
        'is_enabled' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function capability(): BelongsTo
    {
        return $this->belongsTo(FlowRouteCapability::class, 'flow_route_capability_id');
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
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

    public function scopeVisibility(Builder $query, string $visibility): Builder
    {
        return $query->where('visibility', $visibility);
    }

    public function scopeForModule(Builder $query, string $moduleKey): Builder
    {
        return $query->where('module_key', $moduleKey);
    }
}
