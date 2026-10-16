<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
    use RefreshDatabase;


    public function test_it_creates_contact_from_manual_form(): void
    {
        $user = User::factory()->create();

        ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'last_name' => 'Lead',
                'name' => 'Jane Lead',
                'email' => 'jane@example.test',
                'phone' => '5551112222',
                'existing_relationship_confirmed' => '1',
                'source' => 'manual',
                'subsource' => 'walk_in',
            ]);

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Lead', $contact->last_name);
        $this->assertSame('Jane Lead', $contact->name);
        $this->assertSame('5551112222', $contact->phone);
        $this->assertSame('manual', $contact->source);
        $this->assertSame('walk_in', $contact->subsource);
    }

    public function test_it_applies_selected_status_when_creating_contact(): void
    {
        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'in_process',
            'name' => 'In Process',
            'is_active' => true,
            'sort_order' => 20,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'last_name' => 'Lead',
                'email' => 'jane@example.test',
                'existing_relationship_confirmed' => '1',
                'contact_status_id' => $status->id,
            ]);

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->id,
            'contact_status_id' => $status->id,
        ]);

        $profile = ContactWorkflowProfile::query()
            ->where('contact_id', $contact->id)
            ->firstOrFail();

        $this->assertSame('crm_manual_create', data_get($profile->meta, 'last_status_change.reason'));
    }

    public function test_it_applies_default_contact_status_when_creating_contact_without_selected_status(): void
    {
        config()->set('contacts.default_contact_status_key', 'new');

        $user = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Jane',
                'last_name' => 'Lead',
                'email' => 'jane@example.test',
                'existing_relationship_confirmed' => '1',
            ]);

        $contact = Contact::query()->where('email', 'jane@example.test')->firstOrFail();

        $response->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->id,
            'contact_status_id' => $status->id,
        ]);
    }


    public function test_it_renders_import_preview_with_csv_mapping_fields(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        ContactStatus::query()->create([
            'key' => 'new',
            'name' => 'New',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'leads.csv',
            implode("\n", [
                'First Name,Last Name,Email,Phone,Legacy Status',
                'Jane,Lead,jane@example.test,5551112222,Fresh Lead',
            ]),
        );

        $response = $this
            ->actingAs($user)
            ->post(route('crm.contacts.import.preview'), [
                'csv' => $csv,
            ]);

        $response->assertOk();
    }
}