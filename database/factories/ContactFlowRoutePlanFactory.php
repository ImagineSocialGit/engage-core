<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactFlowRoutePlan>
 */
class ContactFlowRoutePlanFactory extends Factory
{
    protected $model = ContactFlowRoutePlan::class;

    public function definition(): array
    {
        $progress = ContactFlowRouteProgress::factory()->create();

        return [
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_id' => $progress->contact_id,
            'subject_type' => $progress->subject_type,
            'subject_id' => $progress->subject_id,
            'flow_route_id' => $progress->flow_route_id,
            'status' => ContactFlowRoutePlan::STATUS_ACTIVE,
            'source' => ContactFlowRoutePlan::SOURCE_TEMPLATE,
            'flow_route_version' => 1,
            'snapshot_at' => now(),
            'started_at' => now(),
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'route_snapshot' => [],
            'meta' => [],
        ];
    }
}
