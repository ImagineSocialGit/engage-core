<?php

namespace App\Modules\Tasks\Models;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'related_type',
        'related_id',
        'assigned_to_type',
        'assigned_to_id',
        'responsible_party',
        'responsible_type',
        'responsible_id',
        'flow_route_progress_id',
        'flow_route_plan_id',
        'flow_route_plan_item_id',
        'flow_route_progress_item_id',
        'flow_route_id',
        'flow_route_point_id',
        'flow_route_capability_id',
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
        'related_id' => 'integer',
        'assigned_to_id' => 'integer',
        'responsible_id' => 'integer',
        'flow_route_progress_id' => 'integer',
        'flow_route_plan_id' => 'integer',
        'flow_route_plan_item_id' => 'integer',
        'flow_route_progress_item_id' => 'integer',
        'flow_route_id' => 'integer',
        'flow_route_point_id' => 'integer',
        'flow_route_capability_id' => 'integer',
        'task_template_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'canceled_at' => 'datetime',
        'archived_at' => 'datetime',
        'meta' => 'array',
    ];

    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    public function assignedTo(): MorphTo
    {
        return $this->morphTo();
    }

    public function responsible(): MorphTo
    {
        return $this->morphTo();
    }

    public function flowRouteProgress(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRouteProgress::class, 'flow_route_progress_id');
    }

    public function flowRoutePlan(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRoutePlan::class, 'flow_route_plan_id');
    }

    public function flowRoutePlanItem(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRoutePlanItem::class, 'flow_route_plan_item_id');
    }

    public function flowRouteProgressItem(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRouteProgressItem::class, 'flow_route_progress_item_id');
    }

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function flowRoutePoint(): BelongsTo
    {
        return $this->belongsTo(FlowRoutePoint::class);
    }

    public function flowRouteCapability(): BelongsTo
    {
        return $this->belongsTo(FlowRouteCapability::class);
    }

    public function taskTemplate(): BelongsTo
    {
        return $this->belongsTo(TaskTemplate::class);
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

    public function scopeCreatedByFlowRoute(Builder $query): Builder
    {
        return $query->whereNotNull('flow_route_progress_id');
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

    public function isResponsibleParty(string $responsibleParty): bool
    {
        return $this->responsible_party === $responsibleParty;
    }
}
