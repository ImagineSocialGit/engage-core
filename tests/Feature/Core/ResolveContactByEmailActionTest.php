<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveContactByEmailActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_contact_receives_the_configured_default_status_when_workflow_is_available(): void
    {
        config()->set('contacts.default_contact_status_key', 'inquiry_new');

        $status = ContactStatus::query()->create([
            'key' => 'inquiry_new',
            'name' => 'New Inquiry',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $contact = app(ResolveContactByEmailAction::class)->handle(
            email: '  NEW.PERSON@example.test  ',
            name: 'New Person',
            phone: '555-0100',
            source: 'website',
            subsource: 'consultation_request',
        );

        $profile = ContactWorkflowProfile::query()
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertSame('new.person@example.test', $contact->email);
        $this->assertSame($status->getKey(), $profile->contact_status_id);
        $this->assertSame(
            'contact_email_resolution_create',
            data_get($profile->meta, 'last_status_change.reason'),
        );
    }

    public function test_existing_contact_is_reused_without_overwriting_its_current_status_or_contact_data(): void
    {
        config()->set('contacts.default_contact_status_key', 'inquiry_new');

        ContactStatus::query()->create([
            'key' => 'inquiry_new',
            'name' => 'New Inquiry',
            'is_active' => true,
            'sort_order' => 10,
        ]);
        $active = ContactStatus::query()->create([
            'key' => 'active_client',
            'name' => 'Active Client',
            'is_active' => true,
            'sort_order' => 20,
        ]);
        $existing = Contact::query()->create([
            'name' => 'Existing Person',
            'email' => 'existing@example.test',
            'phone' => '555-0101',
            'source' => 'legacy_crm',
            'subsource' => 'migration',
        ]);
        ContactWorkflowProfile::query()->create([
            'contact_id' => $existing->getKey(),
            'contact_status_id' => $active->getKey(),
        ]);

        $resolved = app(ResolveContactByEmailAction::class)->handle(
            email: 'EXISTING@example.test',
            name: 'Replacement Name',
            phone: '555-9999',
            source: 'website',
            subsource: 'consultation_request',
        );

        $profile = ContactWorkflowProfile::query()
            ->where('contact_id', $existing->getKey())
            ->sole();

        $this->assertSame($existing->getKey(), $resolved->getKey());
        $this->assertSame('Existing Person', $resolved->name);
        $this->assertSame('555-0101', $resolved->phone);
        $this->assertSame('legacy_crm', $resolved->source);
        $this->assertSame('migration', $resolved->subsource);
        $this->assertSame($active->getKey(), $profile->contact_status_id);
    }

    public function test_missing_configured_default_status_does_not_create_a_workflow_profile(): void
    {
        config()->set('contacts.default_contact_status_key', 'missing_status');

        $contact = app(ResolveContactByEmailAction::class)->handle(
            email: 'no-status@example.test',
            source: 'website',
            subsource: 'consultation_request',
        );

        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'contact_id' => $contact->getKey(),
        ]);
    }
}