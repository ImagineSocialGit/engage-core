<?php

namespace Tests\Feature\CRM;

use App\Modules\Core\Actions\Contacts\UpdateContactStatusAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateContactStatusActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_workflow_profile_when_contact_has_no_status_profile(): void
    {
        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create();

        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'contact_id' => $contact->id,
        ]);

        $updatedContact = app(UpdateContactStatusAction::class)->handle(
            contact: $contact,
            status: $status,
            reason: 'test_status_change',
        );

        $updatedContact->refresh()->load('workflowProfile.contactStatus');

        $this->assertNotNull($updatedContact->workflowProfile);
        $this->assertTrue($updatedContact->workflowProfile->contactStatus->is($status));

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->id,
            'contact_status_id' => $status->id,
        ]);

        $this->assertNull($updatedContact->workflowProfile->meta['last_status_change']['from_contact_status_id']);
        $this->assertSame($status->id, $updatedContact->workflowProfile->meta['last_status_change']['to_contact_status_id']);
        $this->assertSame('test_status_change', $updatedContact->workflowProfile->meta['last_status_change']['reason']);
    }

    public function test_it_updates_existing_workflow_profile_status(): void
    {
        $prospect = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $inProcess = ContactStatus::query()->create([
            'key' => 'in_process',
            'name' => 'In Process',
            'sort_order' => 2,
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create();

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->id,
            'contact_status_id' => $prospect->id,
            'last_status_changed_at' => now()->subDay(),
            'meta' => [
                'existing' => true,
            ],
        ]);

        $updatedContact = app(UpdateContactStatusAction::class)->handle(
            contact: $contact,
            status: $inProcess,
            reason: 'test_status_change',
        );

        $updatedContact->refresh()->load('workflowProfile.contactStatus');

        $this->assertTrue($updatedContact->workflowProfile->contactStatus->is($inProcess));
        $this->assertSame($prospect->id, $updatedContact->workflowProfile->meta['last_status_change']['from_contact_status_id']);
        $this->assertSame($inProcess->id, $updatedContact->workflowProfile->meta['last_status_change']['to_contact_status_id']);
        $this->assertSame('test_status_change', $updatedContact->workflowProfile->meta['last_status_change']['reason']);
        $this->assertTrue($updatedContact->workflowProfile->meta['existing']);
    }

    public function test_it_does_nothing_when_status_is_unchanged(): void
    {
        $prospect = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $contact = Contact::factory()->create();

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->id,
            'contact_status_id' => $prospect->id,
            'last_status_changed_at' => now()->subDay(),
            'meta' => [
                'existing' => true,
            ],
        ]);

        app(UpdateContactStatusAction::class)->handle(
            contact: $contact,
            status: $prospect,
            reason: 'same_status',
        );

        $contact->refresh()->load('workflowProfile.contactStatus');

        $this->assertTrue($contact->workflowProfile->contactStatus->is($prospect));
        $this->assertSame(['existing' => true], $contact->workflowProfile->meta);
    }
}