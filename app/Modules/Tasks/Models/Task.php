<?php

namespace App\Modules\Tasks\Models;

use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Task extends Model
{
    use HasFactory;

    protected static function newFactory(): TaskFactory
    {
        return TaskFactory::new();
    }

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_SYSTEM = 'system';
    public const SOURCE_MODULE = 'module';

    public const SOURCE_OPTIONS = [
        self::SOURCE_MANUAL,
        self::SOURCE_SYSTEM,
        self::SOURCE_MODULE,
    ];

    public const STATUS_OPEN = 'open';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    public const RESPONSIBLE_PARTY_INTERNAL = 'internal';
    public const RESPONSIBLE_PARTY_CONTACT = 'contact';
    public const RESPONSIBLE_PARTY_THIRD_PARTY = 'third_party';
    public const RESPONSIBLE_PARTY_UNKNOWN = 'unknown';

    public const RESPONSIBLE_PARTY_OPTIONS = [
        self::RESPONSIBLE_PARTY_INTERNAL,
        self::RESPONSIBLE_PARTY_CONTACT,
        self::RESPONSIBLE_PARTY_THIRD_PARTY,
        self::RESPONSIBLE_PARTY_UNKNOWN,
    ];

    protected $fillable = [
        'assigned_to_type',
        'assigned_to_id',
        'responsible_party',
        'responsible_type',
        'responsible_id',
        'task_template_id',
        'task_template_key',
        'source',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'canceled_at',
        'canceled_reason',
        'archived_at',
        'meta',
    ];

    protected $casts = [
        'assigned_to_id' => 'integer',
        'responsible_id' => 'integer',
        'task_template_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'archived_at' => 'datetime',
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

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(TaskLink::class);
    }

    public function subjectLinks(): HasMany
    {
        return $this->links()->where('role', TaskLink::ROLE_SUBJECT);
    }

    public function contextLinks(): HasMany
    {
        return $this->links()->where('role', TaskLink::ROLE_CONTEXT);
    }

    public function resultLinks(): HasMany
    {
        return $this->links()->where('role', TaskLink::ROLE_RESULT);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeCanceled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CANCELED);
    }

    public function scopeUnarchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->whereNotNull('assigned_to_type')
            ->whereNotNull('assigned_to_id');
    }

    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to_type')
            ->whereNull('assigned_to_id');
    }

    public function scopeResponsibleParty(Builder $query, string $responsibleParty): Builder
    {
        return $query->where('responsible_party', $responsibleParty);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isCanceled(): bool
    {
        return $this->status === self::STATUS_CANCELED;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isAssigned(): bool
    {
        return $this->assigned_to_type !== null
            && $this->assigned_to_id !== null;
    }

    public function isManual(): bool
    {
        return $this->source === self::SOURCE_MANUAL;
    }

    public function isAutomationCreated(): bool
    {
        return ! $this->isManual();
    }

    public function isResponsibleParty(string $responsibleParty): bool
    {
        return $this->responsible_party === $responsibleParty;
    }
}
