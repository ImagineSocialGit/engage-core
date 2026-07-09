<?php

namespace App\Modules\Campaigns\Models;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class CampaignEnrollment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    public const EXIT_REASON_CONDITION_MATCHED = 'condition_matched';
    public const EXIT_REASON_NO_NEXT_STEP = 'no_next_step';

    protected $fillable = [
        'contact_id',
        'campaign_id',
        'source_type',
        'source_id',
        'flow_route_progress_id',
        'flow_route_plan_id',
        'flow_route_plan_item_id',
        'flow_route_progress_item_id',
        'flow_route_id',
        'flow_route_point_id',
        'flow_route_capability_id',
        'campaign_key',
        'status',
        'current_step',
        'current_campaign_step_id',
        'start_context',
        'exit_conditions',
        'exited_at',
        'exit_reason',
        'last_scheduled_message_id',
        'dedupe_key',
        'started_at',
        'paused_at',
        'resumed_at',
        'cancelled_at',
        'completed_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'contact_id' => 'integer',
            'campaign_id' => 'integer',
            'source_id' => 'integer',
            'flow_route_progress_id' => 'integer',
            'flow_route_plan_id' => 'integer',
            'flow_route_plan_item_id' => 'integer',
            'flow_route_progress_item_id' => 'integer',
            'flow_route_id' => 'integer',
            'flow_route_point_id' => 'integer',
            'flow_route_capability_id' => 'integer',
            'current_step' => 'integer',
            'current_campaign_step_id' => 'integer',
            'start_context' => 'array',
            'exit_conditions' => 'array',
            'last_scheduled_message_id' => 'integer',
            'exited_at' => 'datetime',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'resumed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'completed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function currentCampaignStep(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'current_campaign_step_id');
    }

    public function source(): MorphTo
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

    public function lastScheduledMessage(): BelongsTo
    {
        return $this->belongsTo(ScheduledMessage::class, 'last_scheduled_message_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }
}
