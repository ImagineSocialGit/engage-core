<?php

namespace Tests\Feature\Scheduling;

use App\Models\User;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Providers\SchedulingModuleServiceProvider;
use App\Support\ModuleIntegrations\InternalNotifications\Tasks\TeamMemberTaskAssignedRecipientResolver;
use App\Support\ModuleIntegrations\InternalNotifications\UserTeamMemberBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SchedulingUserHostIdentityBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', array_values(array_unique([
            ...config('modules.enabled', []),
            'scheduling',
        ])));

        if (! $this->app->getProvider(SchedulingModuleServiceProvider::class)) {
            $this->app->register(SchedulingModuleServiceProvider::class);
        }
    }

    public function test_notification_profile_bridge_relinks_imported_team_member_by_email(): void
    {
        $user = User::factory()->create([
            'name' => 'Taylor User',
            'email' => 'taylor@example.test',
        ]);
        $imported = TeamMember::factory()->create([
            'user_id' => null,
            'name' => 'Imported Taylor',
            'email' => 'TAYLOR@example.test',
            'phone' => '+15555550123',
            'role' => 'loan_officer',
            'is_active' => true,
        ]);

        $resolved = app(UserTeamMemberBridge::class)->resolveActive($user);

        $this->assertNotNull($resolved);
        $this->assertSame($imported->getKey(), $resolved->getKey());
        $this->assertSame($user->getKey(), $resolved->user_id);
        $this->assertSame('Imported Taylor', $resolved->name);
        $this->assertSame('TAYLOR@example.test', $resolved->email);
        $this->assertSame('+15555550123', $resolved->phone);
        $this->assertSame('loan_officer', $resolved->role);
    }

    public function test_team_access_deactivation_deactivates_linked_scheduling_host(): void
    {
        $user = User::factory()->create();
        $host = SchedulingHost::factory()->forHostable($user)->create([
            'source' => SchedulingHost::SOURCE_MANUAL,
            'status' => SchedulingHost::STATUS_ACTIVE,
        ]);

        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => false,
        ]);

        $this->assertSame(
            SchedulingHost::STATUS_INACTIVE,
            $host->refresh()->status,
        );
    }

    public function test_task_recipient_resolution_maps_user_to_notification_profile(): void
    {
        $user = User::factory()->create([
            'name' => 'Task Owner',
            'email' => 'task-owner@example.test',
        ]);
        TeamMember::factory()->create([
            'user_id' => null,
            'name' => 'Task Notification Profile',
            'email' => 'task-owner@example.test',
            'is_active' => true,
        ]);

        $recipients = app(TeamMemberTaskAssignedRecipientResolver::class)
            ->resolve($user);

        $this->assertCount(1, $recipients);

        $recipient = $recipients->first();

        $this->assertInstanceOf(TeamMember::class, $recipient->source);
        $this->assertSame($user->getKey(), $recipient->source->user_id);
        $this->assertSame('task-owner@example.test', $recipient->email);
    }
    public function test_user_without_notification_profile_has_no_internal_notification_recipient(): void
    {
        $user = User::factory()->create([
            'email' => 'no-notification-profile@example.test',
        ]);

        $recipients = app(TeamMemberTaskAssignedRecipientResolver::class)
            ->resolve($user);

        $this->assertCount(0, $recipients);
        $this->assertDatabaseMissing('team_members', [
            'user_id' => $user->getKey(),
        ]);
    }

}