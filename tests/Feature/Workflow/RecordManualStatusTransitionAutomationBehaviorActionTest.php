<?php

namespace Tests\Feature\Workflow;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Tasks\Actions\RecordManualTaskCompletionAutomationBehaviorAction;
use App\Modules\Tasks\Events\TaskCompleted;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflow\Actions\RecordManualStatusTransitionAutomationBehaviorAction;
use App\Modules\Workflow\Data\ContactWorkflowStatusTransition;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordManualStatusTransitionAutomationBehaviorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_compound_pattern_after_recent_manual_task_completion_by_same_actor(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = $this->createStatus('submitted', 'Submitted');
        $toStatus = $this->createStatus('approved', 'Approved');

        $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $changedAt = $completedAt->addMinutes(5);

        $task = Task::factory()->relatedTo($contact)->completed()->create([
            'title' => 'Review application',
            'completed_at' => $completedAt,
        ]);

        app(RecordManualTaskCompletionAutomationBehaviorAction::class)->handle(
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

        $occurrence = app(RecordManualStatusTransitionAutomationBehaviorAction::class)->handle(
            $this->manualTransition(
                contact: $contact,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actor: $actor,
                occurredAt: $changedAt,
            ),
        );

        $this->assertInstanceOf(AutomationBehaviorOccurrence::class, $occurrence);
        $this->assertSame(
            RecordManualStatusTransitionAutomationBehaviorAction::ACTION_KEY,
            $occurrence->action_key,
        );
        $this->assertSame(
            RecordManualStatusTransitionAutomationBehaviorAction::CAPABILITY_KEY,
            $occurrence->capability_key,
        );
        $this->assertSame('review application', $occurrence->fingerprint_parts['normalized_task_title']);
        $this->assertSame('submitted', $occurrence->fingerprint_parts['from_status_key']);
        $this->assertSame('approved', $occurrence->fingerprint_parts['to_status_key']);
        $this->assertSame('Review application', $occurrence->context['task_title']);
        $this->assertSame('Approved', $occurrence->context['to_status_name']);
        $this->assertSame(
            'manual_task_completion_then_manual_status_change',
            $occurrence->meta['pattern'],
        );

        $this->assertDatabaseCount('automation_behavior_occurrences', 2);
        $this->assertDatabaseCount('automation_opportunities', 1);
    }

    public function test_it_does_not_record_when_task_completion_is_too_old(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = $this->createStatus('submitted', 'Submitted');
        $toStatus = $this->createStatus('approved', 'Approved');

        $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $changedAt = $completedAt->addMinutes(
            RecordManualStatusTransitionAutomationBehaviorAction::RELATED_ACTION_WINDOW_MINUTES + 1,
        );

        $this->recordCompletionEvidence(
            actor: $actor,
            contact: $contact,
            completedAt: $completedAt,
        );

        $occurrence = app(RecordManualStatusTransitionAutomationBehaviorAction::class)->handle(
            $this->manualTransition(
                contact: $contact,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actor: $actor,
                occurredAt: $changedAt,
            ),
        );

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_behavior_occurrences', 1);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_does_not_record_when_task_completion_was_by_different_actor(): void
    {
        $completionActor = User::factory()->create();
        $statusActor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = $this->createStatus('submitted', 'Submitted');
        $toStatus = $this->createStatus('approved', 'Approved');

        $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');

        $this->recordCompletionEvidence(
            actor: $completionActor,
            contact: $contact,
            completedAt: $completedAt,
        );

        $occurrence = app(RecordManualStatusTransitionAutomationBehaviorAction::class)->handle(
            $this->manualTransition(
                contact: $contact,
                fromStatus: $fromStatus,
                toStatus: $toStatus,
                actor: $statusActor,
                occurredAt: $completedAt->addMinutes(2),
            ),
        );

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_does_not_record_non_manual_status_transition(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = $this->createStatus('submitted', 'Submitted');
        $toStatus = $this->createStatus('approved', 'Approved');

        $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');

        $this->recordCompletionEvidence(
            actor: $actor,
            contact: $contact,
            completedAt: $completedAt,
        );

        $transition = new ContactWorkflowStatusTransition(
            contactId: $contact->getKey(),
            contactWorkflowProfileId: 1,
            fromContactStatusId: $fromStatus->getKey(),
            toContactStatusId: $toStatus->getKey(),
            reason: 'flow_route_change_status',
            source: 'flow_route',
            actorType: null,
            actorId: null,
            occurredAt: $completedAt->addMinutes(2),
            meta: [
                'source' => 'flow_route',
            ],
        );

        $occurrence = app(RecordManualStatusTransitionAutomationBehaviorAction::class)
            ->handle($transition);

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_repeated_compound_patterns_for_distinct_contacts_become_eligible(): void
    {
        $actor = User::factory()->create();

        $fromStatus = $this->createStatus('submitted', 'Submitted');
        $toStatus = $this->createStatus('approved', 'Approved');

        foreach (Contact::factory()->count(3)->create() as $index => $contact) {
            $completedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC')
                ->addMinutes($index * 20);

            $this->recordCompletionEvidence(
                actor: $actor,
                contact: $contact,
                completedAt: $completedAt,
            );

            app(RecordManualStatusTransitionAutomationBehaviorAction::class)->handle(
                $this->manualTransition(
                    contact: $contact,
                    fromStatus: $fromStatus,
                    toStatus: $toStatus,
                    actor: $actor,
                    occurredAt: $completedAt->addMinutes(3),
                ),
            );
        }

        $opportunity = AutomationOpportunity::query()
            ->forAction(RecordManualStatusTransitionAutomationBehaviorAction::ACTION_KEY)
            ->firstOrFail();

        $this->assertSame(3, $opportunity->occurrence_count);
        $this->assertSame(3, $opportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $opportunity->status);
        $this->assertNotNull($opportunity->eligible_at);

        $this->assertDatabaseCount('automation_behavior_occurrences', 6);
        $this->assertDatabaseCount('automation_opportunities', 1);
    }

    private function recordCompletionEvidence(
        User $actor,
        Contact $contact,
        CarbonImmutable $completedAt,
    ): AutomationBehaviorOccurrence {
        $task = Task::factory()->relatedTo($contact)->completed()->create([
            'title' => 'Review application',
            'completed_at' => $completedAt,
        ]);

        return app(RecordManualTaskCompletionAutomationBehaviorAction::class)->handle(
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
    }

    private function manualTransition(
        Contact $contact,
        ContactStatus $fromStatus,
        ContactStatus $toStatus,
        User $actor,
        CarbonImmutable $occurredAt,
    ): ContactWorkflowStatusTransition {
        return new ContactWorkflowStatusTransition(
            contactId: $contact->getKey(),
            contactWorkflowProfileId: 1,
            fromContactStatusId: $fromStatus->getKey(),
            toContactStatusId: $toStatus->getKey(),
            reason: 'crm_manual_status_update',
            source: 'crm',
            actorType: $actor->getMorphClass(),
            actorId: $actor->getKey(),
            occurredAt: $occurredAt,
            meta: [
                'source' => 'contact_show_status_form',
            ],
        );
    }

    private function createStatus(string $key, string $name): ContactStatus
    {
        return ContactStatus::query()->create([
            'key' => $key,
            'name' => $name,
            'is_active' => true,
        ]);
    }
}
