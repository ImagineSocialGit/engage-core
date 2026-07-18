<?php

namespace Tests\Feature\Tasks;

use App\Modules\Tasks\Actions\CompleteTaskAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Listeners\EmitTaskCompletedAutomationEvent;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class TaskCompletionAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_completion_and_automation_event_are_committed_once(): void
    {
        Event::fake([
            TaskCompleted::class,
            AutomationEventRecorded::class,
        ]);

        $task = Task::factory()->create([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        $completed = app(CompleteTaskAction::class)->handle($task);
        app(CompleteTaskAction::class)->handle($completed->refresh());

        $this->assertSame(Task::STATUS_COMPLETED, $completed->status);
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => TaskCompleted::NAME,
            'subject_type' => $completed->getMorphClass(),
            'subject_id' => (string) $completed->getKey(),
        ]);
        $this->assertDatabaseCount('automation_event_outbox_events', 1);
        Event::assertDispatchedTimes(TaskCompleted::class, 1);
    }

    public function test_task_completion_rolls_back_when_its_outbox_record_cannot_be_written(): void
    {
        Event::fake([TaskCompleted::class]);

        $emitter = Mockery::mock(EmitTaskCompletedAutomationEvent::class);
        $emitter->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('Simulated task outbox failure.'));
        app()->instance(EmitTaskCompletedAutomationEvent::class, $emitter);

        $task = Task::factory()->create([
            'status' => Task::STATUS_OPEN,
            'completed_at' => null,
        ]);

        try {
            app(CompleteTaskAction::class)->handle($task);
            $this->fail('The task completion should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated task outbox failure.', $exception->getMessage());
        }

        $task->refresh();

        $this->assertSame(Task::STATUS_OPEN, $task->status);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
        Event::assertNotDispatched(TaskCompleted::class);
    }
}