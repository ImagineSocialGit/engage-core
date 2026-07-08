<?php

namespace App\Modules\FlowRoutes\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactFlowRoutePlanItem extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    public const SOURCE_TEMPLATE = 'template';
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_VERTICAL = 'vertical';

    protected $fillable = [
        'contact_flow_route_progress_id',
        'contact_flow_route_plan_id',
        'flow_route_id',
        'flow_route_point_id',
        'point_id',
        'flow_route_capability_id',
        'key',
        'point_type',
        'sort_order',
        'sequence',
        'attempt',
        'source',
        'status',
        'result_reason',
        'available_at',
        'started_at',
        'completed_at',
        'skipped_at',
        'cancelled_at',
        'failed_at',
        'resume_at',
        'waiting_event_key',
        'definition_snapshot',
        'settings_snapshot',
        'cancel_conditions_snapshot',
        'correlation',
        'result_payload',
        'meta',
    ];

    protected $casts = [
        'contact_flow_route_progress_id' => 'integer',
        'contact_flow_route_plan_id' => 'integer',
        'flow_route_id' => 'integer',
        'flow_route_point_id' => 'integer',
        'point_id' => 'integer',
        'flow_route_capability_id' => 'integer',
        'sort_order' => 'integer',
        'sequence' => 'integer',
        'attempt' => 'integer',
        'available_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'skipped_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'resume_at' => 'datetime',
        'definition_snapshot' => 'array',
        'settings_snapshot' => 'array',
        'cancel_conditions_snapshot' => 'array',
        'correlation' => 'array',
        'result_payload' => 'array',
        'meta' => 'array',
    ];

    public function progress(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRouteProgress::class, 'contact_flow_route_progress_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(ContactFlowRoutePlan::class, 'contact_flow_route_plan_id');
    }

    public function flowRoute(): BelongsTo
    {
        return $this->belongsTo(FlowRoute::class);
    }

    public function flowRoutePoint(): BelongsTo
    {
        return $this->belongsTo(FlowRoutePoint::class);
    }

    public function point(): BelongsTo
    {
        return $this->belongsTo(Point::class);
    }

    public function capability(): BelongsTo
    {
        return $this->belongsTo(FlowRouteCapability::class, 'flow_route_capability_id');
    }

    public function progressItems(): HasMany
    {
        return $this->hasMany(ContactFlowRouteProgressItem::class, 'contact_flow_route_plan_item_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BLOCKED);
    }

    public function scopeRunnable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_PENDING,
            self::STATUS_ACTIVE,
            self::STATUS_WAITING,
        ]);
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            self::STATUS_COMPLETED,
            self::STATUS_SKIPPED,
            self::STATUS_CANCELLED,
            self::STATUS_FAILED,
        ]);
    }

    public function scopeWaitingForEvent(Builder $query, string $eventKey): Builder
    {
        return $query
            ->waiting()
            ->where('waiting_event_key', $eventKey);
    }

    public function scopeDueToResume(Builder $query): Builder
    {
        return $query
            ->waiting()
            ->whereNotNull('resume_at')
            ->where('resume_at', '<=', now());
    }
}

