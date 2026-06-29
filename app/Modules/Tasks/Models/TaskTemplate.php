<?php

namespace App\Modules\Tasks\Models;

use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskTemplate extends Model
{
    use HasFactory;

    protected static function newFactory(): TaskTemplateFactory
    {
        return TaskTemplateFactory::new();
    }

    protected $fillable = [
        'key',
        'group_key',
        'name',
        'title',
        'description',
        'task_description',
        'responsible_party',
        'priority',
        'due_offset_days',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'due_offset_days' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGroup(Builder $query, string $groupKey): Builder
    {
        return $query->where('group_key', $groupKey);
    }
}