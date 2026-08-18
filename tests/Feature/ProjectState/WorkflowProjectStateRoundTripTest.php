<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Workflow\Events\ContactWorkflowStatusChanged;
use App\Modules\Workflow\Models\ContactWorkflowProfile;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Jobs\PublishAutomationEventOutboxEventsJob;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkflowProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_workflow_and_automation_event_state_round_trip_without_replaying_imported_events(): void
    {
        Queue::fake();
        Event::fake([
            AutomationEventRecorded::class,
            ContactWorkflowStatusChanged::class,
        ]);

        $eventId = (string) Str::uuid();
        $this->seedSourceState($eventId);

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertCount(
            1,
            $document['sections']['workflow']['tables']['contact_workflow_profiles'],
        );
        $this->assertCount(
            1,
            $document['sections']['automation_events']['tables']['automation_event_outbox_events'],
        );
        $this->assertCount(
            1,
            $document['sections']['automation_events']['tables']['automation_event_consumer_receipts'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);
        $this->assertStringContainsString(
            '[automation_event_outbox_events.status] [processing] → [paused]',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('contact_statuses', [
            'id' => 140,
            'key' => 'new',
            'name' => 'New',
        ]);
        $this->assertDatabaseHas('contact_statuses', [
            'id' => 141,
            'key' => 'engaged',
            'name' => 'Engaged',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => 60,
            'email' => 'workflow-state@example.com',
        ]);
        $this->assertDatabaseHas('contact_workflow_profiles', [
            'id' => 70,
            'contact_id' => 60,
            'contact_status_id' => 141,
            'assigned_to_type' => 'App\\Modules\\Core\\Models\\Contact',
            'assigned_to_id' => 60,
        ]);

        $profileMeta = $this->jsonColumn(
            table: 'contact_workflow_profiles',
            id: 70,
            column: 'meta',
        );

        $this->assertSame(
            140,
            $profileMeta['last_status_change']['from_contact_status_id'],
        );
        $this->assertSame(
            141,
            $profileMeta['last_status_change']['to_contact_status_id'],
        );

        $this->assertDatabaseHas('automation_event_outbox_events', [
            'id' => 80,
            'event_id' => $eventId,
            'contact_id' => 60,
            'subject_type' => ContactWorkflowProfile::class,
            'subject_id' => '70',
            'status' => 'paused',
            'claim_token' => null,
            'claim_expires_at' => null,
        ]);

        $eventPayload = $this->jsonColumn(
            table: 'automation_event_outbox_events',
            id: 80,
            column: 'payload',
        );

        $this->assertSame(60, $eventPayload['workflow_transition']['contact_id']);
        $this->assertSame(
            70,
            $eventPayload['workflow_transition']['contact_workflow_profile_id'],
        );
        $this->assertSame(
            140,
            $eventPayload['workflow_transition']['from_contact_status_id'],
        );
        $this->assertSame(
            141,
            $eventPayload['workflow_transition']['to_contact_status_id'],
        );

        $this->assertDatabaseHas('automation_event_consumer_receipts', [
            'id' => 90,
            'event_id' => $eventId,
            'consumer' => 'workflow.contact_status_changed',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'automation_events',
            'source_table' => 'automation_event_outbox_events',
            'source_record_id' => '80',
            'original_status' => 'processing',
            'state' => 'pending',
        ]);

        app(ProjectStateResumeManager::class)
            ->resume(ProjectStateResumeManager::CATEGORY_AUTOMATION_EVENTS);

        $this->assertDatabaseHas('automation_event_outbox_events', [
            'id' => 80,
            'status' => 'pending',
            'claim_token' => null,
            'claim_expires_at' => null,
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'automation_events',
            'state' => 'pending',
        ]);

        Queue::assertPushed(PublishAutomationEventOutboxEventsJob::class, 1);
        Event::assertNotDispatched(AutomationEventRecorded::class);
        Event::assertNotDispatched(ContactWorkflowStatusChanged::class);
    }

    public function test_validation_rejects_a_broken_status_reference_inside_workflow_history(): void
    {
        $this->seedSourceState((string) Str::uuid());

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['workflow']['tables']['contact_workflow_profiles'][0]
            ['meta']['last_status_change']['to_contact_status_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'contact_workflow_profiles.0.meta.last_status_change.to_contact_status_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('contact_workflow_profiles', [
            'id' => 70,
        ]);
    }

    private function seedSourceState(string $eventId): void
    {
        $now = now()->startOfSecond();
        $claimToken = (string) Str::uuid();

        DB::table('contact_statuses')->insert([
            [
                'id' => 40,
                'key' => 'new',
                'name' => 'New',
                'description' => 'New workflow contact.',
                'category' => 'workflow',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 10,
                'source_version' => '1',
                'meta' => json_encode(['source' => 'preset']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 41,
                'key' => 'engaged',
                'name' => 'Engaged',
                'description' => 'Engaged workflow contact.',
                'category' => 'workflow',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => true,
                'customized_at' => $now,
                'sort_order' => 20,
                'source_version' => '1',
                'meta' => json_encode(['source' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Workflow',
            'last_name' => 'State',
            'name' => 'Workflow State',
            'email' => 'workflow-state@example.com',
            'phone' => '+15555550123',
            'source' => 'production',
            'subsource' => null,
            'contact_import_batch_id' => null,
            'last_contacted_at' => null,
            'last_activity_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_workflow_profiles')->insert([
            'id' => 70,
            'contact_id' => 60,
            'contact_status_id' => 41,
            'assigned_to_type' => 'App\\Modules\\Core\\Models\\Contact',
            'assigned_to_id' => 60,
            'last_status_changed_at' => $now,
            'meta' => json_encode([
                'last_status_change' => [
                    'from_contact_status_id' => 40,
                    'to_contact_status_id' => 41,
                    'reason' => 'project_state_test',
                    'source' => 'crm',
                    'actor_type' => null,
                    'actor_id' => null,
                    'changed_at' => $now->toISOString(),
                    'meta' => ['surface' => 'test'],
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('automation_event_outbox_events')->insert([
            'id' => 80,
            'event_id' => $eventId,
            'idempotency_key' => 'workflow-state-event',
            'event_key' => ContactWorkflowStatusChanged::AUTOMATION_EVENT_KEY,
            'contact_id' => 60,
            'subject_type' => ContactWorkflowProfile::class,
            'subject_id' => '70',
            'occurred_at' => $now,
            'payload' => json_encode([
                'workflow_transition' => [
                    'contact_id' => 60,
                    'contact_workflow_profile_id' => 70,
                    'from_contact_status_id' => 40,
                    'to_contact_status_id' => 41,
                    'reason' => 'project_state_test',
                    'source' => 'crm',
                    'actor_type' => null,
                    'actor_id' => null,
                    'occurred_at' => $now->toISOString(),
                    'meta' => ['surface' => 'test'],
                ],
            ]),
            'meta' => json_encode([
                'source_module' => 'workflow',
                'source' => 'contact_workflow_status_changed',
            ]),
            'status' => 'processing',
            'available_at' => $now,
            'claim_token' => $claimToken,
            'claim_expires_at' => $now->copy()->addMinutes(5),
            'attempts' => 1,
            'last_attempted_at' => $now,
            'published_at' => null,
            'last_error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('automation_event_consumer_receipts')->insert([
            'id' => 90,
            'event_id' => $eventId,
            'consumer' => 'workflow.contact_status_changed',
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        $now = now()->startOfSecond();

        DB::table('automation_event_consumer_receipts')->delete();
        DB::table('automation_event_outbox_events')->delete();
        DB::table('contact_workflow_profiles')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_statuses')->delete();

        DB::table('contact_statuses')->insert([
            [
                'id' => 140,
                'key' => 'new',
                'name' => 'Fresh preset new',
                'description' => null,
                'category' => 'workflow',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 10,
                'source_version' => '2',
                'meta' => json_encode(['source' => 'fresh-preset']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 141,
                'key' => 'engaged',
                'name' => 'Fresh preset engaged',
                'description' => null,
                'category' => 'workflow',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 20,
                'source_version' => '2',
                'meta' => json_encode(['source' => 'fresh-preset']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonColumn(string $table, int $id, string $column): array
    {
        $value = DB::table($table)
            ->where('id', $id)
            ->value($column);

        $decoded = is_string($value)
            ? json_decode($value, true, flags: JSON_THROW_ON_ERROR)
            : $value;

        $this->assertIsArray($decoded);

        return $decoded;
    }
}