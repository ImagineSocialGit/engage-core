<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\ContactStatus;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\FlowRouteTriggerBindingResolver;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteTransitionSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_more_specific_transition_route_wins_and_generic_route_remains_fallback(): void
    {
        $prospectNew = $this->contactStatus('prospect_new', 'Prospect New');
        $prospectNurture = $this->contactStatus('prospect_nurture', 'Prospect Nurture');
        $engaged = $this->contactStatus('engaged', 'Engaged');

        $generic = $this->route('engaged_generic', $engaged, []);
        $specific = $this->route('engaged_from_reply', $engaged, [
            'definition' => [
                'transition' => [
                    'from_contact_status_keys' => ['prospect_nurture'],
                    'sources' => ['flow_routes'],
                    'reasons' => ['inbound_reply_high_intent'],
                ],
            ],
        ]);

        // Bind the generic Route last so it wins normal binding order. Transition
        // specificity must still select the qualified Route when it matches.
        $this->bind($specific, $engaged);
        $this->bind($generic, $engaged);

        $resolver = app(FlowRouteTriggerBindingResolver::class);

        $selected = $resolver->selectedFlowRouteForTransition($this->transition(
            from: $prospectNurture,
            to: $engaged,
            source: 'flow_routes',
            reason: 'inbound_reply_high_intent',
        ));

        $this->assertSame($specific->getKey(), $selected?->getKey());

        $fallback = $resolver->selectedFlowRouteForTransition($this->transition(
            from: $prospectNew,
            to: $engaged,
            source: 'crm',
            reason: 'manual_update',
        ));

        $this->assertSame($generic->getKey(), $fallback?->getKey());
    }

    private function contactStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
            'is_active' => true,
        ]);
    }

    /** @param array<string, mixed> $meta */
    private function route(string $key, ContactStatus $status, array $meta): FlowRoute
    {
        return FlowRoute::query()->create([
            'key' => $key,
            'contact_status_id' => $status->getKey(),
            'name' => $key,
            'version' => 1,
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'is_current_version' => true,
            'meta' => $meta,
        ]);
    }

    private function bind(FlowRoute $route, ContactStatus $status): void
    {
        FlowRouteTriggerBinding::query()->create([
            'trigger_type' => FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'flow_route_id' => $route->getKey(),
            'is_active' => true,
            'meta' => [],
        ]);
    }

    private function transition(
        ContactStatus $from,
        ContactStatus $to,
        string $source,
        string $reason,
    ): ContactWorkflowStatusTransition {
        return new ContactWorkflowStatusTransition(
            contactId: 100,
            contactWorkflowProfileId: 200,
            fromContactStatusId: (int) $from->getKey(),
            toContactStatusId: (int) $to->getKey(),
            reason: $reason,
            source: $source,
            actorType: null,
            actorId: null,
            occurredAt: CarbonImmutable::now(),
        );
    }
}