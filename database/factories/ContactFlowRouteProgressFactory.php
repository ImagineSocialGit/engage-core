<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<ContactFlowRouteProgress>
 */
class ContactFlowRouteProgressFactory extends Factory
{
    protected $model = ContactFlowRouteProgress::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'contact_status_id' => null,
            'contact_workflow_profile_id' => null,
            'flow_route_id' => FlowRoute::factory(),
            'current_flow_route_point_id' => null,
            'status' => ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => null,
            'waiting_event_key' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'meta' => [],
        ];
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function waiting(?string $eventKey = null): static
    {
        return $this->state(fn (): array => [
            'status' => ContactFlowRouteProgress::STATUS_WAITING,
            'waiting_event_key' => $eventKey,
        ]);
    }
}
