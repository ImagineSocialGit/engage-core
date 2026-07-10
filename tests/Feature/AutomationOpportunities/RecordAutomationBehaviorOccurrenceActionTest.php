<?php

namespace Tests\Feature\AutomationOpportunities;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationOpportunities\Actions\RecordAutomationBehaviorOccurrenceAction;
use App\Support\AutomationOpportunities\Data\AutomationBehaviorData;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RecordAutomationBehaviorOccurrenceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_behavior_and_creates_matching_observing_opportunity(): void
    {
        $actor = User::factory()->create();
        $subject = Contact::factory()->create();
        $occurredAt = CarbonImmutable::parse('2026-07-10 15:30:00', 'UTC');

        $occurrence = app(RecordAutomationBehaviorOccurrenceAction::class)->handle(
            AutomationBehaviorData::make(
                actionKey: 'task.created_manually',
                actor: $actor,
                subject: $subject,
                capabilityKey: 'tasks.create_task',
                fingerprintParts: [
                    'related_subject_type' => 'contact',
                    'contact_status_key' => 'attempting_contact',
                    'normalized_title' => 'call this contact',
                ],
                context: [
                    'contact_status_name' => 'Attempting Contact',
                    'task_title' => 'Call this contact',
                ],
                meta: [
                    'source' => 'contact_show_task_form',
                ],
                occurredAt: $occurredAt,
            ),
        );

        $this->assertInstanceOf(AutomationBehaviorOccurrence::class, $occurrence);
        $this->assertSame('task.created_manually', $occurrence->action_key);
        $this->assertSame($actor->getMorphClass(), $occurrence->actor_type);
        $this->assertSame($actor->getKey(), $occurrence->actor_id);
        $this->assertSame($subject->getMorphClass(), $occurrence->subject_type);
        $this->assertSame($subject->getKey(), $occurrence->subject_id);
        $this->assertSame('tasks.create_task', $occurrence->capability_key);
        $this->assertSame('attempting_contact', $occurrence->fingerprint_parts['contact_status_key']);
        $this->assertSame('Attempting Contact', $occurrence->context['contact_status_name']);
        $this->assertSame('contact_show_task_form', $occurrence->meta['source']);
        $this->assertTrue($occurrence->occurred_at->equalTo($occurredAt));

        $opportunity = AutomationOpportunity::query()
            ->where('action_key', 'task.created_manually')
            ->where('fingerprint', $occurrence->fingerprint)
            ->firstOrFail();

        $this->assertSame(AutomationOpportunity::STATUS_OBSERVING, $opportunity->status);
        $this->assertSame(1, $opportunity->occurrence_count);
        $this->assertSame(1, $opportunity->distinct_subject_count);
        $this->assertSame(1, $opportunity->distinct_actor_count);
        $this->assertSame('tasks.create_task', $opportunity->capability_key);
        $this->assertTrue($opportunity->first_occurred_at->equalTo($occurredAt));
        $this->assertTrue($opportunity->last_occurred_at->equalTo($occurredAt));
    }

    public function test_it_reuses_one_opportunity_for_equivalent_fingerprint_parts(): void
    {
        $actor = User::factory()->create();

        foreach (Contact::factory()->count(2)->create() as $contact) {
            app(RecordAutomationBehaviorOccurrenceAction::class)->handle(
                AutomationBehaviorData::make(
                    actionKey: 'task.created_manually',
                    actor: $actor,
                    subject: $contact,
                    fingerprintParts: [
                        'contact_status_key' => 'attempting_contact',
                        'task' => [
                            'priority' => 'normal',
                            'title' => 'call this contact',
                        ],
                    ],
                ),
            );
        }

        $this->assertDatabaseCount('automation_behavior_occurrences', 2);
        $this->assertDatabaseCount('automation_opportunities', 1);

        $opportunity = AutomationOpportunity::query()->firstOrFail();

        $this->assertSame(2, $opportunity->occurrence_count);
        $this->assertSame(2, $opportunity->distinct_subject_count);
        $this->assertSame(1, $opportunity->distinct_actor_count);
    }

    public function test_it_rejects_behavior_without_action_key_or_fingerprint_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Automation behavior requires a non-empty action key and fingerprint parts.'
        );

        app(RecordAutomationBehaviorOccurrenceAction::class)->handle(
            AutomationBehaviorData::make(
                actionKey: '',
                fingerprintParts: [],
            ),
        );
    }
}
