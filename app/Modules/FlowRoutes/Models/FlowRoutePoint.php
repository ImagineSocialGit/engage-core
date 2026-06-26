<?php

namespace App\Modules\FlowRoutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowRoutePoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_route_id',
        'point_id',
        'sort_order',
        'is_active',
        'definition',
        'settings',
        'cancel_conditions',
        'meta',
    ];

    protected $casts = [
        'flow_route_id' => 'integer',
        'point_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'definition' => 'array',
        'settings' => 'array',
        'cancel_conditions' => 'array',
        'meta' => 'array',
    ];

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class);
    }

    public function contactFlowRouteProgress(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgress::class, 'current_flow_route_point_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    public function scopeForPointType(Builder $query, string $type): Builder
    {
        return $query->whereHas('point', fn (Builder $pointQuery) => $pointQuery->where('type', $type));
    }
}