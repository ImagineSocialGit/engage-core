<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_delete_route_soft_deletes_contact_without_cascading_history(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'email' => 'delete-me@example.test',
        ]);

        $tag = ContactTag::query()->create([
            'contact_id' => $contact->getKey(),
            'tag' => 'Historical Tag',
        ]);

        $this
            ->actingAs($user)
            ->delete(route('crm.contacts.destroy', $contact))
            ->assertRedirect(route('crm.contacts.index'));

        $this->assertNull(Contact::query()->find($contact->getKey()));

        $deleted = Contact::withTrashed()->findOrFail($contact->getKey());

        $this->assertTrue($deleted->trashed());
        $this->assertDatabaseHas('contact_tags', [
            'id' => $tag->getKey(),
            'contact_id' => $contact->getKey(),
            'tag' => 'Historical Tag',
        ]);
    }

    public function test_deleted_contact_identity_is_restored_instead_of_duplicated(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'returns@example.test',
            'name' => 'Original Contact',
        ]);

        $contact->delete();

        $restored = app(CreateOrUpdateContactAction::class)->handle([
            'email' => 'returns@example.test',
            'name' => 'Returning Contact',
        ]);

        $this->assertSame($contact->getKey(), $restored->getKey());
        $this->assertFalse($restored->trashed());
        $this->assertSame('Returning Contact', $restored->name);
        $this->assertSame(
            1,
            Contact::withTrashed()
                ->where('email', 'returns@example.test')
                ->count(),
        );
    }

    public function test_contact_detail_exposes_general_delete_control(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        $this
            ->actingAs($user)
            ->get(route('crm.contacts.show', $contact))
            ->assertOk()
            ->assertSee('data-contact-delete-zone', false)
            ->assertSee('data-contact-delete-modal', false)
            ->assertSee(route('crm.contacts.destroy', $contact), false);
    }

    public function test_project_state_core_contract_preserves_contact_deleted_at(): void
    {
        $this->assertSame(
            6,
            config('project_state.sections.core.version'),
        );

        $this->assertContains(
            'deleted_at',
            config('project_state.sections.core.tables.contacts.columns', []),
        );
    }
}