<?php

namespace Database\Factories;

use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ContactFlowRouteProgressItem>
 */
class ContactFlowRouteProgressItemFactory extends Factory
{
    protected $model = ContactFlowRouteProgressItem::class;

    public function definition(): array
    {
        $planItem = ContactFlowRoutePlanItem::factory()->create();

        return [
            'contact_flow_route_progress_id' => $planItem->contact_flow_route_progress_id,
            'contact_flow_route_plan_id' => $planItem->contact_flow_route_plan_id,
            'contact_flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_id' => $planItem->flow_route_id,
            'flow_route_point_id' => $planItem->flow_route_point_id,
            'flow_route_capability_id' => $planItem->flow_route_capability_id,
            'created_subject_type' => null,
            'created_subject_id' => null,
            'key' => $planItem->key,
            'point_type' => $planItem->point_type ?? FlowRoutePointType::Noop->value,
            'sequence' => $planItem->sequence,
            'attempt' => 1,
            'status' => ContactFlowRouteProgressItem::STATUS_STARTED,
            'result_reason' => null,
            'started_at' => now(),
            'completed_at' => null,
            'skipped_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'correlation_key' => null,
            'correlation_type' => null,
            'correlation' => [],
            'result_payload' => [],
            'meta' => [],
        ];
    }

    public function createdSubject(Model $subject): static
    {
        return $this->state(fn (): array => [
            'created_subject_type' => $subject->getMorphClass(),
            'created_subject_id' => $subject->getKey(),
        ]);
    }

    public function waiting(?string $eventKey = null): static
    {
        return $this->state(fn (): array => [
            'status' => ContactFlowRouteProgressItem::STATUS_WAITING,
            'waiting_event_key' => $eventKey,
        ]);
    }
}
