<?php

namespace Tests\Feature\FlowRoutes;

use App\Modules\FlowRoutes\Actions\ResumeFlowRouteProgressFromEventAction;
use App\Modules\FlowRoutes\Actions\StartFlowRoutesFromAutomationEventAction;
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
        $start = Mockery::mock(StartFlowRoutesFromAutomationEventAction::class);
        $start->shouldReceive('handle')->once();

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
            subjectId: 99,
            occurredAt: now(),
            payload: [],
            meta: ['source_module' => 'webinars'],
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