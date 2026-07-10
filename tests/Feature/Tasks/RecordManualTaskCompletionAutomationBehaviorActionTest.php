<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Actions\RecordManualTaskCompletionAutomationBehaviorAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordManualTaskCompletionAutomationBehaviorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_manual_crm_task_completion_as_evidence_only(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();
        $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');

        $task = Task::factory()->relatedTo($contact)->completed()->create([
            'title' => 'Review application',
            'task_template_key' => null,
            'completed_at' => $completedAt,
        ]);

        $occurrence = app(RecordManualTaskCompletionAutomationBehaviorAction::class)->handle(
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

        $this->assertInstanceOf(AutomationBehaviorOccurrence::class, $occurrence);
        $this->assertSame(
            RecordManualTaskCompletionAutomationBehaviorAction::ACTION_KEY,
            $occurrence->action_key,
        );
        $this->assertSame($actor->getMorphClass(), $occurrence->actor_type);
        $this->assertSame($actor->getKey(), $occurrence->actor_id);
        $this->assertSame($contact->getMorphClass(), $occurrence->subject_type);
        $this->assertSame($contact->getKey(), $occurrence->subject_id);
        $this->assertSame('review application', $occurrence->fingerprint_parts['normalized_title']);
        $this->assertSame('Review application', $occurrence->context['task_title']);
        $this->assertTrue($occurrence->occurred_at->equalTo($completedAt));

        $this->assertDatabaseCount('automation_behavior_occurrences', 1);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_does_not_record_non_manual_completion(): void
    {
        $contact = Contact::factory()->create();

        $task = Task::factory()->relatedTo($contact)->completed()->create();

        $occurrence = app(RecordManualTaskCompletionAutomationBehaviorAction::class)->handle(
            new TaskCompleted(
                task: $task,
                source: 'tasks',
            ),
        );

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
    }

    public function test_it_does_not_record_completion_without_contact_subject(): void
    {
        $actor = User::factory()->create();

        $task = Task::factory()->completed()->create([
            'related_type' => null,
            'related_id' => null,
        ]);

        $occurrence = app(RecordManualTaskCompletionAutomationBehaviorAction::class)->handle(
            new TaskCompleted(
                task: $task,
                actorType: $actor->getMorphClass(),
                actorId: $actor->getKey(),
                source: 'crm',
                meta: [
                    'source' => 'task_controller.complete',
                ],
            ),
        );

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
    }
}
