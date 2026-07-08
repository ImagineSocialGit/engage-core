<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Models\ContactFlowRoutePlan;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\Point;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactFlowRoutePlanItem>
 */
class ContactFlowRoutePlanItemFactory extends Factory
{
    protected $model = ContactFlowRoutePlanItem::class;

    public function definition(): array
    {
        $plan = ContactFlowRoutePlan::factory()->create();

        return [
            'contact_flow_route_progress_id' => $plan->contact_flow_route_progress_id,
            'contact_flow_route_plan_id' => $plan->getKey(),
            'flow_route_id' => $plan->flow_route_id,
            'flow_route_point_id' => null,
            'point_id' => null,
            'flow_route_capability_id' => null,
            'key' => 'plan-item-'.fake()->unique()->bothify('########'),
            'point_type' => Point::TYPE_NOOP,
            'sort_order' => 0,
            'sequence' => 0,
            'attempt' => 1,
            'source' => ContactFlowRoutePlanItem::SOURCE_TEMPLATE,
            'status' => ContactFlowRoutePlanItem::STATUS_PENDING,
            'result_reason' => null,
            'available_at' => null,
            'started_at' => null,
            'completed_at' => null,
            'skipped_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'definition_snapshot' => [],
            'settings_snapshot' => [],
            'cancel_conditions_snapshot' => [],
            'correlation' => [],
            'result_payload' => [],
            'meta' => [],
        ];
    }

    public function waiting(?string $eventKey = null): static
    {
        return $this->state(fn (): array => [
            'status' => ContactFlowRoutePlanItem::STATUS_WAITING,
            'waiting_event_key' => $eventKey,
        ]);
    }
}
