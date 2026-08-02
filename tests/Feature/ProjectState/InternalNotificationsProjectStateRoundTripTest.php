<?php

namespace Tests\Feature\ProjectState;

use App\Modules\InternalNotifications\Models\TeamMember;
use App\Modules\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Modules\Tasks\Models\Task;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InternalNotificationsProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_internal_notifications_state_round_trips_and_remaps_team_member_references(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame(8, $document['version']);
        $this->assertCount(
            1,
            $document['sections']['internal_notifications']['tables']['team_members'],
        );
        $this->assertCount(
            1,
            $document['sections']['internal_notifications']['tables']['team_member_notification_preferences'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);
        $this->assertStringContainsString(
            'Import will clear [team_members.user_id] for 1 row(s).',
            implode(' ', $report['warnings']),
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
            'name' => 'Production Advisor',
            'email' => 'advisor@example.com',
            'role' => 'advisor',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('team_member_notification_preferences', [
            'id' => 21,
            'team_member_id' => 20,
            'channel' => 'sms',
            'purpose' => 'inbound_replies',
            'scope' => 'inbound_messages',
            'is_enabled' => true,
        ]);

        $this->assertDatabaseHas('contact_statuses', [
            'id' => 130,
            'key' => 'new',
            'name' => 'Production New',
        ]);
        $this->assertDatabaseHas('contacts', [
            'id' => 40,
            'email' => 'team-member-contact@example.com',
        ]);
        $this->assertDatabaseHas('contact_workflow_profiles', [
            'id' => 50,
            'contact_id' => 40,
            'contact_status_id' => 130,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
        ]);

        $this->assertDatabaseHas('task_templates', [
            'id' => 160,
            'key' => 'internal.team_follow_up',
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
            'responsible_type' => TeamMember::class,
            'responsible_id' => 20,
            'is_customized' => true,
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => 70,
            'task_template_id' => 160,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
            'responsible_type' => TeamMember::class,
            'responsible_id' => 20,
        ]);

        $this->assertDatabaseHas('scheduled_messages', [
            'id' => 80,
            'recipient_type' => TeamMember::class,
            'recipient_id' => 20,
            'context_type' => Task::class,
            'context_id' => 70,
            'status' => 'sent',
        ]);

        $this->assertDatabaseHas('flow_route_capabilities', [
            'id' => 190,
            'key' => 'internal_notifications.team_member_context',
            'name' => 'Production team member context',
        ]);
        $this->assertDatabaseHas('flow_route_capability_bindings', [
            'id' => 91,
            'flow_route_capability_id' => 190,
            'context_type' => TeamMember::class,
            'context_id' => 20,
            'owner_type' => TeamMember::class,
            'owner_id' => 20,
        ]);

        $this->assertDatabaseCount('project_state_resume_items', 0);
    }

    public function test_validation_rejects_a_broken_team_member_preference_reference(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['internal_notifications']['tables']['team_member_notification_preferences'][0]['team_member_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'Project-state reference [team_member_notification_preferences.0.team_member_id] does not exist in [team_members].',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('team_members', [
            'id' => 20,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('users')->insert([
            'id' => 10,
            'name' => 'Source Owner',
            'email' => 'source-owner@example.com',
            'email_verified_at' => null,
            'password' => '$2y$12$projectStateSourceUserPasswordHash',
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_statuses')->insert([
            'id' => 30,
            'key' => 'new',
            'name' => 'Production New',
            'description' => 'Production contact status.',
            'category' => 'general',
            'color' => null,
            'is_core' => true,
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $now,
            'sort_order' => 10,
            'source_version' => '1',
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contacts')->insert([
            'id' => 40,
            'first_name' => 'Team',
            'last_name' => 'Contact',
            'name' => 'Team Contact',
            'email' => 'team-member-contact@example.com',
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

        DB::table('team_member_notification_preferences')->insert([
            'id' => 21,
            'team_member_id' => 20,
            'channel' => 'sms',
            'purpose' => 'inbound_replies',
            'scope' => 'inbound_messages',
            'is_enabled' => true,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_workflow_profiles')->insert([
            'id' => 50,
            'contact_id' => 40,
            'contact_status_id' => 30,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
            'last_status_changed_at' => $now,
            'meta' => json_encode([
                'last_status_change' => [
                    'from_contact_status_id' => 30,
                    'to_contact_status_id' => 30,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('task_templates')->insert(
            $this->taskTemplateRow(
                id: 60,
                name: 'Production team follow-up',
                sourceVersion: '1',
                customized: true,
                now: $now,
            ),
        );

        DB::table('tasks')->insert([
            'id' => 70,
            'assigned_to_type' => TeamMember::class,
            'assigned_to_id' => 20,
            'responsible_party' => 'internal',
            'responsible_type' => TeamMember::class,
            'responsible_id' => 20,
            'task_template_id' => 60,
            'task_template_key' => 'internal.team_follow_up',
            'source' => 'manual',
            'title' => 'Call the assigned contact',
            'description' => 'Follow up with the contact.',
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

        DB::table('scheduled_messages')->insert([
            'id' => 80,
            'recipient_type' => TeamMember::class,
            'recipient_id' => 20,
            'context_type' => Task::class,
            'context_id' => 70,
            'behavior_owner_type' => null,
            'behavior_owner_id' => null,
            'channel' => 'email',
            'message_type' => 'task_assigned',
            'purpose' => 'internal',
            'scope' => 'crm_tasks',
            'payload_class' => InternalEmailNotificationPayload::class,
            'queue' => 'notifications',
            'dispatch_keys' => json_encode(['task_assigned']),
            'definition_config_path' => null,
            'payload' => json_encode([
                'to' => 'advisor@example.com',
                'subject' => 'Task assigned',
                'body' => 'A task was assigned.',
            ]),
            'send_at' => $now,
            'status' => 'sent',
            'provider_idempotency_key' => 'project-state-team-member-message',
            'dedupe_key' => 'project-state-team-member-message',
            'meta' => json_encode([
                'notification_type' => 'task_assigned',
                'recipient_source_type' => TeamMember::class,
                'recipient_source_id' => 20,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
            'message_template_version_id' => null,
            'message_chain_enrollment_id' => null,
            'message_chain_step_variant_id' => null,
        ]);

        DB::table('flow_route_capabilities')->insert(
            $this->flowRouteCapabilityRow(
                id: 90,
                name: 'Production team member context',
                sourceVersion: '1',
                now: $now,
            ),
        );

        DB::table('flow_route_capability_bindings')->insert([
            'id' => 91,
            'flow_route_capability_id' => 90,
            'context_type' => TeamMember::class,
            'context_id' => 20,
            'owner_type' => TeamMember::class,
            'owner_id' => 20,
            'module_key' => 'internal_notifications',
            'visibility' => 'operator',
            'sort_order' => 10,
            'label' => 'Team member context',
            'description' => null,
            'help_text' => null,
            'defaults' => json_encode([]),
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
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('scheduled_messages')->delete();
        DB::table('flow_route_capability_bindings')->delete();
        DB::table('tasks')->delete();
        DB::table('task_templates')->delete();
        DB::table('contact_workflow_profiles')->delete();
        DB::table('team_member_notification_preferences')->delete();
        DB::table('team_members')->delete();
        DB::table('contacts')->delete();
        DB::table('contact_statuses')->delete();
        DB::table('flow_route_capabilities')->delete();
        DB::table('users')->delete();

        $now = now()->startOfSecond();

        DB::table('users')->insert([
            'id' => 110,
            'name' => 'Target Owner',
            'email' => 'target-owner@example.com',
            'email_verified_at' => null,
            'password' => '$2y$12$projectStateTargetUserPasswordHash',
            'remember_token' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('contact_statuses')->insert([
            'id' => 130,
            'key' => 'new',
            'name' => 'Fresh New',
            'description' => null,
            'category' => 'general',
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
        ]);

        DB::table('task_templates')->insert(
            $this->taskTemplateRow(
                id: 160,
                name: 'Fresh team follow-up',
                sourceVersion: '2',
                customized: false,
                now: $now,
            ),
        );

        DB::table('flow_route_capabilities')->insert(
            $this->flowRouteCapabilityRow(
                id: 190,
                name: 'Fresh team member context',
                sourceVersion: '2',
                now: $now,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function taskTemplateRow(
        int $id,
        string $name,
        string $sourceVersion,
        bool $customized,
        mixed $now,
    ): array {
        return [
            'id' => $id,
            'key' => 'internal.team_follow_up',
            'source' => 'preset',
            'source_version' => $sourceVersion,
            'owner_group' => 'operations',
            'category' => 'follow_up',
            'name' => $name,
            'title' => 'Call the assigned contact',
            'description' => 'Team-owned follow-up task.',
            'task_description' => 'Follow up with the assigned contact.',
            'assigned_to_type' => $customized ? TeamMember::class : null,
            'assigned_to_id' => $customized ? 20 : null,
            'assigned_to_strategy' => $customized ? 'explicit' : 'unassigned',
            'responsible_party' => 'internal',
            'responsible_type' => $customized ? TeamMember::class : null,
            'responsible_id' => $customized ? 20 : null,
            'priority' => 'normal',
            'due_offset_minutes' => 60,
            'link_defaults' => json_encode([]),
            'defaults' => json_encode([]),
            'is_active' => true,
            'is_customized' => $customized,
            'customized_at' => $customized ? $now : null,
            'meta' => json_encode(['owner' => $customized ? 'production' : 'preset']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function flowRouteCapabilityRow(
        int $id,
        string $name,
        string $sourceVersion,
        mixed $now,
    ): array {
        return [
            'id' => $id,
            'key' => 'internal_notifications.team_member_context',
            'module_key' => 'internal_notifications',
            'capability_type' => 'context',
            'point_type' => 'team_member_context',
            'handler_key' => null,
            'event_key' => null,
            'action_key' => null,
            'name' => $name,
            'description' => 'Provides TeamMember context to route authoring.',
            'category' => 'internal_notifications',
            'surface' => 'operator',
            'supported_subjects' => json_encode([TeamMember::class]),
            'required_modules' => json_encode(['internal_notifications']),
            'input_schema' => json_encode(['type' => 'object']),
            'output_schema' => json_encode(['type' => 'object']),
            'available_fields' => json_encode([]),
            'defaults' => json_encode([]),
            'is_active' => true,
            'source' => 'module_registry',
            'source_version' => $sourceVersion,
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}