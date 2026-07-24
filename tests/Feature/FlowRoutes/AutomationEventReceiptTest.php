<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEvent;
use App\Modules\FlowRoutes\Data\Events\FlowRouteExternalEventResumeResult;
use App\Modules\FlowRoutes\Listeners\ResumeFlowRoutesFromAutomationEvent;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AutomationEventReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaying_the_same_durable_event_runs_flow_routes_once(): void
    {
        $eventId = (string) Str::uuid();
        $occurredAt = now();
        $payload = [
            'webinar_registration' => [
                'id' => 99,
                'contact' => [
                    'id' => 42,
                    'first_name' => 'Jeff',
                ],
            ],
        ];
        $meta = [
            'source_module' => 'webinars',
            'provider' => [
                'name' => 'zoom',
                'event' => 'participant_joined',
            ],
        ];
        $assertEnvelope = function (FlowRouteExternalEvent $externalEvent) use (
            $eventId,
            $occurredAt,
            $payload,
            $meta,
        ): bool {
            $this->assertSame($eventId, $externalEvent->eventId);
            $this->assertSame('webinar.attended', $externalEvent->name);
            $this->assertSame(42, $externalEvent->contactId);
            $this->assertSame('webinar_registration', $externalEvent->subjectType);
            $this->assertSame(99, $externalEvent->subjectId);
            $this->assertEquals($payload, $externalEvent->payload);
            $this->assertEquals($meta, $externalEvent->meta);
            $this->assertArrayNotHasKey('automation_event', $externalEvent->payload);
            $this->assertArrayNotHasKey('automation_event_meta', $externalEvent->payload);
            $this->assertEquals([
                'name' => 'webinar.attended',
                'event_id' => $eventId,
                'contact_id' => 42,
                'subject_type' => 'webinar_registration',
                'subject_id' => 99,
                'occurred_at' => $occurredAt->toISOString(),
            ], $externalEvent->persistenceReference());

            return true;
        };

        $start = Mockery::mock(StartFlowRoutesFromAutomationEventAction::class);
        $start->shouldReceive('handle')
            ->once()
            ->withArgs($assertEnvelope);

        $resume = Mockery::mock(ResumeFlowRouteProgressFromEventAction::class);
        $resume->shouldReceive('handle')
            ->once()
            ->withArgs($assertEnvelope)
            ->andReturn(new FlowRouteExternalEventResumeResult());

        app()->instance(StartFlowRoutesFromAutomationEventAction::class, $start);
        app()->instance(ResumeFlowRouteProgressFromEventAction::class, $resume);

        $event = new AutomationEventRecorded(new AutomationEventData(
            eventKey: 'webinar.attended',
            contactId: 42,
            subjectType: 'webinar_registration',
            subjectId: 99,
            occurredAt: $occurredAt,
            payload: $payload,
            meta: $meta,
            eventId: $eventId,
        ));

        $listener = app(ResumeFlowRoutesFromAutomationEvent::class);

        $listener->handle($event);
        $listener->handle($event);

        $this->assertDatabaseHas('automation_event_consumer_receipts', [
            'event_id' => $eventId,
            'consumer' => ResumeFlowRoutesFromAutomationEvent::CONSUMER,
        ]);
        $this->assertDatabaseCount('automation_event_consumer_receipts', 1);
    }

    public function test_failed_flow_routes_effect_rolls_back_its_receipt_and_can_be_retried(): void
    {
        $calls = 0;
        $start = Mockery::mock(StartFlowRoutesFromAutomationEventAction::class);
        $start->shouldReceive('handle')
            ->twice()
            ->andReturnUsing(function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new \RuntimeException('Simulated FlowRoutes failure.');
                }
            });

        $resume = Mockery::mock(ResumeFlowRouteProgressFromEventAction::class);
        $resume->shouldReceive('handle')
            ->once()
            ->andReturn(new FlowRouteExternalEventResumeResult());

        app()->instance(StartFlowRoutesFromAutomationEventAction::class, $start);
        app()->instance(ResumeFlowRouteProgressFromEventAction::class, $resume);

        $eventId = (string) Str::uuid();
        $event = new AutomationEventRecorded(new AutomationEventData(
            eventKey: 'webinar.attended',
            contactId: 42,
            subjectType: 'webinar_registration',
            subjectId: 100,
            occurredAt: now(),
            eventId: $eventId,
        ));

        $listener = app(ResumeFlowRoutesFromAutomationEvent::class);

        try {
            $listener->handle($event);
            $this->fail('The first FlowRoutes attempt should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated FlowRoutes failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('automation_event_consumer_receipts', [
            'event_id' => $eventId,
            'consumer' => ResumeFlowRoutesFromAutomationEvent::CONSUMER,
        ]);

        $listener->handle($event);

        $this->assertDatabaseHas('automation_event_consumer_receipts', [
            'event_id' => $eventId,
            'consumer' => ResumeFlowRoutesFromAutomationEvent::CONSUMER,
        ]);
    }
}