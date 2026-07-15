<?php

namespace Tests\Feature\Tasks;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class TaskCompletionBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_updates_state_and_touches_every_linked_contact(): void
    {
        Carbon::setTestNow('2026-07-10 14:00:00');

        $firstContact = Contact::factory()->create([
            'last_activity_at' => Carbon::parse('2026-07-01 09:00:00'),
        ]);
        $secondContact = Contact::factory()->create([
            'last_activity_at' => Carbon::parse('2026-07-02 09:00:00'),
        ]);

        $task = Task::factory()
            ->linkedTo($firstContact, TaskLink::ROLE_SUBJECT)
            ->linkedTo($secondContact, TaskLink::ROLE_CONTEXT)
            ->canceled('Not needed yet.')
            ->create();

        $completed = app(CompleteTaskAction::class)->handle($task);

        $this->assertSame(Task::STATUS_COMPLETED, $completed->status);
        $this->assertTrue($completed->completed_at->equalTo(Carbon::now()));
        $this->assertNull($completed->canceled_at);
        $this->assertNull($completed->canceled_reason);
        $this->assertTrue($firstContact->refresh()->last_activity_at->equalTo(Carbon::now()));
        $this->assertTrue($secondContact->refresh()->last_activity_at->equalTo(Carbon::now()));
    }

    public function test_standalone_completion_is_valid_and_idempotent(): void
    {
        Event::fake([TaskCompleted::class]);

        $task = Task::factory()->create([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        $completed = app(CompleteTaskAction::class)->handle($task);
        app(CompleteTaskAction::class)->handle($completed->refresh());

        $this->assertSame(Task::STATUS_COMPLETED, $completed->status);
        Event::assertDispatchedTimes(TaskCompleted::class, 1);
    }

    public function test_non_contact_linked_task_completes_without_contact_assumption(): void
    {
        Event::fake([TaskCompleted::class]);

        $appointment = Appointment::factory()->create();
        $task = Task::factory()->linkedTo($appointment)->create();

        $completed = app(CompleteTaskAction::class)->handle($task);

        $this->assertSame(Task::STATUS_COMPLETED, $completed->status);
        Event::assertDispatched(TaskCompleted::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
