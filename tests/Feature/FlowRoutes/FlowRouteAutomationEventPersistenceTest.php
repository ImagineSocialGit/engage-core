<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Actions\CreateContactFlowRoutePlanAction;
use App\Modules\FlowRoutes\Actions\ExecuteCurrentFlowRoutePointAction;
use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Data\Points\PointExecutionResult;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\FlowRoutes\Models\FlowRouteTriggerBinding;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlowRouteAutomationEventPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private const MAX_PERSISTED_JSON_BYTES = 16384;

    public function test_external_event_persistence_references_are_scalar_only(): void
    {
        $occurredAt = CarbonImmutable::parse('2026-07-24 18:00:00 UTC');
        $eventId = (string) Str::uuid();
        $event = FlowRouteExternalEvent::make(
            name: 'webinar.attended',
            contactId: 42,
            subjectType: 'webinar_registration',
            subjectId: 99,
            occurredAt: $occurredAt,
            payload: [
                'webinar_registration' => [
                    'id' => 99,
                    'contact' => [
                        'id' => 42,
                    ],
                ],
            ],
            meta: [
                'source_module' => 'webinars',
                'provider' => [
                    'name' => 'zoom',
                ],
            ],
            eventId: $eventId,
        );

        $reference = $event->persistenceReference();

        $this->assertEquals([
            'name' => 'webinar.attended',
            'event_id' => $eventId,
            'contact_id' => 42,
            'subject_type' => 'webinar_registration',
            'subject_id' => 99,
            'occurred_at' => $occurredAt->toISOString(),
        ], $reference);
        $this->assertArrayNotHasKey('payload', $reference);
        $this->assertArrayNotHasKey('meta', $reference);

        foreach ($reference as $value) {
            $this->assertTrue(is_int($value) || is_string($value));
        }

        $this->assertLessThanOrEqual(
            FlowRouteExternalEvent::MAX_PERSISTENCE_REFERENCE_BYTES,
            strlen(json_encode($reference, JSON_THROW_ON_ERROR)),
        );

        $nonDurableReference = FlowRouteExternalEvent::make(
            name: 'task.completed',
            payload: [
                'task' => [
                    'id' => 7,
                ],
            ],
            meta: [
                'source_module' => 'tasks',
            ],
        )->persistenceReference();

        $this->assertEquals([
            'name' => 'task.completed',
        ], $nonDurableReference);
        $this->assertArrayNotHasKey('event_id', $nonDurableReference);
        $this->assertArrayNotHasKey('payload', $nonDurableReference);
        $this->assertArrayNotHasKey('meta', $nonDurableReference);
    }

    public function test_external_event_rejects_an_oversized_persistence_reference(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The FlowRoutes automation-event persistence reference exceeds its encoded-size limit.',
        );

        FlowRouteExternalEvent::make(
            name: str_repeat('oversized-event-key', 100),
        )->persistenceReference();
    }

    public function test_durable_outbox_event_is_the_only_owner_of_the_full_start_event_graph(): void
    {
        $contact = Contact::factory()->create();
        $flowRoute = FlowRoute::factory()
            ->forAutomationEvent('webinar.attended')
            ->create();

        FlowRoutePoint::factory()
            ->start()
            ->type(FlowRoutePointType::Noop)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
            ]);

        FlowRouteTriggerBinding::factory()->create([
            'trigger_type' => FlowRoute::TRIGGER_AUTOMATION_EVENT,
            'trigger_key' => 'webinar.attended',
            'flow_route_id' => $flowRoute->getKey(),
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
        ]);

        $eventId = (string) Str::uuid();
        $occurredAt = CarbonImmutable::parse('2026-07-24 18:15:00 UTC');
        $graphMarker = 'FULL-EVENT-GRAPH-START';
        $payload = [
            'webinar_registration' => [
                'id' => 321,
                'contact' => [
                    'id' => $contact->getKey(),
                    'first_name' => 'Persistence',
                ],
                'graph' => str_repeat($graphMarker, 4096),
            ],
        ];
        $meta = [
            'source_module' => 'webinars',
            'provider' => [
                'name' => 'zoom',
                'raw' => str_repeat($graphMarker, 512),
            ],
        ];

        $outboxEvent = app(AutomationEventOutbox::class)->record(
            new AutomationEventData(
                eventKey: 'webinar.attended',
                contactId: $contact->getKey(),
                subjectType: 'webinar_registration',
                subjectId: 321,
                occurredAt: $occurredAt,
                payload: $payload,
                meta: $meta,
                eventId: $eventId,
            ),
            idempotencyKey: 'flow-routes-persistence-start-'.$eventId,
        );

        app(AutomationEventOutbox::class)->publish($outboxEvent->getKey());

        $outboxEvent->refresh();
        $this->assertSame('published', $outboxEvent->status);
        $this->assertEquals($payload, $outboxEvent->payload);
        $this->assertEquals($meta, $outboxEvent->meta);

        $progress = ContactFlowRouteProgress::query()
            ->where('contact_id', $contact->getKey())
            ->where('flow_route_id', $flowRoute->getKey())
            ->firstOrFail();

        $this->assertEquals([
            'name' => 'webinar.attended',
            'event_id' => $eventId,
            'contact_id' => $contact->getKey(),
            'subject_type' => 'webinar_registration',
            'subject_id' => 321,
            'occurred_at' => $occurredAt->toISOString(),
        ], data_get($progress->meta, 'started_from_automation_event'));

        $this->assertFlowRoutePersistenceIsBounded(
            progress: $progress,
            graphMarker: $graphMarker,
        );
    }

    public function test_event_resume_uses_in_memory_payload_and_meta_but_persists_only_a_compact_match_reference(): void
    {
        $contact = Contact::factory()->create();
        $flowRoute = FlowRoute::factory()->create();
        $eventWaitPoint = FlowRoutePoint::factory()
            ->start()
            ->type(FlowRoutePointType::EventWait)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'definition' => [
                    'event_key' => 'webinar.attended',
                    'correlation' => [
                        'payload.webinar_registration.id' => 654,
                        'meta.source_module' => 'webinars',
                    ],
                ],
            ]);
        $noopPoint = FlowRoutePoint::factory()
            ->type(FlowRoutePointType::Noop)
            ->create([
                'flow_route_id' => $flowRoute->getKey(),
                'sort_order' => 10,
            ]);

        $eventWaitPoint->forceFill([
            'next_flow_route_point_id' => $noopPoint->getKey(),
        ])->save();

        $progress = ContactFlowRouteProgress::factory()->create([
            'contact_id' => $contact->getKey(),
            'subject_type' => 'webinar_registration',
            'subject_id' => 654,
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $eventWaitPoint->getKey(),
        ]);

        app(CreateContactFlowRoutePlanAction::class)->handle($progress, $flowRoute);

        $waitingResult = app(ExecuteCurrentFlowRoutePointAction::class)
            ->handle($progress->refresh());

        $this->assertSame(PointExecutionResult::STATUS_WAITING, $waitingResult->status);

        $eventId = (string) Str::uuid();
        $occurredAt = CarbonImmutable::parse('2026-07-24 18:30:00 UTC');
        $graphMarker = 'FULL-EVENT-GRAPH-RESUME';
        $externalEvent = FlowRouteExternalEvent::make(
            name: 'webinar.attended',
            contactId: $contact->getKey(),
            subjectType: 'webinar_registration',
            subjectId: 654,
            occurredAt: $occurredAt,
            payload: [
                'webinar_registration' => [
                    'id' => 654,
                    'contact' => [
                        'id' => $contact->getKey(),
                    ],
                    'graph' => str_repeat($graphMarker, 4096),
                ],
            ],
            meta: [
                'source_module' => 'webinars',
                'provider' => [
                    'name' => 'zoom',
                    'raw' => str_repeat($graphMarker, 512),
                ],
            ],
            eventId: $eventId,
        );

        $resumeResult = app(ResumeFlowRouteProgressFromEventAction::class)
            ->handle($externalEvent);

        $this->assertSame(1, $resumeResult->matched);
        $this->assertSame(1, $resumeResult->resumed);

        $progress->refresh();
        $this->assertSame(ContactFlowRouteProgress::STATUS_COMPLETED, $progress->status);
        $this->assertArrayNotHasKey('event_resume_attempts', $progress->meta ?? []);
        $this->assertArrayNotHasKey('last_event_resume_attempt', $progress->meta ?? []);

        $waitingProgressItem = ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('flow_route_point_id', $eventWaitPoint->getKey())
            ->oldest('id')
            ->firstOrFail();

        $eventReference = [
            'name' => 'webinar.attended',
            'event_id' => $eventId,
            'contact_id' => $contact->getKey(),
            'subject_type' => 'webinar_registration',
            'subject_id' => 654,
            'occurred_at' => $occurredAt->toISOString(),
        ];

        $this->assertEquals(
            $eventReference,
            data_get($waitingProgressItem->result_payload, 'matched_event'),
        );
        $this->assertArrayNotHasKey(
            'payload',
            data_get($waitingProgressItem->result_payload, 'matched_event', []),
        );
        $this->assertArrayNotHasKey(
            'meta',
            data_get($waitingProgressItem->result_payload, 'matched_event', []),
        );

        $this->assertFlowRoutePersistenceIsBounded(
            progress: $progress,
            graphMarker: $graphMarker,
        );
    }

    private function assertFlowRoutePersistenceIsBounded(
        ContactFlowRouteProgress $progress,
        string $graphMarker,
    ): void {
        $values = [
            'progress.meta' => $progress->meta ?? [],
        ];

        foreach (ContactFlowRoutePlanItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->get() as $planItem
        ) {
            $values["plan_item.{$planItem->getKey()}.result_payload"] = $planItem->result_payload ?? [];
            $values["plan_item.{$planItem->getKey()}.meta"] = $planItem->meta ?? [];
        }

        foreach (ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->get() as $progressItem
        ) {
            $values["progress_item.{$progressItem->getKey()}.result_payload"] = $progressItem->result_payload ?? [];
            $values["progress_item.{$progressItem->getKey()}.meta"] = $progressItem->meta ?? [];
        }

        foreach ($values as $path => $value) {
            $json = json_encode($value, JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($graphMarker, $json, $path);
            $this->assertStringNotContainsString('"automation_event":', $json, $path);
            $this->assertStringNotContainsString('"automation_event_meta":', $json, $path);
            $this->assertLessThanOrEqual(
                self::MAX_PERSISTED_JSON_BYTES,
                strlen($json),
                "{$path} exceeds the persisted FlowRoutes event-state budget.",
            );
        }
    }
}