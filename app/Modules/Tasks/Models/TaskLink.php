<?php

namespace App\Modules\Tasks\Models;

use Database\Factories\TaskLinkFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TaskLink extends Model
{
    use HasFactory;

    public const ROLE_SUBJECT = 'subject';
    public const ROLE_CONTEXT = 'context';
    public const ROLE_RESULT = 'result';

    public const ROLES = [
        self::ROLE_SUBJECT,
        self::ROLE_CONTEXT,
        self::ROLE_RESULT,
    ];

    protected $fillable = [
        'task_id',
        'linkable_type',
        'linkable_id',
        'role',
    ];

    protected $casts = [
        'task_id' => 'integer',
        'linkable_id' => 'integer',
    ];

    protected static function newFactory(): TaskLinkFactory
    {
        return TaskLinkFactory::new();
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }

    public function scopeSubject(Builder $query): Builder
    {
        return $query->role(self::ROLE_SUBJECT);
    }

    public function scopeContext(Builder $query): Builder
    {
        return $query->role(self::ROLE_CONTEXT);
    }

    public function scopeResult(Builder $query): Builder
    {
        return $query->role(self::ROLE_RESULT);
    }
}
