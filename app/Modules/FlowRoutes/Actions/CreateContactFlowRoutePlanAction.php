<?php

namespace App\Modules\FlowRoutes\Actions;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateContactFlowRoutePlanAction
{
    public function handle(
        ContactFlowRouteProgress $progress,
        ?FlowRoute $flowRoute = null,
        bool $forceNew = false,
        ?ContactFlowRoutePlan $reconciledFromPlan = null,
    ): ContactFlowRoutePlan {
        return DB::transaction(function () use ($progress, $flowRoute, $forceNew, $reconciledFromPlan) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with('plan')
                ->findOrFail($progress->getKey());

            if (! $forceNew && $progress->plan instanceof ContactFlowRoutePlan) {
                return $progress->plan;
            }

            $flowRoute ??= FlowRoute::query()
                ->findOrFail($progress->flow_route_id);

            $flowRoutePoints = $flowRoute->activeFlowRoutePoints()
                ->with('capability')
                ->ordered()
                ->get();

            $now = Carbon::now();
            $revision = ((int) ContactFlowRoutePlan::query()
                ->where('contact_flow_route_progress_id', $progress->getKey())
                ->max('revision')) + 1;

            $plan = ContactFlowRoutePlan::query()->create([
                'contact_flow_route_progress_id' => $progress->getKey(),
                'contact_id' => $progress->contact_id,
                'subject_type' => $progress->subject_type,
                'subject_id' => $progress->subject_id,
                'flow_route_id' => $flowRoute->getKey(),
                'status' => ContactFlowRoutePlan::STATUS_ACTIVE,
                'source' => ContactFlowRoutePlan::SOURCE_TEMPLATE,
                'revision' => $revision,
                'flow_route_version' => $flowRoute->version,
                'snapshot_at' => $now,
                'started_at' => $progress->started_at ?? $now,
                'reconciled_from_plan_id' => $reconciledFromPlan?->getKey(),
                'route_snapshot' => $this->routeSnapshot($flowRoute),
                'meta' => array_filter([
                    'created_by' => 'flow_routes',
                    'created_from' => $reconciledFromPlan instanceof ContactFlowRoutePlan
                        ? 'flow_route_version_reconciliation'
                        : 'flow_route_template',
                    'reconciled_from_plan_id' => $reconciledFromPlan?->getKey(),
                    'reconciled_from_flow_route_id' => $reconciledFromPlan?->flow_route_id,
                    'reconciled_from_flow_route_version' => $reconciledFromPlan?->flow_route_version,
                ], static fn (mixed $value): bool => $value !== null),
            ]);

            $sequence = 1;

            /** @var FlowRoutePoint $flowRoutePoint */
            foreach ($flowRoutePoints as $flowRoutePoint) {
                ContactFlowRoutePlanItem::query()->create([
                    'contact_flow_route_progress_id' => $progress->getKey(),
                    'contact_flow_route_plan_id' => $plan->getKey(),
                    'flow_route_id' => $flowRoute->getKey(),
                    'flow_route_point_id' => $flowRoutePoint->getKey(),
                    'flow_route_capability_id' => $flowRoutePoint->flow_route_capability_id,
                    'key' => $flowRoutePoint->key,
                    'point_type' => $flowRoutePoint->type,
                    'sort_order' => $flowRoutePoint->sort_order,
                    'sequence' => $sequence++,
                    'attempt' => 0,
                    'source' => ContactFlowRoutePlanItem::SOURCE_TEMPLATE,
                    'status' => ! $forceNew
                        && (int) $progress->current_flow_route_point_id === (int) $flowRoutePoint->getKey()
                            ? ContactFlowRoutePlanItem::STATUS_ACTIVE
                            : ContactFlowRoutePlanItem::STATUS_PENDING,
                    'definition_snapshot' => $flowRoutePoint->definition ?? [],
                    'settings_snapshot' => $flowRoutePoint->settings ?? [],
                    'cancel_conditions_snapshot' => $flowRoutePoint->cancel_conditions ?? [],
                    'meta' => [
                        'flow_route_point_snapshot' => $this->flowRoutePointSnapshot($flowRoutePoint),
                    ],
                ]);
            }

            return $plan->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function routeSnapshot(FlowRoute $flowRoute): array
    {
        return [
            'id' => $flowRoute->getKey(),
            'key' => $flowRoute->key,
            'name' => $flowRoute->name,
            'version' => $flowRoute->version,
            'trigger_type' => $flowRoute->trigger_type,
            'trigger_key' => $flowRoute->trigger_key,
            'owner_type' => $flowRoute->owner_type,
            'owner_id' => $flowRoute->owner_id,
            'owner_group' => $flowRoute->owner_group,
            'source_version' => $flowRoute->source_version,
            'meta' => $flowRoute->meta ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flowRoutePointSnapshot(FlowRoutePoint $flowRoutePoint): array
    {
        return [
            'id' => $flowRoutePoint->getKey(),
            'key' => $flowRoutePoint->key,
            'sort_order' => $flowRoutePoint->sort_order,
            'is_start' => $flowRoutePoint->is_start,
            'next_flow_route_point_id' => $flowRoutePoint->next_flow_route_point_id,
            'point_type' => $flowRoutePoint->type,
            'name' => $flowRoutePoint->name,
            'description' => $flowRoutePoint->description,
            'flow_route_capability_id' => $flowRoutePoint->flow_route_capability_id,
            'source_version' => $flowRoutePoint->source_version,
            'meta' => $flowRoutePoint->meta ?? [],
        ];
    }
}
