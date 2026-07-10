<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReconcileFlowRouteProgressToCurrentVersionAction
{
    public function __construct(
        private readonly CreateContactFlowRoutePlanAction $createContactFlowRoutePlan,
    ) {}

    public function handle(FlowRoute $currentFlowRoute): int
    {
        $historicalRouteIds = FlowRoute::query()
            ->where('key', $currentFlowRoute->key)
            ->whereKeyNot($currentFlowRoute->getKey())
            ->pluck('id');

        if ($historicalRouteIds->isEmpty()) {
            return 0;
        }

        $progressIds = ContactFlowRouteProgress::query()
            ->runnable()
            ->whereIn('flow_route_id', $historicalRouteIds)
            ->pluck('id');

        $reconciled = 0;

        foreach ($progressIds as $progressId) {
            $this->reconcileOne((int) $progressId, $currentFlowRoute);
            $reconciled++;
        }

        return $reconciled;
    }

    private function reconcileOne(int $progressId, FlowRoute $currentFlowRoute): void
    {
        DB::transaction(function () use ($progressId, $currentFlowRoute): void {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with([
                    'currentFlowRoutePoint',
                    'plan.items',
                ])
                ->findOrFail($progressId);

            if (! $progress->isRunnable() || (int) $progress->flow_route_id === (int) $currentFlowRoute->getKey()) {
                return;
            }

            $oldCurrentPoint = $progress->currentFlowRoutePoint;

            if (! $oldCurrentPoint instanceof FlowRoutePoint || ! is_string($oldCurrentPoint->key) || trim($oldCurrentPoint->key) === '') {
                throw new RuntimeException("FlowRoute progress [{$progress->getKey()}] cannot reconcile to FlowRoute [{$currentFlowRoute->key}] version [{$currentFlowRoute->version}] because its current point has no durable key.");
            }

            $newCurrentPoint = $currentFlowRoute->activeFlowRoutePoints()
                ->where('key', $oldCurrentPoint->key)
                ->first();

            if (! $newCurrentPoint instanceof FlowRoutePoint) {
                throw new RuntimeException("FlowRoute progress [{$progress->getKey()}] cannot reconcile from point [{$oldCurrentPoint->key}] to FlowRoute [{$currentFlowRoute->key}] version [{$currentFlowRoute->version}] because that active point key does not exist in the new version.");
            }


            $oldPlan = $progress->plan;

            if (! $oldPlan instanceof ContactFlowRoutePlan) {
                $oldPlan = $this->createContactFlowRoutePlan->handle($progress, $progress->flowRoute);
                $oldPlan->load('items');
            }

            $newPlan = $this->createContactFlowRoutePlan->handle(
                progress: $progress,
                flowRoute: $currentFlowRoute,
                forceNew: true,
                reconciledFromPlan: $oldPlan,
            );

            $newPlan->load('items');

            $oldItemsByKey = $oldPlan->items->keyBy('key');
            $newItemsByKey = $newPlan->items->keyBy('key');
            $oldCurrentItem = $oldItemsByKey->get($oldCurrentPoint->key);
            $newCurrentItem = $newItemsByKey->get($newCurrentPoint->key);

            if (! $newCurrentItem instanceof ContactFlowRoutePlanItem) {
                throw new RuntimeException("FlowRoute progress [{$progress->getKey()}] cannot reconcile because new plan [{$newPlan->getKey()}] has no plan item for point [{$newCurrentPoint->key}].");
            }

            foreach ($newPlan->items as $newItem) {
                $oldItem = $oldItemsByKey->get($newItem->key);

                if ($newItem->getKey() === $newCurrentItem->getKey()) {
                    $this->carryCurrentItemState($progress, $oldCurrentItem, $newItem);
                    continue;
                }

                if ($oldItem instanceof ContactFlowRoutePlanItem && $this->shouldPreserveTerminalState($oldItem)) {
                    $this->copyTerminalState($oldItem, $newItem);
                }
            }

            $now = Carbon::now();

            $oldPlan->forceFill([
                'status' => ContactFlowRoutePlan::STATUS_SUPERSEDED,
                'superseded_at' => $now,
                'meta' => array_replace_recursive($oldPlan->meta ?? [], [
                    'reconciliation' => [
                        'superseded_at' => $now->toISOString(),
                        'superseded_by_plan_id' => $newPlan->getKey(),
                        'to_flow_route_id' => $currentFlowRoute->getKey(),
                        'to_flow_route_version' => $currentFlowRoute->version,
                    ],
                ]),
            ])->save();

            $meta = $progress->meta ?? [];
            $waiting = $meta['waiting'] ?? null;

            if ($progress->isWaiting() && is_array($waiting)) {
                $waiting['flow_route_plan_id'] = $newPlan->getKey();
                $waiting['flow_route_plan_item_id'] = $newCurrentItem->getKey();
                $waiting['flow_route_point_id'] = $newCurrentPoint->getKey();
                $waiting['flow_route_point_key'] = $newCurrentPoint->key;
                $waiting['point_type'] = $newCurrentPoint->type;
                $meta['waiting'] = $waiting;
            }

            $entry = [
                'reconciled_at' => $now->toISOString(),
                'from_flow_route_id' => $progress->flow_route_id,
                'from_flow_route_version' => $oldPlan->flow_route_version,
                'from_flow_route_plan_id' => $oldPlan->getKey(),
                'from_flow_route_point_id' => $oldCurrentPoint->getKey(),
                'from_flow_route_point_key' => $oldCurrentPoint->key,
                'to_flow_route_id' => $currentFlowRoute->getKey(),
                'to_flow_route_version' => $currentFlowRoute->version,
                'to_flow_route_plan_id' => $newPlan->getKey(),
                'to_flow_route_point_id' => $newCurrentPoint->getKey(),
                'to_flow_route_point_key' => $newCurrentPoint->key,
            ];

            $history = $meta['version_reconciliation_history'] ?? [];
            $history = is_array($history) ? $history : [];
            $history[] = $entry;
            $meta['last_version_reconciliation'] = $entry;
            $meta['version_reconciliation_history'] = array_slice($history, -50);

            $progress->forceFill([
                'flow_route_id' => $currentFlowRoute->getKey(),
                'current_flow_route_point_id' => $newCurrentPoint->getKey(),
                'meta' => $meta,
            ])->save();
        });
    }

    private function carryCurrentItemState(
        ContactFlowRouteProgress $progress,
        mixed $oldCurrentItem,
        ContactFlowRoutePlanItem $newCurrentItem,
    ): void {
        $oldCurrentItem = $oldCurrentItem instanceof ContactFlowRoutePlanItem ? $oldCurrentItem : null;

        $newCurrentItem->forceFill([
            'status' => $progress->isWaiting()
                ? ContactFlowRoutePlanItem::STATUS_WAITING
                : ContactFlowRoutePlanItem::STATUS_ACTIVE,
            'attempt' => $oldCurrentItem?->attempt ?? 0,
            'available_at' => $oldCurrentItem?->available_at,
            'started_at' => $oldCurrentItem?->started_at,
            'resume_at' => $progress->resume_at,
            'waiting_event_key' => $progress->waiting_event_key,
            'correlation' => $oldCurrentItem?->correlation,
            'result_payload' => $oldCurrentItem?->result_payload,
            'meta' => array_replace_recursive($newCurrentItem->meta ?? [], [
                'reconciliation' => [
                    'carried_from_plan_item_id' => $oldCurrentItem?->getKey(),
                ],
            ]),
        ])->save();
    }

    private function shouldPreserveTerminalState(ContactFlowRoutePlanItem $item): bool
    {
        return in_array($item->status, [
            ContactFlowRoutePlanItem::STATUS_COMPLETED,
            ContactFlowRoutePlanItem::STATUS_SKIPPED,
            ContactFlowRoutePlanItem::STATUS_CANCELLED,
        ], true);
    }

    private function copyTerminalState(
        ContactFlowRoutePlanItem $oldItem,
        ContactFlowRoutePlanItem $newItem,
    ): void {
        $newItem->forceFill([
            'status' => $oldItem->status,
            'attempt' => $oldItem->attempt,
            'result_reason' => $oldItem->result_reason,
            'available_at' => $oldItem->available_at,
            'started_at' => $oldItem->started_at,
            'completed_at' => $oldItem->completed_at,
            'skipped_at' => $oldItem->skipped_at,
            'cancelled_at' => $oldItem->cancelled_at,
            'failed_at' => $oldItem->failed_at,
            'result_payload' => $oldItem->result_payload,
            'meta' => array_replace_recursive($newItem->meta ?? [], [
                'reconciliation' => [
                    'carried_from_plan_item_id' => $oldItem->getKey(),
                    'preserved_terminal_status' => $oldItem->status,
                ],
            ]),
        ])->save();
    }
}
