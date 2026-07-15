<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
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
            ->linkedTo($contact)
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

    public function test_standalone_task_completed_automation_event_has_null_contact_id(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $task = Task::factory()->completed()->create([
            'title' => 'Standalone completed task',
        ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            fn (AutomationEventRecorded $event): bool => $event->event->contactId === null
                && $event->event->subjectType === $task->getMorphClass()
                && $event->event->subjectId === $task->getKey()
                && data_get($event->event->payload, 'task.links') === [],
        );
    }

    public function test_task_completed_event_includes_compact_non_contact_task_links(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $appointment = Appointment::factory()->create();
        $task = Task::factory()->linkedTo($appointment)->completed()->create();

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            fn (AutomationEventRecorded $event): bool => data_get(
                $event->event->payload,
                'task.links.0',
            ) === [
                'role' => TaskLink::ROLE_SUBJECT,
                'linkable_type' => $appointment->getMorphClass(),
                'linkable_id' => $appointment->getKey(),
            ],
        );
    }

    public function test_task_completed_contact_id_is_null_when_contact_link_attribution_is_ambiguous(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $firstContact = Contact::factory()->create();
        $secondContact = Contact::factory()->create();

        $task = Task::factory()
            ->linkedTo($firstContact, TaskLink::ROLE_SUBJECT)
            ->linkedTo($secondContact, TaskLink::ROLE_SUBJECT)
            ->completed()
            ->create();

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            fn (AutomationEventRecorded $event): bool => $event->event->contactId === null,
        );
    }

    public function test_task_completed_contact_id_can_fall_back_to_responsible_contact(): void
    {
        Event::fake([
            AutomationEventRecorded::class,
        ]);

        $contact = Contact::factory()->create();

        $task = Task::factory()->completed()->create([
            'responsible_party' => Task::RESPONSIBLE_PARTY_CONTACT,
            'responsible_type' => $contact->getMorphClass(),
            'responsible_id' => $contact->getKey(),
        ]);

        app(EmitTaskCompletedAutomationEvent::class)->handle(
            new TaskCompleted($task),
        );

        Event::assertDispatched(
            AutomationEventRecorded::class,
            fn (AutomationEventRecorded $event): bool => $event->event->contactId === $contact->getKey(),
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
