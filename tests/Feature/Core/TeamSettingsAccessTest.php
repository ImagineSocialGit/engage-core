<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_team_assign_member_and_assign_contact_with_actor_provenance(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $member->getKey(),
            'role_key' => 'member',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->post(route('crm.settings.team.teams.store'), [
                'name' => 'Sales',
            ])
            ->assertRedirect();

        $team = Team::query()->where('name', 'Sales')->firstOrFail();

        $this->actingAs($owner)
            ->patch(route('crm.settings.team.members.update', $member), [
                'name' => $member->name,
                'email' => $member->email,
                'role_key' => 'member',
                'is_active' => '1',
                'team_ids' => [$team->getKey()],
            ])
            ->assertRedirect();

        $this->assertTrue(DB::table('team_user')
            ->where('team_id', $team->getKey())
            ->where('user_id', $member->getKey())
            ->exists());

        $contact = Contact::factory()->create();

        $this->actingAs($owner)
            ->patch(route('crm.contacts.assignment.update', $contact), [
                'assigned_user_id' => $member->getKey(),
                'assigned_team_id' => $team->getKey(),
            ])
            ->assertRedirect();

        $contact->refresh();
        $this->assertSame($member->getKey(), $contact->assigned_user_id);
        $this->assertSame($team->getKey(), $contact->assigned_team_id);
        $this->assertSame($owner->getKey(), data_get($contact->meta, 'assignment.actor_user_id'));
    }

    public function test_member_can_view_team_directory_but_cannot_manage_access_or_assign_contacts(): void
    {
        $member = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $member->getKey(),
            'role_key' => 'member',
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);

        $this->actingAs($member)
            ->get(route('crm.settings.team.index'))
            ->assertOk();

        $this->actingAs($member)
            ->post(route('crm.settings.team.teams.store'), ['name' => 'Blocked'])
            ->assertForbidden();

        $this->actingAs($member)
            ->patch(route('crm.contacts.assignment.update', $contact), [
                'assigned_user_id' => null,
                'assigned_team_id' => null,
            ])
            ->assertForbidden();
    }

    public function test_owner_cannot_remove_own_team_management_access(): void
    {
        $owner = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $owner->getKey(),
            'role_key' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->patch(route('crm.settings.team.members.update', $owner), [
                'name' => $owner->name,
                'email' => $owner->email,
                'role_key' => 'member',
                'is_active' => '1',
                'team_ids' => [],
            ])
            ->assertSessionHasErrors('role_key');

        $this->assertDatabaseHas('user_access_profiles', [
            'user_id' => $owner->getKey(),
            'role_key' => 'owner',
            'is_active' => true,
        ]);
    }
}