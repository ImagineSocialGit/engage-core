<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Access\Models\Team;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Models\Contact;
use App\Support\Users\CrmUserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TeamAccessFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_users_keep_owner_access_and_managed_user_creation_materializes_a_profile(): void
    {
        $legacy = User::factory()->create();
        $access = app(UserAccessService::class);

        $this->assertSame('owner', $access->roleKey($legacy));
        $this->assertTrue($access->allows($legacy, 'team.manage'));
        $this->assertDatabaseMissing('user_access_profiles', [
            'user_id' => $legacy->getKey(),
        ]);

        $created = app(CrmUserManager::class)->create(
            name: 'Generic Manager',
            email: 'manager@example.test',
            password: 'Example-password-42!',
            roleKey: 'manager',
        );

        $this->assertDatabaseHas('user_access_profiles', [
            'user_id' => $created->getKey(),
            'role_key' => 'manager',
            'is_active' => true,
        ]);
    }

    public function test_contact_visibility_follows_owner_manager_member_viewer_and_team_rules(): void
    {
        $owner = User::factory()->create();
        $manager = User::factory()->create();
        $member = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::query()->create([
            'name' => 'Sales',
            'is_active' => true,
        ]);

        $this->profile($manager, 'manager');
        $this->profile($member, 'member');
        $this->profile($viewer, 'viewer');

        DB::table('team_user')->insert([
            [
                'team_id' => $team->getKey(),
                'user_id' => $manager->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'team_id' => $team->getKey(),
                'user_id' => $member->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'team_id' => $team->getKey(),
                'user_id' => $viewer->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $direct = Contact::factory()->create([
            'assigned_user_id' => $member->getKey(),
        ]);
        $teamContact = Contact::factory()->create([
            'assigned_team_id' => $team->getKey(),
        ]);
        $unassigned = Contact::factory()->create([
            'assigned_user_id' => null,
            'assigned_team_id' => null,
        ]);
        $other = Contact::factory()->create([
            'assigned_user_id' => $owner->getKey(),
        ]);

        $visibility = app(ContactVisibility::class);

        $this->assertSameIds(
            [$direct->id, $teamContact->id, $unassigned->id, $other->id],
            $visibility->apply(Contact::query(), $owner)->pluck('id')->all(),
        );
        $this->assertSameIds(
            [$direct->id, $teamContact->id, $unassigned->id],
            $visibility->apply(Contact::query(), $manager)->pluck('id')->all(),
        );
        $this->assertSameIds(
            [$direct->id],
            $visibility->apply(Contact::query(), $member)->pluck('id')->all(),
        );
        $this->assertSameIds(
            [$direct->id, $teamContact->id],
            $visibility->apply(Contact::query(), $viewer)->pluck('id')->all(),
        );
    }

    public function test_inactive_access_profile_blocks_capabilities(): void
    {
        $user = User::factory()->create();
        $this->profile($user, 'owner', false);
        $access = app(UserAccessService::class);

        $this->assertFalse($access->isActive($user));
        $this->assertFalse($access->allows($user, 'team.manage'));
        $this->assertFalse($access->allows($user, 'contacts.view_all'));
    }

    private function profile(
        User $user,
        string $role,
        bool $active = true,
    ): UserAccessProfile {
        return UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => $role,
            'is_active' => $active,
            'capability_overrides' => null,
        ]);
    }

    /**
     * @param array<int, int> $expected
     * @param array<int, int> $actual
     */
    private function assertSameIds(array $expected, array $actual): void
    {
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}