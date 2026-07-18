<?php

namespace Tests\Feature\Workflow;

use App\Modules\Core\Actions\Contacts\UpdateContactStatusAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Actions\TransitionContactWorkflowStatusAction;
use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TransitionContactWorkflowStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_workflow_profile_and_emits_status_changed_event(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $profile = app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'Manual update',
            source: 'crm',
        );

        $this->assertInstanceOf(ContactWorkflowProfile::class, $profile);
        $this->assertTrue($profile->exists);
        $this->assertSame($contact->id, $profile->contact_id);
        $this->assertSame($status->id, $profile->contact_status_id);
        $this->assertNotNull($profile->last_status_changed_at);

        $this->publishWorkflowStatusChangeEvents();

        Event::assertDispatched(
            ContactWorkflowStatusChanged::class,
            fn (ContactWorkflowStatusChanged $event): bool => $event->transition->contactId === $contact->id
                && $event->transition->fromContactStatusId === null
                && $event->transition->toContactStatusId === $status->id
                && $event->transition->reason === 'Manual update'
                && $event->transition->source === 'crm',
        );
    }

    public function test_it_updates_an_existing_workflow_profile_and_records_previous_status(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();

        $oldStatus = ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $newStatus = ContactStatus::query()->create([
            'key' => 'qualified',
            'name' => 'Qualified',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 20,
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->id,
            'contact_status_id' => $oldStatus->id,
            'last_status_changed_at' => now()->subDay(),
        ]);

        $profile = app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $newStatus,
            reason: 'Qualified by intake',
            source: 'workflow_test',
        );

        $this->assertSame($newStatus->id, $profile->contact_status_id);
        $this->assertSame($oldStatus->id, $profile->meta['last_status_change']['from_contact_status_id']);
        $this->assertSame($newStatus->id, $profile->meta['last_status_change']['to_contact_status_id']);
        $this->assertSame('Qualified by intake', $profile->meta['last_status_change']['reason']);
        $this->assertSame('workflow_test', $profile->meta['last_status_change']['source']);

        $this->publishWorkflowStatusChangeEvents();

        Event::assertDispatched(
            ContactWorkflowStatusChanged::class,
            fn (ContactWorkflowStatusChanged $event): bool => $event->transition->fromContactStatusId === $oldStatus->id
                && $event->transition->toContactStatusId === $newStatus->id,
        );
    }

    public function test_it_does_not_emit_event_when_status_is_unchanged_without_force(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->id,
            'contact_status_id' => $status->id,
            'last_status_changed_at' => now()->subDay(),
        ]);

        app(TransitionContactWorkflowStatusAction::class)->handle(
            contact: $contact,
            toStatus: $status,
            reason: 'No-op',
            source: 'workflow_test',
        );

        Event::assertNotDispatched(ContactWorkflowStatusChanged::class);
    }

    public function test_core_status_update_action_delegates_to_workflow_without_core_importing_workflow_models(): void
    {
        Event::fake([ContactWorkflowStatusChanged::class]);

        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_core' => true,
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $updatedContact = app(UpdateContactStatusAction::class)->handle(
            contact: $contact,
            status: $status,
            reason: 'Manual CRM update',
            source: 'crm',
        );

        $this->assertSame($contact->id, $updatedContact->id);

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->id,
            'contact_status_id' => $status->id,
        ]);

        $this->publishWorkflowStatusChangeEvents();

        Event::assertDispatched(ContactWorkflowStatusChanged::class);
    }

    private function publishWorkflowStatusChangeEvents(): void
    {
        $outbox = app(AutomationEventOutbox::class);
        $published = 0;

        while ($event = AutomationEventOutboxEvent::query()
            ->where('event_key', ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY)
            ->where('status', AutomationEventOutboxEvent::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->orderBy('id')
            ->first()
        ) {
            $published++;

            $this->assertLessThanOrEqual(
                100,
                $published,
                'Workflow status-change outbox delivery did not settle.',
            );
            $this->assertTrue($outbox->publish((int) $event->getKey()));
        }
    }
}