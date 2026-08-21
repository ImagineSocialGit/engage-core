<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlowRouteAutomationEventExecutionContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_start_event_payload_is_branchable_in_memory_without_becoming_flow_route_persistence(): void
    {
        $contact = Contact::factory()->create();
        $flowRoute = FlowRoute::factory()
            ->forAutomationEvent('inbound_message.normal_reply')
            ->create();

        $branch = FlowRoutePoint::factory()
            ->start()
            ->type(FlowRoutePointType::BranchEvaluate)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'key' => 'reply_outcome',
                'definition' => [
                    'branches' => [
                        [
                            'conditions' => [
                                [
                                    'source' => 'execution_meta',
                                    'path' => 'automation_event.payload.inbound_message.reply_profile_key',
                                    'operator' => 'equals',
                                    'value' => 'cold_lead_nurture',
                                ],
                                [
                                    'source' => 'execution_meta',
                                    'path' => 'automation_event.payload.inbound_message.reply_intent_key',
                                    'operator' => 'equals',
                                    'value' => 'yes',
                                ],
                            ],
                            'target_flow_route_point_key' => 'yes_target',
                        ],
                    ],
                    'on_no_match' => 'blocked',
                ],
            ]);

        FlowRoutePoint::factory()
            ->type(FlowRoutePointType::Noop)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'key' => 'no_match_target',
                'sort_order' => 10,
            ]);

        $yesTarget = FlowRoutePoint::factory()
            ->type(FlowRoutePointType::Noop)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'key' => 'yes_target',
                'sort_order' => 20,
            ]);

        FlowRouteTriggerBinding::factory()
            ->forAutomationEvent('inbound_message.normal_reply')
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'context_type' => null,
                'context_id' => null,
                'is_active' => true,
            ]);

        $graphMarker = 'TRANSIENT-INBOUND-REPLY-EVENT-GRAPH';
        $occurredAt = CarbonImmutable::parse('2026-08-21 03:00:00 UTC');

        app(StartFlowRoutesFromAutomationEventAction::class)->handle(
            FlowRouteExternalEvent::make(
                name: 'inbound_message.normal_reply',
                contactId: $contact->getKey(),
                subjectType: 'inbound_message',
                subjectId: 501,
                occurredAt: $occurredAt,
                payload: [
                    'inbound_message' => [
                        'id' => 501,
                        'reply_profile_key' => 'cold_lead_nurture',
                        'reply_intent_key' => 'yes',
                        'graph' => str_repeat($graphMarker, 1024),
                    ],
                ],
                meta: [
                    'source_module' => 'inbound_messaging',
                    'graph' => str_repeat($graphMarker, 256),
                ],
                eventId: 'event-reply-501',
            ),
        );

        $progress = ContactFlowRouteProgress::query()->sole();

        $branchAttempt = ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('flow_route_point_id', $branch->getKey())
            ->sole();

        $this->assertSame('branch_matched', $branchAttempt->result_reason);
        $this->assertSame(
            $yesTarget->getKey(),
            data_get($branchAttempt->result_payload, 'meta.advance_to_flow_route_point_id'),
        );

        $this->assertEquals([
            'name' => 'inbound_message.normal_reply',
            'event_id' => 'event-reply-501',
            'contact_id' => $contact->getKey(),
            'subject_type' => 'inbound_message',
            'subject_id' => 501,
            'occurred_at' => $occurredAt->toISOString(),
        ], data_get($progress->meta, 'started_from_automation_event'));

        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->status);

        foreach ([
            $progress->meta,
            $branchAttempt->result_payload,
            $branchAttempt->meta,
        ] as $persisted) {
            $json = json_encode($persisted, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($graphMarker, $json);
            $this->assertStringNotContainsString('"automation_event":', $json);
        }
    }
}