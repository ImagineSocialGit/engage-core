<?php

namespace App\Modules\Messaging\Models;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteCapability;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Database\Factories\ScheduledMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ScheduledMessage extends Model
{
    use HasFactory;

    protected static function newFactory(): ScheduledMessageFactory
    {
        return ScheduledMessageFactory::new();
    }

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'context_type',
        'context_id',
        'flow_route_progress_id',
        'flow_route_plan_id',
        'flow_route_plan_item_id',
        'flow_route_progress_item_id',
        'flow_route_id',
        'flow_route_point_id',
        'flow_route_capability_id',
        'channel',
        'message_type',
        'purpose',
        'scope',
        'payload_class',
        'queue',
        'dispatch_keys',
        'definition_config_path',
        'payload',
        'send_at',
        'status',
        'sent_at',
        'skipped_at',
        'failed_at',
        'dedupe_key',
        'failure_reason',
        'skip_reason',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'context_id' => 'integer',
            'flow_route_progress_id' => 'integer',
            'flow_route_plan_id' => 'integer',
            'flow_route_plan_item_id' => 'integer',
            'flow_route_progress_item_id' => 'integer',
            'flow_route_id' => 'integer',
            'flow_route_point_id' => 'integer',
            'flow_route_capability_id' => 'integer',
            'dispatch_keys' => 'array',
            'payload' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
            'skipped_at' => 'datetime',
            'failed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function recipient(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
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

    /**
     * @return array<int, string>
     */
    public function dispatchKeys(): array
    {
        return array_values(array_filter(
            $this->dispatch_keys ?? [],
            fn (mixed $dispatchKey): bool => is_string($dispatchKey) && trim($dispatchKey) !== '',
        ));
    }
}
