<?php

namespace Tests\Feature\Workflow;

use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Listeners\DispatchContactWorkflowStatusChangedFromAutomationEvent;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WorkflowStatusAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_transition_is_persisted_and_replayed_through_the_existing_domain_event(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();
        $status = $this->contactStatus('durable_status', 'Durable Status');

        $profile = app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'Durable transition test',
            source: 'test',
        );

        $outboxEvent = AutomationEventOutboxEvent::query()
            ->where('event_key', ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY)
            ->firstOrFail();

        if ($outboxEvent->status !== AutomationEventOutboxEvent::STATUS_PUBLISHED) {
            $this->assertTrue(
                app(AutomationEventOutbox::class)->publish((int) $outboxEvent->getKey()),
            );
        }

        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY,
            'contact_id' => $contact->getKey(),
            'subject_type' => $profile->getMorphClass(),
            'subject_id' => (string) $profile->getKey(),
        ]);
        Event::assertDispatchedTimes(ContactWorkflowStatusChanged::class, 1);
    }

    public function test_status_transition_rolls_back_when_its_outbox_record_cannot_be_written(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $outbox = Mockery::mock(AutomationEventOutbox::class);
        $outbox->shouldReceive('record')
            ->once()
            ->andThrow(new RuntimeException('Simulated workflow outbox failure.'));
        app()->instance(AutomationEventOutbox::class, $outbox);

        $contact = Contact::factory()->create();
        $originalLastActivityAt = $contact->last_activity_at;
        $status = $this->contactStatus('rolled_back_status', 'Rolled Back Status');

        try {
            app(TransitionContactWorkflowStatusAction::class)->handle(
                contact: $contact,
                toStatus: $status,
                source: 'test',
            );
            $this->fail('The workflow transition should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated workflow outbox failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'contact_id' => $contact->getKey(),
        ]);
        $this->assertEquals(
            $originalLastActivityAt?->toISOString(),
            $contact->fresh()->last_activity_at?->toISOString(),
        );
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
        Event::assertNotDispatched(ContactWorkflowStatusChanged::class);
    }

    public function test_replaying_one_workflow_envelope_dispatches_the_domain_event_once(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();
        $status = $this->contactStatus('receipt_status', 'Receipt Status');
        $profile = ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
            'last_status_changed_at' => now(),
            'meta' => [],
        ]);
        $eventId = (string) Str::uuid();
        $occurredAt = now();

        $recorded = new AutomationEventRecorded(new AutomationEventData(
            eventKey: ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY,
            contactId: $contact->getKey(),
            subjectType: $profile->getMorphClass(),
            subjectId: $profile->getKey(),
            occurredAt: $occurredAt,
            payload: [
                'workflow_transition' => [
                    'contact_id' => $contact->getKey(),
                    'contact_workflow_profile_id' => $profile->getKey(),
                    'from_contact_status_id' => null,
                    'to_contact_status_id' => $status->getKey(),
                    'reason' => 'Receipt test',
                    'source' => 'test',
                    'actor_type' => null,
                    'actor_id' => null,
                    'occurred_at' => $occurredAt->toISOString(),
                    'meta' => [],
                ],
            ],
            meta: ['source_module' => 'workflow'],
            eventId: $eventId,
        ));

        $listener = app(DispatchContactWorkflowStatusChangedFromAutomationEvent::class);

        $listener->handle($recorded);
        $listener->handle($recorded);

        Event::assertDispatchedTimes(ContactWorkflowStatusChanged::class, 1);
        $this->assertDatabaseHas('automation_event_consumer_receipts', [
            'event_id' => $eventId,
            'consumer' => DispatchContactWorkflowStatusChangedFromAutomationEvent::CONSUMER,
        ]);
    }

    private function contactStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
            'is_core' => false,
            'is_active' => true,
            'sort_order' => 100,
        ]);
    }
}