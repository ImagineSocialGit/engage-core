<?php

namespace App\Modules\Tasks\Models;

use Database\Factories\TaskTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskTemplate extends Model
{
    use HasFactory;

    public const SOURCE_PRESET = 'preset';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_MODULE = 'module';

    public const ASSIGNED_TO_STRATEGY_UNASSIGNED = 'unassigned';
    public const ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER = 'only_active_team_member';

    public const ASSIGNED_TO_STRATEGIES = [
        self::ASSIGNED_TO_STRATEGY_UNASSIGNED,
        self::ASSIGNED_TO_STRATEGY_ONLY_ACTIVE_TEAM_MEMBER,
    ];

    public const LINK_SOURCE_CURRENT_CONTACT = 'current_contact';
    public const LINK_SOURCE_CURRENT_SUBJECT = 'current_subject';

    public const LINK_SOURCES = [
        self::LINK_SOURCE_CURRENT_CONTACT,
        self::LINK_SOURCE_CURRENT_SUBJECT,
    ];

    protected static function newFactory(): TaskTemplateFactory
    {
        return TaskTemplateFactory::new();
    }

    protected $fillable = [
        'key',
        'source',
        'source_version',
        'owner_group',
        'category',
        'name',
        'title',
        'description',
        'task_description',
        'assigned_to_type',
        'assigned_to_id',
        'assigned_to_strategy',
        'responsible_party',
        'responsible_type',
        'responsible_id',
        'priority',
        'due_offset_minutes',
        'link_defaults',
        'defaults',
        'is_active',
        'is_customized',
        'customized_at',
        'meta',
    ];

    protected $casts = [
        'assigned_to_id' => 'integer',
        'responsible_id' => 'integer',
        'due_offset_minutes' => 'integer',
        'link_defaults' => 'array',
        'defaults' => 'array',
        'is_active' => 'boolean',
        'is_customized' => 'boolean',
        'customized_at' => 'datetime',
        'meta' => 'array',
    ];

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function responsible(): MorphTo
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

    public function scopeForKey(Builder $query, string $key): Builder
    {
        return $query->where('key', $key);
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
