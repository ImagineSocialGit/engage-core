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
    public function handle(ContactFlowRouteProgress $progress, ?FlowRoute $flowRoute = null): ContactFlowRoutePlan
    {
        return DB::transaction(function () use ($progress, $flowRoute) {
            $progress = ContactFlowRouteProgress::query()
                ->lockForUpdate()
                ->with('plan')
                ->findOrFail($progress->getKey());

            if ($progress->plan instanceof ContactFlowRoutePlan) {
                return $progress->plan;
            }

            $flowRoute ??= FlowRoute::query()
                ->findOrFail($progress->flow_route_id);

            $flowRoutePoints = $flowRoute->activeFlowRoutePoints()
                ->with(['point', 'capability'])
                ->ordered()
                ->get();

            $now = Carbon::now();

            $plan = ContactFlowRoutePlan::query()->create([
                'contact_flow_route_progress_id' => $progress->getKey(),
                'contact_id' => $progress->contact_id,
                'subject_type' => $progress->subject_type,
                'subject_id' => $progress->subject_id,
                'flow_route_id' => $flowRoute->getKey(),
                'status' => ContactFlowRoutePlan::STATUS_ACTIVE,
                'source' => ContactFlowRoutePlan::SOURCE_TEMPLATE,
                'flow_route_version' => $flowRoute->version,
                'snapshot_at' => $now,
                'started_at' => $progress->started_at ?? $now,
                'route_snapshot' => $this->routeSnapshot($flowRoute),
                'meta' => [
                    'created_by' => 'flow_routes',
                    'created_from' => 'flow_route_template',
                ],
            ]);

            $sequence = 1;

            /** @var FlowRoutePoint $flowRoutePoint */
            foreach ($flowRoutePoints as $flowRoutePoint) {
                if (! $flowRoutePoint->point || ! $flowRoutePoint->point->is_active) {
                    continue;
                }

                ContactFlowRoutePlanItem::query()->create([
                    'contact_flow_route_progress_id' => $progress->getKey(),
                    'contact_flow_route_plan_id' => $plan->getKey(),
                    'flow_route_id' => $flowRoute->getKey(),
                    'flow_route_point_id' => $flowRoutePoint->getKey(),
                    'point_id' => $flowRoutePoint->point_id,
                    'flow_route_capability_id' => $flowRoutePoint->flow_route_capability_id,
                    'key' => $flowRoutePoint->key,
                    'point_type' => $flowRoutePoint->point->type,
                    'sort_order' => $flowRoutePoint->sort_order,
                    'sequence' => $sequence++,
                    'attempt' => 0,
                    'source' => ContactFlowRoutePlanItem::SOURCE_TEMPLATE,
                    'status' => ((int) $progress->current_flow_route_point_id === (int) $flowRoutePoint->getKey())
                        ? ContactFlowRoutePlanItem::STATUS_ACTIVE
                        : ContactFlowRoutePlanItem::STATUS_PENDING,
                    'definition_snapshot' => array_replace_recursive(
                        $flowRoutePoint->point->default_definition ?? [],
                        $flowRoutePoint->definition ?? [],
                    ),
                    'settings_snapshot' => array_replace_recursive(
                        $flowRoutePoint->point->default_settings ?? [],
                        $flowRoutePoint->settings ?? [],
                    ),
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
            'point_id' => $flowRoutePoint->point_id,
            'point_key' => $flowRoutePoint->point?->key,
            'point_type' => $flowRoutePoint->point?->type,
            'flow_route_capability_id' => $flowRoutePoint->flow_route_capability_id,
            'source_version' => $flowRoutePoint->source_version,
            'meta' => $flowRoutePoint->meta ?? [],
        ];
    }
}
