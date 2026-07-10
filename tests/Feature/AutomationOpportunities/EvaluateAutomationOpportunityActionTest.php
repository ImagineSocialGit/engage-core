<?php

namespace Tests\Feature\AutomationOpportunities;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Support\AutomationOpportunities\Actions\EvaluateAutomationOpportunityAction;
use App\Support\AutomationOpportunities\Models\AutomationBehaviorOccurrence;
use App\Support\AutomationOpportunities\Models\AutomationOpportunity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class EvaluateAutomationOpportunityActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_moves_observing_opportunity_to_eligible_after_three_occurrences_for_three_distinct_subjects(): void
    {
        $actor = User::factory()->create();
        $subjects = Contact::factory()->count(3)->create();
        $fingerprint = str_repeat('a', 64);

        foreach ($subjects as $index => $subject) {
            $occurrence = $this->occurrence(
                fingerprint: $fingerprint,
                actor: $actor,
                subject: $subject,
                occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC')->addMinutes($index),
            );

            $opportunity = app(EvaluateAutomationOpportunityAction::class)->handle($occurrence);
        }

        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $opportunity->status);
        $this->assertSame(3, $opportunity->occurrence_count);
        $this->assertSame(3, $opportunity->distinct_subject_count);
        $this->assertSame(1, $opportunity->distinct_actor_count);
        $this->assertNotNull($opportunity->eligible_at);
    }

    public function test_it_counts_distinct_subjects_and_actors_by_full_morph_pair(): void
    {
        $fingerprint = str_repeat('b', 64);

        $user = User::factory()->create();
        $teamMember = TeamMember::factory()->create();

        $contact = Contact::factory()->create();

        $first = $this->occurrence(
            fingerprint: $fingerprint,
            actor: $user,
            subject: $contact,
            occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC'),
        );

        $second = $this->occurrence(
            fingerprint: $fingerprint,
            actor: $teamMember,
            subject: $teamMember,
            occurredAt: CarbonImmutable::parse('2026-07-10 11:00:00', 'UTC'),
        );

        app(EvaluateAutomationOpportunityAction::class)->handle(
            occurrence: $first,
            minimumOccurrences: 2,
            minimumDistinctSubjects: 2,
        );

        $opportunity = app(EvaluateAutomationOpportunityAction::class)->handle(
            occurrence: $second,
            minimumOccurrences: 2,
            minimumDistinctSubjects: 2,
        );

        $this->assertSame(2, $opportunity->occurrence_count);
        $this->assertSame(2, $opportunity->distinct_subject_count);
        $this->assertSame(2, $opportunity->distinct_actor_count);
        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $opportunity->status);
    }

    public function test_it_only_counts_occurrences_inside_the_observation_window(): void
    {
        $fingerprint = str_repeat('c', 64);
        $actor = User::factory()->create();
        $subjects = Contact::factory()->count(3)->create();

        $oldOccurrence = $this->occurrence(
            fingerprint: $fingerprint,
            actor: $actor,
            subject: $subjects[0],
            occurredAt: CarbonImmutable::parse('2026-05-01 10:00:00', 'UTC'),
        );

        $recentOccurrence = $this->occurrence(
            fingerprint: $fingerprint,
            actor: $actor,
            subject: $subjects[1],
            occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC'),
        );

        $latestOccurrence = $this->occurrence(
            fingerprint: $fingerprint,
            actor: $actor,
            subject: $subjects[2],
            occurredAt: CarbonImmutable::parse('2026-07-10 11:00:00', 'UTC'),
        );

        app(EvaluateAutomationOpportunityAction::class)->handle($oldOccurrence);
        app(EvaluateAutomationOpportunityAction::class)->handle($recentOccurrence);

        $opportunity = app(EvaluateAutomationOpportunityAction::class)->handle(
            occurrence: $latestOccurrence,
            minimumOccurrences: 3,
            minimumDistinctSubjects: 3,
            windowDays: 30,
        );

        $this->assertSame(2, $opportunity->occurrence_count);
        $this->assertSame(2, $opportunity->distinct_subject_count);
        $this->assertSame(AutomationOpportunity::STATUS_OBSERVING, $opportunity->status);
        $this->assertTrue(
            $opportunity->first_occurred_at->equalTo($recentOccurrence->occurred_at)
        );
        $this->assertTrue(
            $opportunity->last_occurred_at->equalTo($latestOccurrence->occurred_at)
        );
    }

    public function test_it_supports_custom_thresholds(): void
    {
        $fingerprint = str_repeat('d', 64);
        $actor = User::factory()->create();
        $subjects = Contact::factory()->count(2)->create();

        foreach ($subjects as $index => $subject) {
            $occurrence = $this->occurrence(
                fingerprint: $fingerprint,
                actor: $actor,
                subject: $subject,
                occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC')->addMinutes($index),
            );

            $opportunity = app(EvaluateAutomationOpportunityAction::class)->handle(
                occurrence: $occurrence,
                minimumOccurrences: 2,
                minimumDistinctSubjects: 2,
                windowDays: 14,
            );
        }

        $this->assertSame(AutomationOpportunity::STATUS_ELIGIBLE, $opportunity->status);
        $this->assertSame(2, $opportunity->occurrence_count);
        $this->assertSame(2, $opportunity->distinct_subject_count);
        $this->assertSame(14, data_get($opportunity->meta, 'eligibility.window_days'));
    }

    public function test_it_does_not_reset_terminal_or_presentational_statuses_to_eligible(): void
    {
        foreach ([
            AutomationOpportunity::STATUS_SUGGESTED,
            AutomationOpportunity::STATUS_DISMISSED,
            AutomationOpportunity::STATUS_CONVERTED,
            AutomationOpportunity::STATUS_INVALIDATED,
        ] as $index => $status) {
            $fingerprint = str_pad((string) $index, 64, (string) $index);

            $opportunity = AutomationOpportunity::query()->create([
                'action_key' => 'task.created_manually',
                'fingerprint' => $fingerprint,
                'status' => $status,
                'occurrence_count' => 0,
                'distinct_subject_count' => 0,
                'distinct_actor_count' => 0,
            ]);

            $subjects = Contact::factory()->count(3)->create();

            foreach ($subjects as $subjectIndex => $subject) {
                $occurrence = $this->occurrence(
                    fingerprint: $fingerprint,
                    actor: User::factory()->create(),
                    subject: $subject,
                    occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC')->addMinutes($subjectIndex),
                );

                app(EvaluateAutomationOpportunityAction::class)->handle($occurrence);
            }

            $this->assertSame($status, $opportunity->refresh()->status);
        }
    }

    public function test_it_rejects_invalid_thresholds(): void
    {
        $occurrence = $this->occurrence(
            fingerprint: str_repeat('e', 64),
            actor: User::factory()->create(),
            subject: Contact::factory()->create(),
            occurredAt: CarbonImmutable::parse('2026-07-10 10:00:00', 'UTC'),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Minimum automation opportunity occurrences must be at least 1.'
        );

        app(EvaluateAutomationOpportunityAction::class)->handle(
            occurrence: $occurrence,
            minimumOccurrences: 0,
        );
    }

    private function occurrence(
        string $fingerprint,
        User|TeamMember $actor,
        Contact|TeamMember $subject,
        CarbonImmutable $occurredAt,
    ): AutomationBehaviorOccurrence {
        return AutomationBehaviorOccurrence::query()->create([
            'action_key' => 'task.created_manually',
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'capability_key' => 'tasks.create_task',
            'fingerprint' => $fingerprint,
            'fingerprint_parts' => [
                'normalized_title' => 'call this contact',
            ],
            'context' => [
                'task_title' => 'Call this contact',
            ],
            'meta' => [],
            'occurred_at' => $occurredAt,
        ]);
    }
}
