<?php

namespace Tests\Feature\ProjectState;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Tasks\Models\Task;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationOpportunitiesProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_automation_opportunity_state_round_trips_with_safe_subjects_and_detached_user_actors(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(10, $document['version']);
        $this->assertCount(
            5,
            $document['sections']['automation_opportunities']['tables']['automation_behavior_occurrences'],
        );
        $this->assertCount(
            1,
            $document['sections']['automation_opportunities']['tables']['automation_opportunities'],
        );

        $this->prepareCleanTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);
        $this->assertStringContainsString(
            'Import will clear [automation_behavior_occurrences.actor_type] for 5 row(s).',
            $warnings,
        );
        $this->assertStringContainsString(
            'Import will clear [automation_behavior_occurrences.actor_id] for 5 row(s).',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('users', [
            'id' => 110,
            'email' => 'target-owner@example.com',
        ]);
        $this->assertDatabaseMissing('users', [
            'id' => 10,
        ]);

        $this->assertDatabaseHas('team_members', [
            'id' => 20,
            'user_id' => null,
            'email' => 'advisor@example.com',
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => 40,
            'title' => 'Review the contact file',
        ]);

        foreach ([30, 31, 32] as $contactId) {
            $this->assertDatabaseHas('contacts', [
                'id' => $contactId,
            ]);
        }

        foreach ([50, 51, 52, 53, 54] as $occurrenceId) {
            $this->assertDatabaseHas('automation_behavior_occurrences', [
                'id' => $occurrenceId,
                'actor_type' => null,
                'actor_id' => null,
            ]);
        }

        $this->assertDatabaseHas('automation_behavior_occurrences', [
            'id' => 50,
            'subject_type' => Contact::class,
            'subject_id' => 30,
        ]);
        $this->assertDatabaseHas('automation_behavior_occurrences', [
            'id' => 53,
            'subject_type' => Task::class,
            'subject_id' => 40,
        ]);
        $this->assertDatabaseHas('automation_behavior_occurrences', [
            'id' => 54,
            'subject_type' => TeamMember::class,
            'subject_id' => 20,
        ]);

        $this->assertDatabaseHas('automation_opportunities', [
            'id' => 60,
            'action_key' => 'task.created_manually',
            'fingerprint' => hash('sha256', 'review-contact-file'),
            'status' => 'dismissed',
            'occurrence_count' => 3,
            'distinct_subject_count' => 3,
            'distinct_actor_count' => 1,
        ]);

        $opportunity = DB::table('automation_opportunities')
            ->where('id', 60)
            ->first();

        $this->assertNotNull($opportunity);
        $this->assertNotNull($opportunity->eligible_at);
        $this->assertNotNull($opportunity->suggested_at);
        $this->assertNotNull($opportunity->dismissed_at);
        $this->assertNotNull($opportunity->dismissed_until);
        $this->assertEquals(
            ['task_title' => 'Review the contact file'],
            json_decode((string) $opportunity->context, true, flags: JSON_THROW_ON_ERROR),
        );
        $this->assertEquals(
            [
                'eligibility' => [
                    'minimum_occurrences' => 3,
                    'minimum_distinct_subjects' => 3,
                    'window_days' => 30,
                ],
            ],
            json_decode((string) $opportunity->meta, true, flags: JSON_THROW_ON_ERROR),
        );

        $this->assertDatabaseCount('project_state_resume_items', 0);
    }

    public function test_validation_rejects_an_unsupported_automation_occurrence_subject_type(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['automation_opportunities']['tables']['automation_behavior_occurrences'][0]['subject_type'] = 'App\\Modules\\Scheduling\\Models\\Appointment';
        $document['sections']['automation_opportunities']['tables']['automation_behavior_occurrences'][0]['subject_id'] = 999999;

        $this->prepareCleanTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'Project-state polymorphic reference [automation_behavior_occurrences.0.subject_type] uses unsupported type [App\\Modules\\Scheduling\\Models\\Appointment].',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('automation_behavior_occurrences', [
            'id' => 50,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();
        $fingerprint = hash('sha256', 'review-contact-file');

        DB::table('users')->insert([
            'id' => 10,
            'name' => 'Source Owner',
            'email' => 'source-owner@example.com',
            'email_verified_at' => null,
            'password' => '$2y$12$projectStateAutomationSourcePasswordHash',
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('team_members')->insert([
            'id' => 20,
            'user_id' => 10,
            'name' => 'Production Advisor',
            'email' => 'advisor@example.com',
            'phone' => '+15555550999',
            'role' => 'advisor',
            'is_active' => true,
            'meta' => json_encode(['territory' => 'central']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([30, 31, 32] as $index => $contactId) {
            DB::table('contacts')->insert([
                'id' => $contactId,
                'first_name' => 'Contact',
                'last_name' => (string) ($index + 1),
                'name' => 'Contact '.($index + 1),
                'email' => 'automation-contact-'.($index + 1).'@example.com',
                'phone' => null,
                'source' => 'production',
                'subsource' => null,
                'contact_import_batch_id' => null,
                'last_contacted_at' => null,
                'last_activity_at' => $now,
                'meta' => json_encode(['owner' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('tasks')->insert([
            'id' => 40,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
            'responsible_party' => 'internal',
            'responsible_type' => TeamMember::class,
            'responsible_id' => 20,
            'task_template_id' => null,
            'task_template_key' => null,
            'source' => 'manual',
            'title' => 'Review the contact file',
            'description' => 'Review the current file before follow-up.',
            'status' => 'open',
            'priority' => 'normal',
            'due_at' => $now->copy()->addDay(),
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $occurrences = [];

        foreach ([30, 31, 32] as $index => $contactId) {
            $occurredAt = $now->copy()->subDays(3 - $index);
            $occurrences[] = $this->occurrenceRow(
                id: 50 + $index,
                actionKey: 'task.created_manually',
                subjectType: Contact::class,
                subjectId: $contactId,
                fingerprint: $fingerprint,
                fingerprintParts: [
                    'normalized_title' => 'review the contact file',
                    'subject_link_types' => [Contact::class],
                ],
                context: [
                    'task_title' => 'Review the contact file',
                    'contact_id' => $contactId,
                ],
                meta: [
                    'source' => 'task_controller.store',
                ],
                occurredAt: $occurredAt,
            );
        }

        $occurrences[] = $this->occurrenceRow(
            id: 53,
            actionKey: 'task.completed_manually',
            subjectType: Task::class,
            subjectId: 40,
            fingerprint: hash('sha256', 'task-completion-evidence'),
            fingerprintParts: [
                'normalized_title' => 'review the contact file',
            ],
            context: [
                'task_id' => 40,
                'task_title' => 'Review the contact file',
            ],
            meta: [
                'pattern_role' => 'manual_task_completion_evidence',
            ],
            occurredAt: $now->copy()->subHour(),
        );

        $occurrences[] = $this->occurrenceRow(
            id: 54,
            actionKey: 'internal.advisor_reviewed_manually',
            subjectType: TeamMember::class,
            subjectId: 20,
            fingerprint: hash('sha256', 'advisor-review-evidence'),
            fingerprintParts: [
                'role' => 'advisor',
            ],
            context: [
                'team_member_id' => 20,
            ],
            meta: [
                'pattern_role' => 'internal_review_evidence',
            ],
            occurredAt: $now,
        );

        DB::table('automation_behavior_occurrences')->insert($occurrences);

        DB::table('automation_opportunities')->insert([
            'id' => 60,
            'action_key' => 'task.created_manually',
            'fingerprint' => $fingerprint,
            'capability_key' => 'tasks.create_task',
            'status' => 'dismissed',
            'occurrence_count' => 3,
            'distinct_subject_count' => 3,
            'distinct_actor_count' => 1,
            'first_occurred_at' => $now->copy()->subDays(3),
            'last_occurred_at' => $now->copy()->subDay(),
            'eligible_at' => $now->copy()->subDay(),
            'suggested_at' => $now->copy()->subHours(12),
            'dismissed_at' => $now->copy()->subHours(6),
            'dismissed_until' => $now->copy()->addDays(7),
            'converted_at' => null,
            'invalidated_at' => null,
            'context' => json_encode([
                'task_title' => 'Review the contact file',
            ]),
            'meta' => json_encode([
                'eligibility' => [
                    'minimum_occurrences' => 3,
                    'minimum_distinct_subjects' => 3,
                    'window_days' => 30,
                ],
            ]),
            'created_at' => $now->copy()->subDays(3),
            'updated_at' => $now,
        ]);
    }

    private function prepareCleanTarget(): void
    {
        DB::table('automation_opportunities')->delete();
        DB::table('automation_behavior_occurrences')->delete();
        DB::table('tasks')->delete();
        DB::table('team_member_notification_preferences')->delete();
        DB::table('team_members')->delete();
        DB::table('contacts')->delete();
        DB::table('users')->delete();

        $now = now()->startOfSecond();

        DB::table('users')->insert([
            'id' => 110,
            'name' => 'Target Owner',
            'email' => 'target-owner@example.com',
            'email_verified_at' => null,
            'password' => '$2y$12$projectStateAutomationTargetPasswordHash',
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $fingerprintParts
     * @param array<string, mixed> $context
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function occurrenceRow(
        int $id,
        string $actionKey,
        string $subjectType,
        int $subjectId,
        string $fingerprint,
        array $fingerprintParts,
        array $context,
        array $meta,
        mixed $occurredAt,
    ): array {
        return [
            'id' => $id,
            'action_key' => $actionKey,
            'actor_type' => User::class,
            'actor_id' => 10,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'capability_key' => $actionKey === 'task.created_manually'
                ? 'tasks.create_task'
                : null,
            'fingerprint' => $fingerprint,
            'fingerprint_parts' => json_encode($fingerprintParts),
            'context' => json_encode($context),
            'meta' => json_encode($meta),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ];
    }
}