<?php

namespace Tests\Feature\Tasks;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\InternalNotifications\Services\Tasks\OnlyActiveTeamMemberTaskAssignmentStrategyResolver;
use App\Modules\InternalNotifications\Services\Tasks\TeamMemberTaskAssignedRecipientResolver;
use App\Modules\Tasks\Actions\BuildTaskDigestsAction;
use App\Modules\Tasks\Actions\CreateTaskAction;
use App\Modules\Tasks\Actions\NotifyAssignedTaskRecipientsAction;
use App\Modules\Tasks\Actions\SendTaskDigestNotificationsAction;
use App\Modules\Tasks\Contracts\TaskNotificationSchedulerContract;
use App\Modules\Tasks\Data\TaskNotification;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Services\LinkPresenters\ContactTaskLinkPresenter;
use App\Modules\Tasks\Services\TaskAssignedRecipientsResolver;
use App\Modules\Tasks\Services\TaskAssignmentStrategyResolver;
use App\Modules\Tasks\Services\TaskContactLinkResolver;
use App\Modules\Tasks\Services\TaskLinkPresentationResolver;
use App\Modules\Tasks\Services\TaskNotificationScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskNotificationAndDigestBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_standalone_task_builds_notification_that_opens_the_task(): void
    {
        $teamMember = TeamMember::factory()->create([
            'name' => 'Taylor Team',
            'email' => 'taylor@example.test',
        ]);

        $task = Task::factory()->assignedTo($teamMember)->create([
            'title' => 'Review standalone checklist',
        ]);

        $capture = $this->captureScheduler();

        $action = new NotifyAssignedTaskRecipientsAction(
            assignedRecipientsResolver: $this->teamMemberRecipients(),
            linkPresentation: new TaskLinkPresentationResolver([
                new ContactTaskLinkPresenter(),
            ]),
            notificationScheduler: new TaskNotificationScheduler([$capture]),
        );

        $action->handle($task);

        $this->assertCount(1, $capture->notifications);

        $notification = $capture->notifications[0];

        $this->assertSame('task_assigned', $notification->messageType);
        $this->assertTrue($notification->context?->is($task));
        $this->assertSame('Open task', $notification->content['cta']['label']);
        $this->assertSame(route('crm.tasks.show', $task), $notification->content['cta']['url']);
        $this->assertSame('Review standalone checklist', $notification->content['details']['Task']);
    }

    public function test_assigned_standalone_task_is_included_in_daily_digest_and_can_be_scheduled(): void
    {
        $teamMember = TeamMember::factory()->create([
            'name' => 'Digest Owner',
            'email' => 'digest@example.test',
        ]);

        $task = Task::factory()->assignedTo($teamMember)->create([
            'title' => 'Standalone digest task',
            'due_at' => now(),
        ]);

        $build = new BuildTaskDigestsAction($this->teamMemberRecipients());
        $digests = $build->handle(BuildTaskDigestsAction::FREQUENCY_DAILY);

        $this->assertCount(1, $digests);
        $this->assertSame(
            [$task->getKey()],
            $digests->first()->tasks->pluck('id')->all(),
        );

        $capture = $this->captureScheduler();

        $scheduled = (new SendTaskDigestNotificationsAction(
            buildTaskDigests: $build,
            notificationScheduler: new TaskNotificationScheduler([$capture]),
        ))->handle(BuildTaskDigestsAction::FREQUENCY_DAILY);

        $this->assertSame(1, $scheduled);
        $this->assertCount(1, $capture->notifications);
        $this->assertSame(
            BuildTaskDigestsAction::FREQUENCY_DAILY,
            $capture->notifications[0]->messageType,
        );
        $this->assertStringContainsString(
            'Standalone digest task',
            implode("\n", $capture->notifications[0]->content['body']),
        );
    }

    public function test_unassigned_tasks_do_not_create_digest_entries(): void
    {
        Task::factory()->create([
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'due_at' => now(),
        ]);

        $digests = (new BuildTaskDigestsAction(
            $this->teamMemberRecipients(),
        ))->handle(BuildTaskDigestsAction::FREQUENCY_DAILY);

        $this->assertCount(0, $digests);
    }

    public function test_optional_internal_notifications_strategy_can_assign_the_only_active_team_member(): void
    {
        $teamMember = TeamMember::factory()->create([
            'name' => 'Only Active Member',
        ]);

        TeamMember::factory()->inactive()->create();

        $action = new CreateTaskAction(
            assignmentStrategies: new TaskAssignmentStrategyResolver([
                new OnlyActiveTeamMemberTaskAssignmentStrategyResolver(),
            ]),
            contactLinks: new TaskContactLinkResolver(),
        );

        $task = $action->handle([
            'title' => 'Strategy assigned task',
            'assigned_to_strategy' => OnlyActiveTeamMemberTaskAssignmentStrategyResolver::STRATEGY,
            'responsible_party' => Task::RESPONSIBLE_PARTY_INTERNAL,
        ]);

        $this->assertSame($teamMember->getMorphClass(), $task->assigned_to_type);
        $this->assertSame($teamMember->getKey(), $task->assigned_to_id);
    }

    private function teamMemberRecipients(): TaskAssignedRecipientsResolver
    {
        return new TaskAssignedRecipientsResolver([
            new TeamMemberTaskAssignedRecipientResolver(),
        ]);
    }

    private function captureScheduler(): TaskNotificationSchedulerContract
    {
        return new class implements TaskNotificationSchedulerContract {
            /** @var array<int, TaskNotification> */
            public array $notifications = [];

            public function supports(TaskNotification $notification): bool
            {
                return true;
            }

            public function schedule(TaskNotification $notification): bool
            {
                $this->notifications[] = $notification;

                return true;
            }
        };
    }
}
