<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskCompletionProvenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_task_action_dispatches_completion_provenance_once(): void
    {
        Event::fake([
            TaskCompleted::class,
        ]);

        Carbon::setTestNow('2026-07-10 14:00:00');

        $actor = User::factory()->create();
        $task = Task::factory()->create([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        $completedTask = app(CompleteTaskAction::class)->handle(
            task: $task,
            actor: $actor,
            source: 'crm',
            meta: [
                'source' => 'task_controller.complete',
            ],
        );

        Event::assertDispatched(
            TaskCompleted::class,
            fn (TaskCompleted $event): bool => $event->task->is($completedTask)
                && $event->actorType === $actor->getMorphClass()
                && $event->actorId === $actor->getKey()
                && $event->source === 'crm'
                && $event->meta['source'] === 'task_controller.complete'
                && $event->occurredAt->equalTo(Carbon::now()),
        );

        Carbon::setTestNow('2026-07-10 15:00:00');

        app(CompleteTaskAction::class)->handle(
            task: $completedTask->refresh(),
            actor: $actor,
            source: 'crm',
            meta: [
                'source' => 'task_controller.complete',
            ],
        );

        Event::assertDispatchedTimes(TaskCompleted::class, 1);
    }

    public function test_task_completed_automation_event_includes_completion_provenance(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $completedAt = CarbonImmutable::parse('2026-07-10 14:30:00', 'UTC');

        $task = Task::factory()
            ->relatedTo($contact)
            ->completed()
            ->create([
                'completed_at' => $completedAt,
            ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted(
                task: $task,
                actorType: $actor->getMorphClass(),
                actorId: $actor->getKey(),
                source: 'crm',
                meta: [
                    'source' => 'task_controller.complete',
                ],
                occurredAt: $completedAt,
            ),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            function (AutomationEventRecorded $event) use ($actor, $completedAt): bool {
                return data_get($event->event->meta, 'completion.source') === 'crm'
                    && data_get($event->event->meta, 'completion.actor_type') === $actor->getMorphClass()
                    && data_get($event->event->meta, 'completion.actor_id') === $actor->getKey()
                    && data_get($event->event->meta, 'completion.occurred_at') === $completedAt->toISOString()
                    && data_get($event->event->meta, 'completion.meta.source') === 'task_controller.complete';
            },
        );
    }

    public function test_task_controller_marks_completion_as_manual_crm_provenance(): void
    {
        Event::fake([
            TaskCompleted::class,
        ]);

        $user = User::factory()->create();

        $task = Task::factory()->create([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        $response = $this
            ->actingAs($user)
            ->patch(route('crm.tasks.complete', $task));

        $response->assertRedirect();

        Event::assertDispatched(
            TaskCompleted::class,
            fn (TaskCompleted $event): bool => $event->task->is($task)
                && $event->actorType === $user->getMorphClass()
                && $event->actorId === $user->getKey()
                && $event->source === 'crm'
                && $event->meta['source'] === 'task_controller.complete',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
