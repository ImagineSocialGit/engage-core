<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Services\FlowRouteProgressMetaCanonicalizer;
use Illuminate\Support\Carbon;

class AdvanceContactFlowRouteProgressAction
{
    public function __construct(
        private readonly CompleteContactFlowRouteProgressAction $completeContactFlowRouteProgress,
        private readonly FlowRouteProgressMetaCanonicalizer $progressMetaCanonicalizer,
    ) {}

    public function handle(
        ContactFlowRouteProgress $progress,
        ContactFlowRoutePlanItem $fromPlanItem,
        FlowRoutePoint $fromFlowRoutePoint,
        ?PointExecutionResult $result = null,
    ): ContactFlowRouteProgress {
        if (! $progress->isRunnable()) {
            return $progress;
        }

        if ($result?->completeFlowRoute) {
            return $this->completeContactFlowRouteProgress->handle($progress, $result);
        }

        $nextPlanItem = $this->requestedNextPlanItem($fromPlanItem, $result)
            ?? $this->configuredNextPlanItem($fromPlanItem, $fromFlowRoutePoint)
            ?? $this->nextSequentialPlanItem($fromPlanItem);

        if (! $nextPlanItem instanceof ContactFlowRoutePlanItem) {
            return $this->completeContactFlowRouteProgress->handle($progress, $result);
        }

        $nextFlowRoutePoint = $nextPlanItem->flowRoutePoint;
        $advancedAt = Carbon::now();

        $nextPlanItem->forceFill([
            'status' => ContactFlowRoutePlanItem::STATUS_ACTIVE,
            'available_at' => $nextPlanItem->available_at ?? $advancedAt,
        ])->save();

        $progress->forceFill([
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'current_flow_route_point_id' => $nextFlowRoutePoint?->getKey(),
            'resume_at' => null,
            'waiting_event_key' => null,
            'meta' => $this->metaWithoutWaiting($progress),
        ])->save();

        return $progress->refresh();
    }

    private function requestedNextPlanItem(
        ContactFlowRoutePlanItem $fromPlanItem,
        ?PointExecutionResult $result,
    ): ?ContactFlowRoutePlanItem {
        if (! $result) {
            return null;
        }

        $targetFlowRoutePointId = $this->nullableInteger($result->meta['advance_to_flow_route_point_id'] ?? null);
        $targetFlowRoutePointKey = $this->nullableString($result->meta['advance_to_flow_route_point_key'] ?? null);
        $targetPlanItemId = $this->nullableInteger($result->meta['advance_to_flow_route_plan_item_id'] ?? null);
        $targetPlanItemKey = $this->nullableString($result->meta['advance_to_flow_route_plan_item_key'] ?? null);

        if (! $targetFlowRoutePointId && $targetFlowRoutePointKey === null && ! $targetPlanItemId && $targetPlanItemKey === null) {
            return null;
        }

        $query = ContactFlowRoutePlanItem::query()
            ->with('flowRoutePoint')
            ->where('contact_flow_route_plan_id', $fromPlanItem->contact_flow_route_plan_id)
            ->whereKeyNot($fromPlanItem->getKey())
            ->runnable();

        if ($targetPlanItemId) {
            return $query->whereKey($targetPlanItemId)->first();
        }

        if ($targetPlanItemKey !== null) {
            return $query->where('key', $targetPlanItemKey)->first();
        }

        if ($targetFlowRoutePointId) {
            return $query->where('flow_route_point_id', $targetFlowRoutePointId)->first();
        }

        return $query->whereHas(
            'flowRoutePoint',
            fn ($flowRoutePointQuery) => $flowRoutePointQuery->forKey($targetFlowRoutePointKey),
        )->first();
    }

    private function configuredNextPlanItem(
        ContactFlowRoutePlanItem $fromPlanItem,
        FlowRoutePoint $fromFlowRoutePoint,
    ): ?ContactFlowRoutePlanItem {
        $nextFlowRoutePointId = $this->nullableInteger($fromFlowRoutePoint->next_flow_route_point_id);

        if (! $nextFlowRoutePointId) {
            return null;
        }

        return ContactFlowRoutePlanItem::query()
            ->with('flowRoutePoint')
            ->where('contact_flow_route_plan_id', $fromPlanItem->contact_flow_route_plan_id)
            ->where('flow_route_point_id', $nextFlowRoutePointId)
            ->runnable()
            ->oldest('sequence')
            ->first();
    }

    private function nextSequentialPlanItem(ContactFlowRoutePlanItem $fromPlanItem): ?ContactFlowRoutePlanItem
    {
        return ContactFlowRoutePlanItem::query()
            ->with('flowRoutePoint')
            ->where('contact_flow_route_plan_id', $fromPlanItem->contact_flow_route_plan_id)
            ->where('sequence', '>', (int) $fromPlanItem->sequence)
            ->runnable()
            ->oldest('sequence')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function metaWithoutWaiting(
        ContactFlowRouteProgress $progress,
    ): array {
        $meta = $this->progressMetaCanonicalizer->forPersistence(
            $progress->meta ?? [],
        );
        unset($meta['waiting']);

        return $meta;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}