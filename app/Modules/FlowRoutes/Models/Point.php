<?php

namespace App\Modules\FlowRoutes\Models;

use App\Modules\Tasks\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Point extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'task_title_template',
        'task_description_template',
        'default_due_offset_days',
        'default_assignment_strategy',
        'default_assigned_to_type',
        'default_assigned_to_id',
        'default_cancel_conditions',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'default_due_offset_days' => 'integer',
        'default_assigned_to_id' => 'integer',
        'default_cancel_conditions' => 'array',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function defaultAssignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function flowRoutePoints(): HasMany
    {
        return $this->hasMany(FlowRoutePoint::class);
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