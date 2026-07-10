<?php

namespace Tests\Feature\AutomationOpportunities;

use App\Modules\Core\Models\Contact;
use App\Modules\Tasks\Models\Task;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationOpportunities\Actions\RecordAutomationEventCorrelationEvidenceAction;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordAutomationEventCorrelationEvidenceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_supported_contact_automation_event_as_evidence_only(): void
    {
        $contact = Contact::factory()->create();
        $subject = Task::factory()->relatedTo($contact)->create();

        $occurredAt = CarbonImmutable::parse('2026-07-10 14:00:00', 'UTC');

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: 'task.completed',
                subject: $subject,
                contactId: $contact->getKey(),
                occurredAt: $occurredAt,
                meta: [
                    'source_module' => 'tasks',
                    'source' => 'crm',
                ],
            ),
        ));

        $occurrence = AutomationBehaviorOccurrence::query()
            ->forAction(RecordAutomationEventCorrelationEvidenceAction::ACTION_KEY)
            ->firstOrFail();

        $this->assertSame($contact->getMorphClass(), $occurrence->subject_type);
        $this->assertSame($contact->getKey(), $occurrence->subject_id);
        $this->assertSame('task.completed', $occurrence->fingerprint_parts['event_key']);
        $this->assertSame('task.completed', $occurrence->context['event_key']);
        $this->assertSame($subject->getMorphClass(), $occurrence->context['automation_event_subject_type']);
        $this->assertSame($subject->getKey(), $occurrence->context['automation_event_subject_id']);
        $this->assertSame('tasks', $occurrence->context['source_module']);
        $this->assertSame('crm', $occurrence->context['source']);
        $this->assertSame(
            'automation_event_correlation_evidence',
            $occurrence->meta['pattern_role'],
        );
        $this->assertTrue($occurrence->occurred_at->equalTo($occurredAt));

        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_ignores_unsupported_automation_events(): void
    {
        $contact = Contact::factory()->create();

        event(new AutomationEventRecorded(
            AutomationEventData::make(
                eventKey: 'contact.updated',
                contactId: $contact->getKey(),
            ),
        ));

        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }

    public function test_it_ignores_supported_event_without_contact(): void
    {
        event(new AutomationEventRecorded(
            AutomationEventData::make(
                eventKey: 'webinar.attended',
            ),
        ));

        $this->assertDatabaseCount('automation_behavior_occurrences', 0);
        $this->assertDatabaseCount('automation_opportunities', 0);
    }
}
