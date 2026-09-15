<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Models\ContactTag;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactStatusAndTagManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_show_exposes_status_and_tag_management_controls(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'workflow',
        ])));

        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'Webinar Attended',
        ]);

        ContactTag::query()->create([
            'contact_id' => Contact::factory()->create()->getKey(),
            'tag' => 'Webinar Missed',
        ]);

        $response = $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact));

        $response
            ->assertOk()
            ->assertSee('data-contact-status-form', false)
            ->assertSee('No status')
            ->assertSee('Prospect')
            ->assertSee('data-contact-tags', false)
            ->assertSee('data-contact-tag-add-form', false)
            ->assertSee('data-contact-tag-edit-form', false)
            ->assertSee('data-contact-tag-remove-form', false)
            ->assertSee('Webinar Attended')
            ->assertSee('Webinar Missed');
    }

    public function test_contact_status_can_be_assigned_and_removed_from_contact_show(): void
    {
        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'workflow',
        ])));

        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $status = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.status.update', $contact), [
                'contact_status_id' => $status->getKey(),
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseHas('contact_workflow_profiles', [
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.status.update', $contact), [
                'contact_status_id' => '',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $profile = ContactWorkflowProfile::query()
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertNull($profile->contact_status_id);
        $this->assertSame(
            $status->getKey(),
            data_get($profile->meta, 'last_status_change.from_contact_status_id'),
        );
        $this->assertNull(
            data_get($profile->meta, 'last_status_change.to_contact_status_id'),
        );
        $this->assertSame(
            'crm_manual_status_clear',
            data_get($profile->meta, 'last_status_change.reason'),
        );
    }

    public function test_contact_tags_can_be_added_edited_and_removed(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this
            ->actingAs($user)
            ->post(route('crm.contacts.tags.store', $contact), [
                'contact_tag_context' => 'add',
                'tag' => '  Webinar Registered  ',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $contactTag = ContactTag::query()
            ->where('contact_id', $contact->getKey())
            ->firstOrFail();

        $this->assertSame('Webinar Registered', $contactTag->tag);

        $this
            ->actingAs($user)
            ->post(route('crm.contacts.tags.store', $contact), [
                'contact_tag_context' => 'add',
                'tag' => 'Webinar Registered',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertSame(
            1,
            ContactTag::query()
                ->where('contact_id', $contact->getKey())
                ->where('tag', 'Webinar Registered')
                ->count(),
        );

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.tags.update', [$contact, $contactTag]), [
                'contact_tag_context' => 'edit',
                'contact_tag_id' => $contactTag->getKey(),
                'tag' => 'Webinar Attended',
            ])
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseHas('contact_tags', [
            'id' => $contactTag->getKey(),
            'contact_id' => $contact->getKey(),
            'tag' => 'Webinar Attended',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('crm.contacts.tags.destroy', [$contact, $contactTag]))
            ->assertRedirect(route('crm.contacts.show', $contact));

        $this->assertDatabaseMissing('contact_tags', [
            'id' => $contactTag->getKey(),
        ]);
    }

    public function test_contact_cannot_edit_or_remove_another_contacts_tag(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $otherTag = ContactTag::query()->create([
            'contact_id' => $otherContact->getKey(),
            'tag' => 'Other Contact Tag',
        ]);

        $this
            ->actingAs($user)
            ->patch(route('crm.contacts.tags.update', [$contact, $otherTag]), [
                'contact_tag_context' => 'edit',
                'contact_tag_id' => $otherTag->getKey(),
                'tag' => 'Changed',
            ])
            ->assertNotFound();

        $this
            ->actingAs($user)
            ->delete(route('crm.contacts.tags.destroy', [$contact, $otherTag]))
            ->assertNotFound();

        $this->assertDatabaseHas('contact_tags', [
            'id' => $otherTag->getKey(),
            'contact_id' => $otherContact->getKey(),
            'tag' => 'Other Contact Tag',
        ]);
    }
}