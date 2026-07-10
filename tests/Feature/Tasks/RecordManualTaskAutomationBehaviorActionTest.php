<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Tasks\Actions\RecordManualTaskAutomationBehaviorAction;
use App\Modules\Tasks\Models\Task;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordManualTaskAutomationBehaviorActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_manual_contact_task_behavior_with_status_context(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
            'last_status_changed_at' => now(),
        ]);

        $task = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => '  Call   This Contact  ',
            'task_template_key' => null,
        ]);

        $occurrence = app(RecordManualTaskAutomationBehaviorAction::class)->handle(
            task: $task,
            actor: $actor,
        );

        $this->assertInstanceOf(AutomationBehaviorOccurrence::class, $occurrence);
        $this->assertSame(RecordManualTaskAutomationBehaviorAction::ACTION_KEY, $occurrence->action_key);
        $this->assertSame(RecordManualTaskAutomationBehaviorAction::CAPABILITY_KEY, $occurrence->capability_key);
        $this->assertSame($actor->getMorphClass(), $occurrence->actor_type);
        $this->assertSame($actor->getKey(), $occurrence->actor_id);
        $this->assertSame($contact->getMorphClass(), $occurrence->subject_type);
        $this->assertSame($contact->getKey(), $occurrence->subject_id);
        $this->assertSame('contact', $occurrence->fingerprint_parts['related_subject_type']);
        $this->assertSame('attempting_contact', $occurrence->fingerprint_parts['contact_status_key']);
        $this->assertNull($occurrence->fingerprint_parts['task_template_key']);
        $this->assertSame('call this contact', $occurrence->fingerprint_parts['normalized_title']);
        $this->assertSame('Attempting Contact', $occurrence->context['contact_status_name']);
        $this->assertSame($task->getKey(), $occurrence->context['task_id']);
        $this->assertSame('task_controller.store', $occurrence->meta['source']);

        $opportunity = AutomationOpportunity::query()->firstOrFail();

        $this->assertSame($occurrence->fingerprint, $opportunity->fingerprint);
        $this->assertSame(1, $opportunity->occurrence_count);
        $this->assertSame(1, $opportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_OBSERVING, $opportunity->status);
    }

    public function test_it_uses_task_template_key_instead_of_title_when_available(): void
    {
        $contact = Contact::factory()->create();

        $firstTask = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'First visible title',
            'task_template_key' => 'tasks.call_contact',
        ]);

        $secondTask = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Completely different visible title',
            'task_template_key' => 'tasks.call_contact',
        ]);

        $action = app(RecordManualTaskAutomationBehaviorAction::class);

        $firstOccurrence = $action->handle($firstTask);
        $secondOccurrence = $action->handle($secondTask);

        $this->assertSame('tasks.call_contact', $firstOccurrence?->fingerprint_parts['task_template_key']);
        $this->assertNull($firstOccurrence?->fingerprint_parts['normalized_title']);
        $this->assertSame($firstOccurrence?->fingerprint, $secondOccurrence?->fingerprint);
    }

    public function test_it_records_contact_task_without_workflow_status_when_workflow_relation_is_unavailable(): void
    {
        $originalMorphMap = Relation::morphMap();

        try {
            $contact = Contact::factory()->create();

            $task = Task::factory()->relatedTo($contact)->create([
                'source' => Task::SOURCE_MANUAL,
                'title' => 'Review documents',
            ]);

            $occurrence = app(RecordManualTaskAutomationBehaviorAction::class)->handle($task);

            $this->assertInstanceOf(AutomationBehaviorOccurrence::class, $occurrence);
            $this->assertNull($occurrence->fingerprint_parts['contact_status_key']);
            $this->assertNull($occurrence->context['contact_status_key']);
            $this->assertNull($occurrence->context['contact_status_name']);
        } finally {
            Relation::morphMap($originalMorphMap, false);
        }
    }

    public function test_it_does_not_record_non_manual_tasks(): void
    {
        $contact = Contact::factory()->create();

        $task = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_SYSTEM,
            'title' => 'System-created task',
        ]);

        $occurrence = app(RecordManualTaskAutomationBehaviorAction::class)->handle($task);

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_does_not_record_manual_tasks_without_contact_subject(): void
    {
        $task = Task::factory()->create([
            'source' => Task::SOURCE_MANUAL,
            'related_type' => null,
            'related_id' => null,
            'title' => 'Standalone task',
        ]);

        $occurrence = app(RecordManualTaskAutomationBehaviorAction::class)->handle($task);

        $this->assertNull($occurrence);
        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_repeated_equivalent_manual_tasks_for_distinct_contacts_become_eligible(): void
    {
        $actor = User::factory()->create();

        $status = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        foreach (Contact::factory()->count(3)->create() as $contact) {
            ContactWorkflowProfile::query()->create([
                'contact_id' => $contact->getKey(),
                'contact_status_id' => $status->getKey(),
                'last_status_changed_at' => now(),
            ]);

            $task = Task::factory()->relatedTo($contact)->create([
                'source' => Task::SOURCE_MANUAL,
                'title' => 'Call this contact',
            ]);

            app(RecordManualTaskAutomationBehaviorAction::class)->handle(
                task: $task,
                actor: $actor,
            );
        }

        $this->assertDatabaseCount('automation_behavior_occurrences', 3);
        $this->assertDatabaseCount('automation_opportunities', 1);

        $opportunity = AutomationOpportunity::query()->firstOrFail();

        $this->assertSame(3, $opportunity->occurrence_count);
        $this->assertSame(3, $opportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $opportunity->status);
        $this->assertNotNull($opportunity->eligible_at);
    }

    public function test_it_records_compound_pattern_after_recent_manual_status_change_by_same_actor(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $toStatus = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        $changedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $taskCreatedAt = $changedAt->addMinutes(5);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $toStatus->getKey(),
            'last_status_changed_at' => $changedAt,
            'meta' => [
                'last_status_change' => [
                    'from_contact_status_id' => $fromStatus->getKey(),
                    'to_contact_status_id' => $toStatus->getKey(),
                    'reason' => 'crm_manual_status_update',
                    'source' => 'crm',
                    'actor_type' => $actor->getMorphClass(),
                    'actor_id' => $actor->getKey(),
                    'changed_at' => $changedAt->toISOString(),
                    'meta' => [
                        'source' => 'contact_show_status_form',
                    ],
                ],
            ],
        ]);

        $task = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call this contact',
            'task_template_key' => null,
            'created_at' => $taskCreatedAt,
            'updated_at' => $taskCreatedAt,
        ]);

        app(RecordManualTaskAutomationBehaviorAction::class)->handle(
            task: $task,
            actor: $actor,
        );

        $compoundOccurrence = AutomationBehaviorOccurrence::query()
            ->forAction(RecordManualTaskAutomationBehaviorAction::COMPOUND_ACTION_KEY)
            ->firstOrFail();

        $this->assertSame('prospect', $compoundOccurrence->fingerprint_parts['from_status_key']);
        $this->assertSame('attempting_contact', $compoundOccurrence->fingerprint_parts['to_status_key']);
        $this->assertSame('call this contact', $compoundOccurrence->fingerprint_parts['normalized_title']);
        $this->assertSame('Prospect', $compoundOccurrence->context['from_status_name']);
        $this->assertSame('Attempting Contact', $compoundOccurrence->context['to_status_name']);
        $this->assertSame(
            'manual_status_change_then_manual_task_creation',
            $compoundOccurrence->meta['pattern'],
        );

        $this->assertDatabaseCount('automation_behavior_occurrences', 2);
        $this->assertDatabaseCount('automation_opportunities', 2);
    }

    public function test_it_does_not_record_compound_pattern_when_status_change_is_too_old(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $toStatus = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        $changedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $taskCreatedAt = $changedAt->addMinutes(
            RecordManualTaskAutomationBehaviorAction::RELATED_ACTION_WINDOW_MINUTES + 1,
        );

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $toStatus->getKey(),
            'last_status_changed_at' => $changedAt,
            'meta' => [
                'last_status_change' => [
                    'from_contact_status_id' => $fromStatus->getKey(),
                    'to_contact_status_id' => $toStatus->getKey(),
                    'reason' => 'crm_manual_status_update',
                    'source' => 'crm',
                    'actor_type' => $actor->getMorphClass(),
                    'actor_id' => $actor->getKey(),
                    'changed_at' => $changedAt->toISOString(),
                    'meta' => [
                        'source' => 'contact_show_status_form',
                    ],
                ],
            ],
        ]);

        $task = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call this contact',
            'created_at' => $taskCreatedAt,
            'updated_at' => $taskCreatedAt,
        ]);

        app(RecordManualTaskAutomationBehaviorAction::class)->handle(
            task: $task,
            actor: $actor,
        );

        $this->assertDatabaseMissing('automation_behavior_occurrences', [
            'action_key' => RecordManualTaskAutomationBehaviorAction::COMPOUND_ACTION_KEY,
        ]);

        $this->assertDatabaseCount('automation_behavior_occurrences', 1);
        $this->assertDatabaseCount('automation_opportunities', 1);
    }

    public function test_it_does_not_record_compound_pattern_for_different_actor(): void
    {
        $statusActor = User::factory()->create();
        $taskActor = User::factory()->create();
        $contact = Contact::factory()->create();

        $fromStatus = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $toStatus = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        $changedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $taskCreatedAt = $changedAt->addMinutes(2);

        ContactWorkflowProfile::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $toStatus->getKey(),
            'last_status_changed_at' => $changedAt,
            'meta' => [
                'last_status_change' => [
                    'from_contact_status_id' => $fromStatus->getKey(),
                    'to_contact_status_id' => $toStatus->getKey(),
                    'reason' => 'crm_manual_status_update',
                    'source' => 'crm',
                    'actor_type' => $statusActor->getMorphClass(),
                    'actor_id' => $statusActor->getKey(),
                    'changed_at' => $changedAt->toISOString(),
                    'meta' => [
                        'source' => 'contact_show_status_form',
                    ],
                ],
            ],
        ]);

        $task = Task::factory()->relatedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call this contact',
            'created_at' => $taskCreatedAt,
            'updated_at' => $taskCreatedAt,
        ]);

        app(RecordManualTaskAutomationBehaviorAction::class)->handle(
            task: $task,
            actor: $taskActor,
        );

        $this->assertDatabaseMissing('automation_behavior_occurrences', [
            'action_key' => RecordManualTaskAutomationBehaviorAction::COMPOUND_ACTION_KEY,
        ]);
    }

    public function test_repeated_compound_patterns_for_distinct_contacts_become_eligible(): void
    {
        $actor = User::factory()->create();

        $fromStatus = ContactStatus::query()->create([
            'key' => 'prospect',
            'name' => 'Prospect',
            'is_active' => true,
        ]);

        $toStatus = ContactStatus::query()->create([
            'key' => 'attempting_contact',
            'name' => 'Attempting Contact',
            'is_active' => true,
        ]);

        foreach (Contact::factory()->count(3)->create() as $index => $contact) {
            $changedAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC')
                ->addMinutes($index * 20);

            ContactWorkflowProfile::query()->create([
                'contact_id' => $contact->getKey(),
                'contact_status_id' => $toStatus->getKey(),
                'last_status_changed_at' => $changedAt,
                'meta' => [
                    'last_status_change' => [
                        'from_contact_status_id' => $fromStatus->getKey(),
                        'to_contact_status_id' => $toStatus->getKey(),
                        'reason' => 'crm_manual_status_update',
                        'source' => 'crm',
                        'actor_type' => $actor->getMorphClass(),
                        'actor_id' => $actor->getKey(),
                        'changed_at' => $changedAt->toISOString(),
                        'meta' => [
                            'source' => 'contact_show_status_form',
                        ],
                    ],
                ],
            ]);

            $taskCreatedAt = $changedAt->addMinutes(3);

            $task = Task::factory()->relatedTo($contact)->create([
                'source' => Task::SOURCE_MANUAL,
                'title' => 'Call this contact',
                'task_template_key' => null,
                'created_at' => $taskCreatedAt,
                'updated_at' => $taskCreatedAt,
            ]);

            app(RecordManualTaskAutomationBehaviorAction::class)->handle(
                task: $task,
                actor: $actor,
            );
        }

        $compoundOpportunity = AutomationOpportunity::query()
            ->forAction(RecordManualTaskAutomationBehaviorAction::COMPOUND_ACTION_KEY)
            ->firstOrFail();

        $this->assertSame(3, $compoundOpportunity->occurrence_count);
        $this->assertSame(3, $compoundOpportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $compoundOpportunity->status);
        $this->assertNotNull($compoundOpportunity->eligible_at);
    }
}
