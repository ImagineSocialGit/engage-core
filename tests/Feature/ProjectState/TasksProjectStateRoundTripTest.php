<?php

namespace Tests\Feature\ProjectState;

use App\Modules\Core\Models\Contact;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TasksProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_project_state_sections_are_composed_from_module_files_in_dependency_order(): void
    {
        $sections = config('project_state.sections');

        $this->assertIsArray($sections);
        $this->assertEquals(
            ['core', 'relationships', 'mortgage', 'internal_notifications', 'inbound_messaging', 'messaging', 'webinars', 'tasks', 'campaigns', 'broadcasts', 'workflow', 'automation_opportunities', 'automation_events', 'flow_routes', 'reporting'],
            array_keys($sections),
        );

        foreach (array_keys($sections) as $section) {
            $this->assertEquals(
                require config_path("project_state/{$section}.php"),
                $sections[$section],
            );
        }
    }

    public function test_tasks_state_round_trips_after_preset_sync_with_reference_remapping(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertCount(
            1,
            $document['sections']['tasks']['tables']['task_templates'],
        );
        $this->assertCount(
            1,
            $document['sections']['tasks']['tables']['tasks'],
        );
        $this->assertCount(
            1,
            $document['sections']['tasks']['tables']['task_links'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid']);
        $this->assertEquals([], $report['errors']);

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);

        $this->assertDatabaseHas('contacts', [
            'id' => 60,
            'email' => 'task-contact@example.com',
        ]);

        $this->assertDatabaseHas('task_templates', [
            'id' => 200,
            'key' => 'general.follow_up',
            'name' => 'Production follow up',
            'assigned_to_type' => Contact::class,
            'assigned_to_id' => 60,
            'responsible_type' => Contact::class,
            'responsible_id' => 60,
            'is_customized' => true,
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => 110,
            'task_template_id' => 200,
            'task_template_key' => 'general.follow_up',
            'assigned_to_type' => Contact::class,
            'assigned_to_id' => 60,
            'responsible_type' => Contact::class,
            'responsible_id' => 60,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('task_links', [
            'id' => 120,
            'task_id' => 110,
            'linkable_type' => Contact::class,
            'linkable_id' => 60,
            'role' => 'subject',
        ]);
    }

    public function test_validation_rejects_a_broken_task_template_reference(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['tasks']['tables']['tasks'][0]['task_template_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'tasks.0.task_template_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('tasks', [
            'id' => 110,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Task',
            'last_name' => 'Contact',
            'name' => 'Task Contact',
            'email' => 'task-contact@example.com',
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

        DB::table('task_templates')->insert([
            'id' => 100,
            'key' => 'general.follow_up',
            'source' => 'preset',
            'source_version' => '1',
            'owner_group' => 'sales',
            'category' => 'follow_up',
            'name' => 'Production follow up',
            'title' => 'Follow up with contact',
            'description' => 'Customized production template.',
            'task_description' => 'Review the contact and follow up.',
            'assigned_to_type' => Contact::class,
            'assigned_to_id' => 60,
            'assigned_to_strategy' => null,
            'responsible_party' => 'contact',
            'responsible_type' => Contact::class,
            'responsible_id' => 60,
            'priority' => 'high',
            'due_offset_minutes' => 60,
            'link_defaults' => json_encode([
                [
                    'role' => 'subject',
                    'source' => 'current_contact',
                ],
            ]),
            'defaults' => json_encode([
                'source' => 'module',
            ]),
            'is_active' => true,
            'is_customized' => true,
            'customized_at' => $now,
            'meta' => json_encode([
                'preset' => [
                    'contributor' => 'tasks',
                    'task_template_key' => 'general.follow_up',
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('tasks')->insert([
            'id' => 110,
            'assigned_to_type' => Contact::class,
            'assigned_to_id' => 60,
            'responsible_party' => 'contact',
            'responsible_type' => Contact::class,
            'responsible_id' => 60,
            'task_template_id' => 100,
            'task_template_key' => 'general.follow_up',
            'source' => 'module',
            'title' => 'Production follow-up task',
            'description' => 'Call the contact.',
            'status' => 'open',
            'priority' => 'high',
            'due_at' => $now->copy()->addHour(),
            'completed_at' => null,
            'canceled_at' => null,
            'canceled_reason' => null,
            'archived_at' => null,
            'meta' => json_encode(['owner' => 'production']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('task_links')->insert([
            'id' => 120,
            'task_id' => 110,
            'linkable_type' => Contact::class,
            'linkable_id' => 60,
            'role' => 'subject',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('task_links')->delete();
        DB::table('tasks')->delete();
        DB::table('task_templates')->delete();
        DB::table('contacts')->delete();

        $now = now()->startOfSecond();

        DB::table('task_templates')->insert([
            'id' => 200,
            'key' => 'general.follow_up',
            'source' => 'preset',
            'source_version' => '2',
            'owner_group' => null,
            'category' => 'follow_up',
            'name' => 'Fresh preset follow up',
            'title' => 'Fresh preset title',
            'description' => null,
            'task_description' => null,
            'assigned_to_type' => null,
            'assigned_to_id' => null,
            'assigned_to_strategy' => 'unassigned',
            'responsible_party' => 'internal',
            'responsible_type' => null,
            'responsible_id' => null,
            'priority' => null,
            'due_offset_minutes' => null,
            'link_defaults' => null,
            'defaults' => null,
            'is_active' => true,
            'is_customized' => false,
            'customized_at' => null,
            'meta' => json_encode([
                'preset' => [
                    'contributor' => 'tasks',
                    'task_template_key' => 'general.follow_up',
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}