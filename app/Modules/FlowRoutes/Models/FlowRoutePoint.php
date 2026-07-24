<?php

namespace App\Modules\FlowRoutes\Models;

use Database\Factories\FlowRoutePointFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRoutePoint extends Model
{
    use HasFactory;

    protected static function newFactory(): FlowRoutePointFactory
    {
        return FlowRoutePointFactory::new();
    }

    protected $fillable = [
        'flow_route_id',
        'flow_route_capability_id',
        'key',
        'type',
        'name',
        'description',
        'sort_order',
        'is_start',
        'is_active',
        'next_flow_route_point_id',
        'definition',
        'settings',
        'cancel_conditions',
        'source_version',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'flow_route_id' => 'integer',
        'flow_route_capability_id' => 'integer',
        'sort_order' => 'integer',
        'is_start' => 'boolean',
        'is_active' => 'boolean',
        'next_flow_route_point_id' => 'integer',
        'definition' => 'array',
        'settings' => 'array',
        'cancel_conditions' => 'array',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(FlowRouteCapability::class, 'flow_route_capability_id');
    }

    public function nextFlowRoutePoint(): BelongsTo
    {
        return $this->belongsTo(self::class, 'next_flow_route_point_id');
    }

    public function previousFlowRoutePoints(): HasMany
    {
        return $this->hasMany(self::class, 'next_flow_route_point_id');
    }

    public function contactFlowRouteProgress(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgress::class, 'current_flow_route_point_id');
    }

    public function planItems(): HasMany
    {
        return $this->hasMany(ContactFlowRoutePlanItem::class);
    }

    public function progressItems(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgressItem::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeStart(Builder $query): Builder
    {
        return $query->where('is_start', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
    }

    public function scopeForPointType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', true);
    }

    public function scopeNotCustomized(Builder $query): Builder
    {
        return $query->where('is_customized', false);
    }
}