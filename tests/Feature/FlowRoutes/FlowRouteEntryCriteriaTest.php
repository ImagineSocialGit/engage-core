<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Modules\FlowRoutes\Services\FlowRoutePresetDefinitionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteEntryCriteriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_factory_normalizes_route_level_entry_conditions(): void
    {
        $conditions = [
            [
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                'operator' => 'in',
                'values' => ['past_client_nurture', 'cold_lead_nurture'],
            ],
            [
                'source' => 'execution_meta',
                'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                'operator' => 'equals',
                'value' => 'high_intent',
            ],
        ];

        $definition = app(FlowRoutePresetDefinitionFactory::class)->fromArray(
            presetKey: 'test',
            definitionKey: 'reply_follow_up',
            data: [
                'event_key' => 'inbound_message.normal_reply',
                'entry_conditions' => $conditions,
                'name' => 'Reply Follow-Up',
                'points' => [
                    'start' => ['type' => 'noop'],
                ],
            ],
        );

        $this->assertEqualsCanonicalizing(
            $conditions,
            $definition->meta['entry_conditions'],
        );
    }

    public function test_only_routes_whose_entry_criteria_match_create_progress(): void
    {
        $contact = Contact::factory()->create();
        $matchingRoute = $this->replyRoute(
            key: 'matching_reply_route',
            replyProfileKey: 'cold_lead_nurture',
        );
        $nonMatchingRoute = $this->replyRoute(
            key: 'non_matching_reply_route',
            replyProfileKey: 'past_client_nurture',
        );

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: 'inbound_message.normal_reply',
                contactId: $contact->getKey(),
                subjectType: 'inbound_message',
                subjectId: 501,
                payload: [
                    'inbound_message' => [
                        'reply_profile_key' => 'cold_lead_nurture',
                        'reply_intent_key' => 'high_intent',
                    ],
                ],
            ),
        );

        $this->assertDatabaseHas('contact_flow_route_progress', [
            'contact_id' => $contact->getKey(),
            'flow_route_id' => $matchingRoute->getKey(),
            'status' => ContactFlowRouteProgress::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseMissing('contact_flow_route_progress', [
            'contact_id' => $contact->getKey(),
            'flow_route_id' => $nonMatchingRoute->getKey(),
        ]);
        $this->assertSame(1, ContactFlowRouteProgress::query()->count());
    }

    private function replyRoute(string $key, string $replyProfileKey): FlowRoute
    {
        $route = FlowRoute::factory()
            ->forAutomationEvent('inbound_message.normal_reply')
            ->create([
                'key' => $key,
                'meta' => [
                    'definition' => [
                        'entry_conditions' => [
                            [
                                'source' => 'execution_meta',
                                'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                                'operator' => 'equals',
                                'value' => $replyProfileKey,
                            ],
                            [
                                'source' => 'execution_meta',
                                'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                                'operator' => 'equals',
                                'value' => 'high_intent',
                            ],
                        ],
                    ],
                ],
            ]);

        FlowRoutePoint::factory()
            ->start()
            ->type(FlowRoutePointType::Noop)
            ->create([
                'flow_route_id' => $route->getKey(),
            ]);

        FlowRouteTriggerBinding::factory()
            ->forAutomationEvent('inbound_message.normal_reply')
            ->create([
                'flow_route_id' => $route->getKey(),
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
            ]);

        return $route;
    }
}