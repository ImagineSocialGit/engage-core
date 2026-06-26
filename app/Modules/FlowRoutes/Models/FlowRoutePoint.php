<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FlowRoutePoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_route_id',
        'point_id',
        'sort_order',
        'is_active',
        'due_offset_days',
        'assignment_strategy',
        'assigned_to_type',
        'assigned_to_id',
        'cancel_conditions',
        'meta',
    ];

    protected $casts = [
        'flow_route_id' => 'integer',
        'point_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'due_offset_days' => 'integer',
        'assigned_to_id' => 'integer',
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

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function generatedTasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}