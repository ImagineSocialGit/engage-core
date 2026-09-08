<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactAccessVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_contact_index_and_lookup_exclude_contacts_owned_by_other_users(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');

        $visible = Contact::factory()->create([
            'email' => 'visible@example.test',
            'assigned_user_id' => $member->getKey(),
        ]);
        $hidden = Contact::factory()->create([
            'email' => 'hidden@example.test',
            'assigned_user_id' => $otherUser->getKey(),
        ]);

        $this->actingAs($member)
            ->get(route('crm.contacts.index'))
            ->assertOk()
            ->assertSee($visible->email)
            ->assertDontSee($hidden->email);

        $this->actingAs($member)
            ->getJson(route('crm.contacts.lookup', [
                'ids' => [$visible->getKey(), $hidden->getKey()],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'contacts')
            ->assertJsonPath('contacts.0.id', $visible->getKey());
    }

    public function test_route_bound_hidden_contact_returns_not_found(): void
    {
        $member = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->profile($member, 'member');
        $hidden = Contact::factory()->create([
            'assigned_user_id' => $otherUser->getKey(),
        ]);

        $this->actingAs($member)
            ->get(route('crm.contacts.show', $hidden))
            ->assertNotFound();
    }

    public function test_member_created_contact_is_assigned_to_the_creator(): void
    {
        $member = User::factory()->create();
        $this->profile($member, 'member');

        $this->actingAs($member)
            ->post(route('crm.contacts.store'), [
                'first_name' => 'Assigned',
                'last_name' => 'Contact',
                'email' => 'assigned@example.test',
                'phone' => null,
                'source' => 'crm',
                'existing_relationship_confirmed' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('contacts', [
            'email' => 'assigned@example.test',
            'assigned_user_id' => $member->getKey(),
        ]);
    }

    public function test_viewer_cannot_modify_visible_contact(): void
    {
        $viewer = User::factory()->create();
        $this->profile($viewer, 'viewer');
        $contact = Contact::factory()->create([
            'assigned_user_id' => $viewer->getKey(),
        ]);

        $this->actingAs($viewer)
            ->patch(route('crm.contacts.update', $contact), [
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
                'email' => $contact->email,
                'phone' => $contact->phone,
            ])
            ->assertForbidden();
    }

    private function profile(User $user, string $role): void
    {
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => true,
            'capability_overrides' => null,
        ]);
    }
}