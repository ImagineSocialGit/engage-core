<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Jobs\ResumeFlowRouteProgressJob;
use App\Modules\FlowRoutes\Models\FlowRoutePoint;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\ProjectState\ProjectStateManager;
use App\Support\ProjectState\ProjectStateResumeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FlowRoutesProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_flow_routes_state_round_trips_with_definition_remapping_and_inert_runtime_work(): void
    {
        Queue::fake();

        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(10, $document['version']);

        $tables = $document['sections']['flow_routes']['tables'];

        $this->assertCount(2, $tables['flow_route_capabilities']);
        $this->assertCount(1, $tables['flow_route_capability_bindings']);
        $this->assertCount(1, $tables['flow_routes']);
        $this->assertCount(1, $tables['flow_route_trigger_bindings']);
        $this->assertCount(2, $tables['flow_route_points']);
        $this->assertCount(1, $tables['contact_flow_route_progress']);
        $this->assertCount(1, $tables['contact_flow_route_plans']);
        $this->assertCount(2, $tables['contact_flow_route_plan_items']);
        $this->assertCount(1, $tables['contact_flow_route_progress_items']);
        $this->assertCount(
            1,
            $document['sections']['messaging']['tables']['scheduled_messages'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);
        $warnings = implode(' ', $report['warnings']);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);
        $this->assertStringContainsString(
            '[contact_flow_route_progress.status] [waiting] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[contact_flow_route_plans.status] [active] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[contact_flow_route_plan_items.status] [pending] → [paused]',
            $warnings,
        );
        $this->assertStringContainsString(
            '[contact_flow_route_progress_items.status] [waiting] → [paused]',
            $warnings,
        );

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);
        Queue::assertNothingPushed();

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 150,
            'recipient_type' => Contact::class,
            'recipient_id' => 60,
            'behavior_owner_type' => FlowRoutePoint::class,
            'behavior_owner_id' => 300,
            'status' => 'paused',
        ]);

        $messageMeta = $this->jsonColumn(
            table: 'scheduled_messages',
            id: 150,
            column: 'meta',
        );

        $this->assertEquals([
            'flow_route_progress_id' => 110,
            'flow_route_plan_id' => 120,
            'flow_route_plan_item_id' => 130,
            'flow_route_progress_item_id' => 140,
            'flow_route_id' => 290,
            'flow_route_point_id' => 300,
            'flow_route_capability_id' => 280,
        ], $messageMeta['flow_route']);

        $this->assertDatabaseHas('flow_route_capabilities', [
            'id' => 280,
            'key' => 'flow_routes.wait',
            'name' => 'Production wait',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('flow_routes', [
            'id' => 290,
            'key' => 'production_route',
            'version' => 1,
            'contact_status_id' => 241,
            'owner_type' => Contact::class,
            'owner_id' => 60,
            'name' => 'Production route',
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('flow_route_trigger_bindings', [
            'id' => 291,
            'flow_route_id' => 290,
            'context_type' => Contact::class,
            'context_id' => 60,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('flow_route_capability_bindings', [
            'id' => 81,
            'flow_route_capability_id' => 280,
            'context_type' => Contact::class,
            'context_id' => 60,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('flow_route_points', [
            'id' => 300,
            'flow_route_id' => 290,
            'flow_route_capability_id' => 280,
            'key' => 'wait_for_reply',
            'next_flow_route_point_id' => 301,
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('flow_route_points', [
            'id' => 301,
            'flow_route_id' => 290,
            'flow_route_capability_id' => 282,
            'key' => 'change_status',
            'next_flow_route_point_id' => null,
            'is_customized' => true,
        ]);

        $pointDefinition = $this->jsonColumn(
            table: 'flow_route_points',
            id: 301,
            column: 'definition',
        );
        $pointSettings = $this->jsonColumn(
            table: 'flow_route_points',
            id: 301,
            column: 'settings',
        );

        $this->assertSame(241, $pointDefinition['contact_status_id']);
        $this->assertSame(241, $pointSettings['target_status_id']);

        $this->assertDatabaseHas('contact_flow_route_progress', [
            'id' => 110,
            'contact_id' => 60,
            'contact_status_id' => 241,
            'contact_workflow_profile_id' => 70,
            'flow_route_id' => 290,
            'current_flow_route_point_id' => 300,
            'status' => 'paused',
            'waiting_event_key' => 'contact.replied',
        ]);

        $progressMeta = $this->jsonColumn(
            table: 'contact_flow_route_progress',
            id: 110,
            column: 'meta',
        );

        $this->assertSame('waiting', $progressMeta['project_state']['original_status']);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'flow_routes',
            'source_table' => 'contact_flow_route_progress',
            'source_record_id' => '110',
            'original_status' => 'waiting',
            'state' => 'pending',
        ]);
        $this->assertDatabaseHas('project_state_resume_items', [
            'category' => 'scheduled_messages',
            'source_table' => 'scheduled_messages',
            'source_record_id' => '150',
            'original_status' => 'pending',
            'state' => 'pending',
        ]);
        $this->assertSame(
            240,
            $progressMeta['started_from_workflow_transition']['from_contact_status_id'],
        );
        $this->assertSame(
            241,
            $progressMeta['started_from_workflow_transition']['to_contact_status_id'],
        );
        $this->assertSame(120, $progressMeta['waiting']['flow_route_plan_id']);
        $this->assertSame(130, $progressMeta['waiting']['flow_route_plan_item_id']);
        $this->assertSame(140, $progressMeta['waiting']['flow_route_progress_item_id']);
        $this->assertSame(300, $progressMeta['waiting']['flow_route_point_id']);
        $this->assertSame(
            301,
            $progressMeta['immediate_execution_continuation']['flow_route_point_id'],
        );
        $this->assertSame(
            290,
            $progressMeta['last_version_reconciliation']['from_flow_route_id'],
        );
        $this->assertSame(
            300,
            $progressMeta['last_version_reconciliation']['from_flow_route_point_id'],
        );

        $this->assertDatabaseHas('contact_flow_route_plans', [
            'id' => 120,
            'contact_flow_route_progress_id' => 110,
            'flow_route_id' => 290,
            'status' => 'paused',
        ]);

        $planMeta = $this->jsonColumn(
            table: 'contact_flow_route_plans',
            id: 120,
            column: 'meta',
        );
        $routeSnapshot = $this->jsonColumn(
            table: 'contact_flow_route_plans',
            id: 120,
            column: 'route_snapshot',
        );

        $this->assertSame('active', $planMeta['project_state']['original_status']);
        $this->assertSame(290, $planMeta['reconciled_from_flow_route_id']);
        $this->assertSame(290, $routeSnapshot['id']);

        $this->assertDatabaseHas('contact_flow_route_plan_items', [
            'id' => 130,
            'contact_flow_route_progress_id' => 110,
            'contact_flow_route_plan_id' => 120,
            'flow_route_id' => 290,
            'flow_route_point_id' => 300,
            'flow_route_capability_id' => 280,
            'status' => 'paused',
        ]);
        $this->assertDatabaseHas('contact_flow_route_plan_items', [
            'id' => 131,
            'flow_route_point_id' => 301,
            'status' => 'paused',
        ]);

        $waitingPlanItemMeta = $this->jsonColumn(
            table: 'contact_flow_route_plan_items',
            id: 130,
            column: 'meta',
        );
        $planItemDefinition = $this->jsonColumn(
            table: 'contact_flow_route_plan_items',
            id: 131,
            column: 'definition_snapshot',
        );
        $planItemSettings = $this->jsonColumn(
            table: 'contact_flow_route_plan_items',
            id: 131,
            column: 'settings_snapshot',
        );
        $pendingPlanItemMeta = $this->jsonColumn(
            table: 'contact_flow_route_plan_items',
            id: 131,
            column: 'meta',
        );

        $this->assertSame('waiting', $waitingPlanItemMeta['project_state']['original_status']);
        $this->assertSame(300, $waitingPlanItemMeta['flow_route_point_snapshot']['id']);
        $this->assertSame(
            301,
            $waitingPlanItemMeta['flow_route_point_snapshot']['next_flow_route_point_id'],
        );
        $this->assertSame(
            280,
            $waitingPlanItemMeta['flow_route_point_snapshot']['flow_route_capability_id'],
        );

        $this->assertSame(241, $planItemDefinition['contact_status_id']);
        $this->assertSame(241, $planItemSettings['target_status_id']);
        $this->assertSame('pending', $pendingPlanItemMeta['project_state']['original_status']);
        $this->assertSame(301, $pendingPlanItemMeta['flow_route_point_snapshot']['id']);
        $this->assertNull(
            $pendingPlanItemMeta['flow_route_point_snapshot']['next_flow_route_point_id'],
        );
        $this->assertSame(
            282,
            $pendingPlanItemMeta['flow_route_point_snapshot']['flow_route_capability_id'],
        );

        $this->assertDatabaseHas('contact_flow_route_progress_items', [
            'id' => 140,
            'contact_flow_route_progress_id' => 110,
            'contact_flow_route_plan_id' => 120,
            'contact_flow_route_plan_item_id' => 130,
            'flow_route_id' => 290,
            'flow_route_point_id' => 300,
            'flow_route_capability_id' => 280,
            'created_subject_type' => Contact::class,
            'created_subject_id' => 60,
            'status' => 'paused',
        ]);

        $progressItemMeta = $this->jsonColumn(
            table: 'contact_flow_route_progress_items',
            id: 140,
            column: 'meta',
        );
        $progressItemResult = $this->jsonColumn(
            table: 'contact_flow_route_progress_items',
            id: 140,
            column: 'result_payload',
        );

        $this->assertSame('waiting', $progressItemMeta['project_state']['original_status']);
        $this->assertSame(
            290,
            $progressItemResult['meta']['flow_routes']['flow_route_id'],
        );
        $this->assertSame(
            300,
            $progressItemResult['meta']['flow_routes']['flow_route_point_id'],
        );
        $this->assertSame(
            280,
            $progressItemResult['meta']['flow_routes']['flow_route_capability_id'],
        );
        $this->assertSame(
            140,
            $progressItemResult['meta']['flow_routes']['flow_route_progress_item_id'],
        );

        app(ProjectStateResumeManager::class)
            ->resume(ProjectStateResumeManager::CATEGORY_FLOW_ROUTES);

        $this->assertDatabaseHas('contact_flow_route_progress', [
            'id' => 110,
            'status' => 'waiting',
        ]);
        $this->assertDatabaseHas('contact_flow_route_plans', [
            'id' => 120,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('contact_flow_route_plan_items', [
            'id' => 130,
            'status' => 'waiting',
        ]);
        $this->assertDatabaseHas('contact_flow_route_plan_items', [
            'id' => 131,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('contact_flow_route_progress_items', [
            'id' => 140,
            'status' => 'waiting',
        ]);
        $this->assertDatabaseMissing('project_state_resume_items', [
            'category' => 'flow_routes',
            'state' => 'pending',
        ]);

        $resumedProgressMeta = $this->jsonColumn(
            table: 'contact_flow_route_progress',
            id: 110,
            column: 'meta',
        );

        $this->assertArrayNotHasKey('project_state', $resumedProgressMeta);
        Queue::assertPushed(
            ResumeFlowRouteProgressJob::class,
            fn (ResumeFlowRouteProgressJob $job): bool =>
                $job->contactFlowRouteProgressId === 110,
        );

        Queue::assertPushed(ResumeFlowRouteProgressJob::class, 1);
    }

    public function test_validation_rejects_a_broken_flow_route_point_reference_inside_runtime_metadata(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['flow_routes']['tables']['contact_flow_route_progress'][0]['meta']['waiting']['flow_route_point_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'contact_flow_route_progress.0.meta.waiting.flow_route_point_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('contact_flow_route_progress', [
            'id' => 110,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contact_statuses')->insert([
            [
                'id' => 40,
                'key' => 'engaged',
                'name' => 'Engaged',
                'description' => 'Source status.',
                'category' => 'general',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 10,
                'source_version' => '1',
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 41,
                'key' => 'replied',
                'name' => 'Replied',
                'description' => 'Target status.',
                'category' => 'general',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 20,
                'source_version' => '1',
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Route',
            'last_name' => 'Contact',
            'name' => 'Route Contact',
            'email' => 'route-contact@example.com',
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
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'last_status_changed_at' => $now,
            'meta' => json_encode([
                'last_status_change' => [
                    'from_contact_status_id' => 40,
                    'to_contact_status_id' => 41,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('flow_route_capabilities')->insert([
            [
                'id' => 80,
                'key' => 'flow_routes.wait',
                'module_key' => 'flow_routes',
                'capability_type' => 'wait',
                'point_type' => 'wait',
                'handler_key' => 'wait',
                'event_key' => null,
                'action_key' => null,
                'name' => 'Production wait',
                'description' => 'Customized production capability.',
                'category' => 'timing',
                'surface' => 'operator',
                'supported_subjects' => json_encode([Contact::class]),
                'required_modules' => json_encode(['flow_routes']),
                'input_schema' => json_encode(['type' => 'object']),
                'output_schema' => json_encode(['type' => 'object']),
                'available_fields' => json_encode([]),
                'defaults' => json_encode(['minutes' => 15]),
                'is_active' => true,
                'source' => 'module_registry',
                'source_version' => '1',
                'is_customized' => true,
                'customized_at' => $now,
                'meta' => json_encode(['owner' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 82,
                'key' => 'flow_routes.change_status',
                'module_key' => 'flow_routes',
                'capability_type' => 'action',
                'point_type' => 'change_status',
                'handler_key' => 'change_status',
                'event_key' => null,
                'action_key' => 'contacts.change_status',
                'name' => 'Production change status',
                'description' => 'Customized production capability.',
                'category' => 'workflow',
                'surface' => 'operator',
                'supported_subjects' => json_encode([Contact::class]),
                'required_modules' => json_encode(['flow_routes', 'workflow']),
                'input_schema' => json_encode(['type' => 'object']),
                'output_schema' => json_encode(['type' => 'object']),
                'available_fields' => json_encode([]),
                'defaults' => json_encode([]),
                'is_active' => true,
                'source' => 'module_registry',
                'source_version' => '1',
                'is_customized' => true,
                'customized_at' => $now,
                'meta' => json_encode(['owner' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('flow_route_capability_bindings')->insert([
            'id' => 81,
            'flow_route_capability_id' => 80,
            'context_type' => Contact::class,
            'context_id' => 60,
            'owner_type' => null,
            'owner_id' => null,
            'module_key' => 'flow_routes',
            'visibility' => 'operator',
            'sort_order' => 10,
            'label' => 'Production wait',
            'description' => null,
            'help_text' => null,
            'defaults' => json_encode(['minutes' => 15]),
            'constraints' => json_encode([]),
            'input_overrides' => json_encode([]),
            'output_overrides' => json_encode([]),
            'is_enabled' => true,
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('flow_routes')->insert([
            'id' => 90,
            'key' => 'production_route',
            'contact_status_id' => 41,
            'owner_type' => Contact::class,
            'owner_id' => 60,
            'owner_group' => 'sales',
            'name' => 'Production route',
            'description' => 'Customized production route.',
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'replied',
            'is_active' => true,
            'source_version' => '1',
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('flow_route_trigger_bindings')->insert([
            'id' => 91,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'replied',
            'flow_route_id' => 90,
            'context_type' => Contact::class,
            'context_id' => 60,
            'is_active' => true,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('flow_route_points')->insert([
            [
                'id' => 100,
                'flow_route_id' => 90,
                'flow_route_capability_id' => 80,
                'key' => 'wait_for_reply',
                'type' => 'wait',
                'name' => 'Wait for reply',
                'description' => null,
                'sort_order' => 10,
                'is_start' => true,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => json_encode([
                    'minutes' => 15,
                ]),
                'settings' => json_encode([]),
                'cancel_conditions' => json_encode([]),
                'source_version' => '1',
                'is_customized' => true,
                'customized_at' => $now,
                'meta' => json_encode(['owner' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 101,
                'flow_route_id' => 90,
                'flow_route_capability_id' => 82,
                'key' => 'change_status',
                'type' => 'change_status',
                'name' => 'Change status',
                'description' => null,
                'sort_order' => 20,
                'is_start' => false,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => json_encode([
                    'contact_status_id' => 41,
                ]),
                'settings' => json_encode([
                    'target_status_id' => 41,
                ]),
                'cancel_conditions' => json_encode([]),
                'source_version' => '1',
                'is_customized' => true,
                'customized_at' => $now,
                'meta' => json_encode(['owner' => 'production']),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('flow_route_points')
            ->where('id', 100)
            ->update([
                'next_flow_route_point_id' => 101,
            ]);

        DB::table('scheduled_messages')->insert([
            'id' => 150,
            'recipient_type' => Contact::class,
            'recipient_id' => 60,
            'context_type' => null,
            'context_id' => null,
            'behavior_owner_type' => FlowRoutePoint::class,
            'behavior_owner_id' => 100,
            'channel' => 'email',
            'message_type' => 'flow_route_message',
            'purpose' => 'transactional',
            'scope' => 'general',
            'payload_class' => EmailPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => json_encode(['flow_route_message']),
            'definition_config_path' => null,
            'payload' => json_encode([
                'to' => 'flow-route-contact@example.com',
                'subject' => 'FlowRoute message',
                'body' => 'FlowRoute message body.',
            ]),
            'send_at' => $now->copy()->addHour(),
            'status' => 'pending',
            'provider_idempotency_key' => 'flow-route-project-state-provider',
            'dedupe_key' => 'flow-route-project-state-message',
            'meta' => json_encode([
                'source' => 'flow_routes',
                'flow_route' => [
                    'flow_route_progress_id' => 110,
                    'flow_route_plan_id' => 120,
                    'flow_route_plan_item_id' => 130,
                    'flow_route_progress_item_id' => 140,
                    'flow_route_id' => 90,
                    'flow_route_point_id' => 100,
                    'flow_route_capability_id' => 80,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
        ]);

        DB::table('contact_flow_route_progress')->insert([
            'id' => 110,
            'contact_id' => 60,
            'subject_type' => Contact::class,
            'subject_id' => 60,
            'contact_status_id' => 41,
            'contact_workflow_profile_id' => 70,
            'flow_route_id' => 90,
            'current_flow_route_point_id' => 100,
            'status' => 'waiting',
            'started_at' => $now,
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => $now->copy()->addHour(),
            'waiting_event_key' => 'contact.replied',
            'cancellation_reason' => null,
            'failure_reason' => null,
            'meta' => json_encode([
                'started_from_workflow_transition' => [
                    'from_contact_status_id' => 40,
                    'to_contact_status_id' => 41,
                    'reason' => 'production_transition',
                    'source' => 'production',
                    'changed_at' => $now->toISOString(),
                ],
                'waiting' => [
                    'flow_route_plan_id' => 120,
                    'flow_route_plan_item_id' => 130,
                    'flow_route_progress_item_id' => 140,
                    'flow_route_point_id' => 100,
                    'correlation' => [
                        'contact_id' => 60,
                    ],
                ],
                'immediate_execution_continuation' => [
                    'status' => 'scheduled',
                    'sequence' => 1,
                    'scheduled_at' => $now->toISOString(),
                    'flow_route_point_id' => 101,
                ],
                'last_version_reconciliation' => [
                    'from_flow_route_id' => 90,
                    'from_flow_route_version' => 1,
                    'from_flow_route_plan_id' => 120,
                    'from_flow_route_point_id' => 100,
                    'from_flow_route_point_key' => 'wait_for_reply',
                    'to_flow_route_id' => 90,
                    'to_flow_route_version' => 1,
                    'to_flow_route_plan_id' => 120,
                    'to_flow_route_point_id' => 100,
                    'to_flow_route_point_key' => 'wait_for_reply',
                    'reconciled_at' => $now->toISOString(),
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_flow_route_plans')->insert([
            'id' => 120,
            'contact_flow_route_progress_id' => 110,
            'contact_id' => 60,
            'subject_type' => Contact::class,
            'subject_id' => 60,
            'flow_route_id' => 90,
            'status' => 'active',
            'source' => 'template',
            'revision' => 1,
            'flow_route_version' => 1,
            'snapshot_at' => $now,
            'started_at' => $now,
            'completed_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'superseded_at' => null,
            'cancellation_reason' => null,
            'failure_reason' => null,
            'reconciled_from_plan_id' => null,
            'route_snapshot' => json_encode([
                'id' => 90,
                'key' => 'production_route',
                'name' => 'Production route',
                'version' => 1,
                'trigger_type' => 'contact_status',
                'trigger_key' => 'replied',
                'owner_type' => Contact::class,
                'owner_id' => 60,
                'owner_group' => 'sales',
                'source_version' => '1',
                'meta' => [],
            ]),
            'meta' => json_encode([
                'created_by' => 'flow_routes',
                'reconciled_from_flow_route_id' => 90,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_flow_route_plan_items')->insert([
            [
                'id' => 130,
                'contact_flow_route_progress_id' => 110,
                'contact_flow_route_plan_id' => 120,
                'flow_route_id' => 90,
                'flow_route_point_id' => 100,
                'flow_route_capability_id' => 80,
                'key' => 'wait_for_reply',
                'point_type' => 'wait',
                'sort_order' => 10,
                'sequence' => 1,
                'attempt' => 1,
                'source' => 'template',
                'status' => 'waiting',
                'result_reason' => 'waiting_for_reply',
                'available_at' => $now,
                'started_at' => $now,
                'completed_at' => null,
                'skipped_at' => null,
                'cancelled_at' => null,
                'failed_at' => null,
                'resume_at' => $now->copy()->addHour(),
                'waiting_event_key' => 'contact.replied',
                'definition_snapshot' => json_encode([
                    'minutes' => 15,
                    'contact_status_id' => 41,
                ]),
                'settings_snapshot' => json_encode([
                    'target_status_id' => 41,
                ]),
                'cancel_conditions_snapshot' => json_encode([]),
                'correlation' => json_encode([
                    'contact_id' => 60,
                ]),
                'result_payload' => null,
                'meta' => json_encode([
                    'flow_route_point_snapshot' => [
                        'id' => 100,
                        'key' => 'wait_for_reply',
                        'sort_order' => 10,
                        'is_start' => true,
                        'next_flow_route_point_id' => 101,
                        'point_type' => 'wait',
                        'flow_route_capability_id' => 80,
                    ],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 131,
                'contact_flow_route_progress_id' => 110,
                'contact_flow_route_plan_id' => 120,
                'flow_route_id' => 90,
                'flow_route_point_id' => 101,
                'flow_route_capability_id' => 82,
                'key' => 'change_status',
                'point_type' => 'change_status',
                'sort_order' => 20,
                'sequence' => 2,
                'attempt' => 0,
                'source' => 'template',
                'status' => 'pending',
                'result_reason' => null,
                'available_at' => null,
                'started_at' => null,
                'completed_at' => null,
                'skipped_at' => null,
                'cancelled_at' => null,
                'failed_at' => null,
                'resume_at' => null,
                'waiting_event_key' => null,
                'definition_snapshot' => json_encode([
                    'contact_status_id' => 41,
                ]),
                'settings_snapshot' => json_encode([
                    'target_status_id' => 41,
                ]),
                'cancel_conditions_snapshot' => json_encode([]),
                'correlation' => json_encode([]),
                'result_payload' => null,
                'meta' => json_encode([
                    'flow_route_point_snapshot' => [
                        'id' => 101,
                        'key' => 'change_status',
                        'sort_order' => 20,
                        'is_start' => false,
                        'next_flow_route_point_id' => null,
                        'point_type' => 'change_status',
                        'flow_route_capability_id' => 82,
                    ],
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('contact_flow_route_progress_items')->insert([
            'id' => 140,
            'contact_flow_route_progress_id' => 110,
            'contact_flow_route_plan_id' => 120,
            'contact_flow_route_plan_item_id' => 130,
            'flow_route_id' => 90,
            'flow_route_point_id' => 100,
            'flow_route_capability_id' => 80,
            'created_subject_type' => Contact::class,
            'created_subject_id' => 60,
            'key' => 'wait_for_reply',
            'point_type' => 'wait',
            'sequence' => 1,
            'attempt' => 1,
            'status' => 'waiting',
            'result_reason' => 'waiting_for_reply',
            'started_at' => $now,
            'completed_at' => null,
            'skipped_at' => null,
            'cancelled_at' => null,
            'failed_at' => null,
            'resume_at' => $now->copy()->addHour(),
            'waiting_event_key' => 'contact.replied',
            'correlation_key' => 'contact_id',
            'correlation_type' => 'contact',
            'correlation' => json_encode([
                'contact_id' => 60,
            ]),
            'result_payload' => json_encode([
                'status' => 'waiting',
                'reason' => 'waiting_for_reply',
                'meta' => [
                    'flow_route_point_id' => 100,
                    'flow_routes' => [
                        'flow_route_progress_id' => 110,
                        'flow_route_plan_id' => 120,
                        'flow_route_plan_item_id' => 130,
                        'flow_route_progress_item_id' => 140,
                        'flow_route_id' => 90,
                        'flow_route_point_id' => 100,
                        'flow_route_capability_id' => 80,
                    ],
                ],
            ]),
            'meta' => json_encode([
                'flow_routes' => [
                    'flow_route_progress_id' => 110,
                    'flow_route_plan_id' => 120,
                    'flow_route_plan_item_id' => 130,
                    'flow_route_progress_item_id' => 140,
                    'flow_route_id' => 90,
                    'flow_route_point_id' => 100,
                    'flow_route_capability_id' => 80,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('scheduled_messages')->delete();
        DB::table('contact_flow_route_progress_items')->delete();
        DB::table('contact_flow_route_plan_items')->delete();
        DB::table('contact_flow_route_plans')->delete();
        DB::table('contact_flow_route_progress')->delete();
        DB::table('flow_route_capability_bindings')->delete();
        DB::table('flow_route_trigger_bindings')->delete();
        DB::table('flow_route_points')->delete();
        DB::table('flow_routes')->delete();
        DB::table('flow_route_capabilities')->delete();
        DB::table('contact_workflow_profiles')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_statuses')->delete();

        $now = now()->startOfSecond();

        DB::table('contact_statuses')->insert([
            [
                'id' => 240,
                'key' => 'engaged',
                'name' => 'Fresh engaged',
                'description' => null,
                'category' => 'general',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 10,
                'source_version' => '2',
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 241,
                'key' => 'replied',
                'name' => 'Fresh replied',
                'description' => null,
                'category' => 'general',
                'color' => null,
                'is_core' => true,
                'is_active' => true,
                'is_customized' => false,
                'customized_at' => null,
                'sort_order' => 20,
                'source_version' => '2',
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('flow_route_capabilities')->insert([
            [
                'id' => 280,
                'key' => 'flow_routes.wait',
                'module_key' => 'flow_routes',
                'capability_type' => 'wait',
                'point_type' => 'wait',
                'handler_key' => 'wait',
                'event_key' => null,
                'action_key' => null,
                'name' => 'Fresh wait',
                'description' => null,
                'category' => 'timing',
                'surface' => 'operator',
                'supported_subjects' => json_encode([]),
                'required_modules' => json_encode(['flow_routes']),
                'input_schema' => json_encode([]),
                'output_schema' => json_encode([]),
                'available_fields' => json_encode([]),
                'defaults' => json_encode([]),
                'is_active' => true,
                'source' => 'module_registry',
                'source_version' => '2',
                'is_customized' => false,
                'customized_at' => null,
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 282,
                'key' => 'flow_routes.change_status',
                'module_key' => 'flow_routes',
                'capability_type' => 'action',
                'point_type' => 'change_status',
                'handler_key' => 'change_status',
                'event_key' => null,
                'action_key' => 'contacts.change_status',
                'name' => 'Fresh change status',
                'description' => null,
                'category' => 'workflow',
                'surface' => 'operator',
                'supported_subjects' => json_encode([]),
                'required_modules' => json_encode(['flow_routes', 'workflow']),
                'input_schema' => json_encode([]),
                'output_schema' => json_encode([]),
                'available_fields' => json_encode([]),
                'defaults' => json_encode([]),
                'is_active' => true,
                'source' => 'module_registry',
                'source_version' => '2',
                'is_customized' => false,
                'customized_at' => null,
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('flow_routes')->insert([
            'id' => 290,
            'key' => 'production_route',
            'contact_status_id' => 241,
            'owner_type' => null,
            'owner_id' => null,
            'owner_group' => null,
            'name' => 'Fresh route',
            'description' => null,
            'version' => 1,
            'is_current_version' => true,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'replied',
            'is_active' => true,
            'source_version' => '2',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('flow_route_points')->insert([
            [
                'id' => 300,
                'flow_route_id' => 290,
                'flow_route_capability_id' => 280,
                'key' => 'wait_for_reply',
                'type' => 'wait',
                'name' => 'Fresh wait',
                'description' => null,
                'sort_order' => 10,
                'is_start' => true,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => json_encode([
                    'minutes' => 15,
                ]),
                'settings' => json_encode([]),
                'cancel_conditions' => json_encode([]),
                'source_version' => '2',
                'is_customized' => false,
                'customized_at' => null,
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 301,
                'flow_route_id' => 290,
                'flow_route_capability_id' => 282,
                'key' => 'change_status',
                'type' => 'change_status',
                'name' => 'Fresh change status',
                'description' => null,
                'sort_order' => 20,
                'is_start' => false,
                'is_active' => true,
                'next_flow_route_point_id' => null,
                'definition' => json_encode([
                    'contact_status_key' => 'replied',
                ]),
                'settings' => json_encode([]),
                'cancel_conditions' => json_encode([]),
                'source_version' => '2',
                'is_customized' => false,
                'customized_at' => null,
                'meta' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('flow_route_points')
            ->where('id', 300)
            ->update([
                'next_flow_route_point_id' => 301,
            ]);

        DB::table('flow_route_trigger_bindings')->insert([
            'id' => 291,
            'trigger_type' => 'contact_status',
            'trigger_key' => 'replied',
            'flow_route_id' => 290,
            'context_type' => Contact::class,
            'context_id' => 60,
            'is_active' => true,
            'meta' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function jsonColumn(string $table, int $id, string $column): array
    {
        $value = DB::table($table)
            ->where('id', $id)
            ->value($column);

        $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}