<?php

namespace Tests\Feature\Tasks;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Actions\RecordManualTaskAutomationBehaviorAction;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordManualTaskAfterAutomationEventBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_compound_pattern_after_recent_supported_automation_event(): void
    {
        $actor = User::factory()->create();
        $contact = Contact::factory()->create();

        $eventAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $taskCreatedAt = $eventAt->addMinutes(4);

        event(new AutomationEventRecorded(
            AutomationEventData::make(
                eventKey: 'webinar.attended',
                contactId: $contact->getKey(),
                occurredAt: $eventAt,
                meta: [
                    'source_module' => 'webinars',
                ],
            ),
        ));

        $task = Task::factory()->linkedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call after webinar',
            'task_template_key' => null,
            'created_at' => $taskCreatedAt,
            'updated_at' => $taskCreatedAt,
        ]);

        app(RecordManualTaskAutomationBehaviorAction::class)->handle(
            task: $task,
            actor: $actor,
        );

        $compoundOccurrence = AutomationBehaviorOccurrence::query()
            ->forAction(RecordManualTaskAutomationBehaviorAction::AUTOMATION_EVENT_COMPOUND_ACTION_KEY)
            ->firstOrFail();

        $this->assertSame('webinar.attended', $compoundOccurrence->fingerprint_parts['event_key']);
        $this->assertSame('call after webinar', $compoundOccurrence->fingerprint_parts['normalized_title']);
        $this->assertSame('webinar.attended', $compoundOccurrence->context['event_key']);
        $this->assertSame(
            'automation_event_then_manual_task_creation',
            $compoundOccurrence->meta['pattern'],
        );

        $evidence = AutomationBehaviorOccurrence::query()
            ->forAction(RecordAutomationEventCorrelationEvidenceAction::ACTION_KEY)
            ->firstOrFail();

        $this->assertSame(
            $evidence->getKey(),
            $compoundOccurrence->meta['trigger_occurrence_id'],
        );

        $this->assertDatabaseCount('automation_behavior_occurrences', 3);
        $this->assertDatabaseCount('automation_opportunities', 2);
    }

    public function test_it_does_not_record_compound_pattern_when_event_is_too_old(): void
    {
        $contact = Contact::factory()->create();

        $eventAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');
        $taskCreatedAt = $eventAt->addMinutes(
            RecordManualTaskAutomationBehaviorAction::RELATED_ACTION_WINDOW_MINUTES + 1,
        );

        event(new AutomationEventRecorded(
            AutomationEventData::make(
                eventKey: 'permission_invitation.accepted',
                contactId: $contact->getKey(),
                occurredAt: $eventAt,
            ),
        ));

        $task = Task::factory()->linkedTo($contact)->create([
            'source' => Task::SOURCE_MANUAL,
            'title' => 'Call this contact',
            'created_at' => $taskCreatedAt,
            'updated_at' => $taskCreatedAt,
        ]);

        app(RecordManualTaskAutomationBehaviorAction::class)->handle($task);

        $this->assertDatabaseMissing('automation_behavior_occurrences', [
            'action_key' => RecordManualTaskAutomationBehaviorAction::AUTOMATION_EVENT_COMPOUND_ACTION_KEY,
        ]);

        $this->assertDatabaseCount('automation_behavior_occurrences', 2);
        $this->assertDatabaseCount('automation_opportunities', 1);
    }

    public function test_repeated_automation_event_then_task_patterns_become_eligible(): void
    {
        $actor = User::factory()->create();

        foreach (Contact::factory()->count(3)->create() as $index => $contact) {
            $eventAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC')
                ->addMinutes($index * 20);

            event(new AutomationEventRecorded(
                AutomationEventData::make(
                    eventKey: 'webinar.missed',
                    contactId: $contact->getKey(),
                    occurredAt: $eventAt,
                    meta: [
                        'source_module' => 'webinars',
                    ],
                ),
            ));

            $taskCreatedAt = $eventAt->addMinutes(3);

            $task = Task::factory()->linkedTo($contact)->create([
                'source' => Task::SOURCE_MANUAL,
                'title' => 'Follow up with missed attendee',
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
            ->forAction(RecordManualTaskAutomationBehaviorAction::AUTOMATION_EVENT_COMPOUND_ACTION_KEY)
            ->firstOrFail();

        $this->assertSame(3, $compoundOpportunity->occurrence_count);
        $this->assertSame(3, $compoundOpportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $compoundOpportunity->status);
        $this->assertNotNull($compoundOpportunity->eligible_at);
        $this->assertSame('webinar.missed', $compoundOpportunity->context['event_key']);
    }
}
