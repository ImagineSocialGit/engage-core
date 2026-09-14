<?php

namespace Tests\Feature\ProjectState;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormVersion;
use App\Support\ProjectState\ProjectStateDocumentCodec;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FormsProjectStateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_forms_state_round_trips_after_preset_sync_with_version_and_contact_reference_remapping(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        $this->assertSame((int) config('project_state.version'), $document['version']);
        $this->assertArrayHasKey('forms', $document['sections']);
        $this->assertSame(
            (int) config('project_state.sections.forms.version'),
            $document['sections']['forms']['version'],
        );
        $this->assertCount(
            1,
            $document['sections']['forms']['tables']['form_definitions'],
        );
        $this->assertCount(
            1,
            $document['sections']['forms']['tables']['form_versions'],
        );
        $this->assertCount(
            1,
            $document['sections']['forms']['tables']['form_submissions'],
        );
        $this->assertCount(
            2,
            $document['sections']['forms']['tables']['form_submission_values'],
        );

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(' ', $report['errors']));
        $this->assertEquals([], $report['errors']);

        $applied = $projectState->import($document);

        $this->assertTrue($applied['applied']);
        $this->assertSame(1, $applied['applied_counts']['form_definitions']);
        $this->assertSame(1, $applied['applied_counts']['form_versions']);
        $this->assertSame(1, $applied['applied_counts']['form_submissions']);
        $this->assertSame(2, $applied['applied_counts']['form_submission_values']);

        $definition = FormDefinition::withTrashed()
            ->where('key', 'homebuyer_pre_game_check')
            ->sole();
        $version = FormVersion::withTrashed()
            ->where('form_definition_id', $definition->getKey())
            ->where('version', 1)
            ->sole();
        $submission = FormSubmission::withTrashed()
            ->where('external_id', 'submission-320')
            ->sole();

        $this->assertSame(900, $definition->getKey());
        $this->assertSame('Production Homebuyer Pre-Game Check', $definition->name);
        $this->assertSame(901, $version->getKey());
        $this->assertSame(901, $definition->current_form_version_id);
        $this->assertSame('Production version', $version->name);
        $this->assertSame(320, $submission->getKey());
        $this->assertSame(900, $submission->form_definition_id);
        $this->assertSame(901, $submission->form_version_id);
        $this->assertSame(60, $submission->contact_id);
        $this->assertSame(Contact::class, $submission->subject_type);
        $this->assertSame(60, $submission->subject_id);
        $this->assertSame(FormSubmission::REVIEW_STATUS_APPROVED, $submission->review_status);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertNull($submission->reviewed_by_type);
        $this->assertNull($submission->reviewed_by_id);
        $this->assertEquals([
            'first_name' => 'Taylor',
            'email' => 'taylor@example.test',
        ], $submission->payload);

        $this->assertDatabaseHas('form_submission_values', [
            'id' => 330,
            'form_submission_id' => 320,
            'field_key' => 'first_name',
            'value_text' => 'Taylor',
            'sort_order' => 10,
        ]);
        $this->assertDatabaseHas('form_submission_values', [
            'id' => 331,
            'form_submission_id' => 320,
            'field_key' => 'email',
            'value_text' => 'taylor@example.test',
            'sort_order' => 20,
        ]);
    }

    public function test_forms_project_state_section_replaces_the_old_must_be_empty_policies(): void
    {
        $section = config('project_state.sections.forms');
        $policies = config('project_state.table_policies');

        $this->assertTrue($section['optional'] ?? false);
        $this->assertEqualsCanonicalizing(
            [
                'form_definitions',
                'form_versions',
                'form_submissions',
                'form_submission_values',
            ],
            $section['activation_tables'] ?? [],
        );
        $this->assertEqualsCanonicalizing(
            [
                'form_definitions',
                'form_versions',
                'form_submissions',
                'form_submission_values',
            ],
            array_keys($section['tables'] ?? []),
        );

        foreach ($section['activation_tables'] as $table) {
            $this->assertArrayNotHasKey($table, $policies);
        }
    }

    public function test_optional_forms_section_may_be_absent_from_a_current_source_document(): void
    {
        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();

        unset($document['sections']['forms']);
        $document['checksum'] = app(ProjectStateDocumentCodec::class)->checksum($document);

        $report = $projectState->validate($document);

        $this->assertTrue($report['valid'], implode(' ', $report['errors']));
        $this->assertEquals([], $report['errors']);
    }

    public function test_validation_rejects_a_broken_form_version_reference(): void
    {
        $this->seedSourceState();

        $projectState = app(ProjectStateManager::class);
        $document = $projectState->export();
        unset($document['checksum']);

        $document['sections']['forms']['tables']['form_submissions'][0]['form_version_id'] = 999999;

        $this->prepareFreshPresetSyncedTarget();

        $report = $projectState->validate($document);

        $this->assertFalse($report['valid']);
        $this->assertStringContainsString(
            'form_submissions.0.form_version_id',
            implode(' ', $report['errors']),
        );
        $this->assertDatabaseMissing('form_submissions', [
            'id' => 320,
        ]);
    }

    private function seedSourceState(): void
    {
        $now = now()->startOfSecond();

        DB::table('contacts')->insert([
            'id' => 60,
            'first_name' => 'Taylor',
            'last_name' => 'Buyer',
            'name' => 'Taylor Buyer',
            'email' => 'taylor@example.test',
            'phone' => null,
            'birthday' => null,
            'source' => 'forms',
            'subsource' => 'homebuyer_pre_game_check',
            'contact_import_batch_id' => null,
            'assigned_user_id' => null,
            'assigned_team_id' => null,
            'last_contacted_at' => null,
            'last_activity_at' => $now,
            'meta' => json_encode(['source' => 'hosted_form']),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('form_definitions')->insert([
            'id' => 300,
            'key' => 'homebuyer_pre_game_check',
            'name' => 'Production Homebuyer Pre-Game Check',
            'description' => 'Production questionnaire.',
            'status' => FormDefinition::STATUS_ACTIVE,
            'category' => FormDefinition::CATEGORY_QUESTIONNAIRE,
            'is_public' => true,
            'current_form_version_id' => null,
            'source' => 'preset',
            'provider' => null,
            'external_id' => null,
            'meta' => json_encode(['preset' => ['source_version' => 1]]),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('form_versions')->insert([
            'id' => 310,
            'form_definition_id' => 300,
            'version' => 1,
            'status' => FormVersion::STATUS_PUBLISHED,
            'name' => 'Production version',
            'description' => 'Published production questionnaire.',
            'schema' => json_encode([
                'sections' => [
                    [
                        'key' => 'lead',
                        'label' => 'Your Next Step',
                        'fields' => [
                            [
                                'key' => 'first_name',
                                'label' => 'First Name',
                                'type' => 'text',
                                'required' => true,
                            ],
                            [
                                'key' => 'email',
                                'label' => 'Email',
                                'type' => 'email',
                                'required' => true,
                            ],
                        ],
                    ],
                ],
            ]),
            'rules' => json_encode([]),
            'layout' => json_encode([
                'blocks' => [
                    ['type' => 'field', 'field' => 'first_name'],
                    ['type' => 'field', 'field' => 'email'],
                ],
            ]),
            'settings' => json_encode([
                'public' => [
                    'hosted' => [
                        'enabled' => true,
                    ],
                ],
            ]),
            'published_at' => $now,
            'archived_at' => null,
            'source' => 'preset',
            'provider' => null,
            'external_id' => null,
            'meta' => json_encode(['preset' => ['source_version' => 1]]),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('form_definitions')
            ->where('id', 300)
            ->update(['current_form_version_id' => 310]);

        DB::table('form_submissions')->insert([
            'id' => 320,
            'form_definition_id' => 300,
            'form_version_id' => 310,
            'contact_id' => 60,
            'subject_type' => Contact::class,
            'subject_id' => 60,
            'status' => FormSubmission::STATUS_SUBMITTED,
            'review_status' => FormSubmission::REVIEW_STATUS_APPROVED,
            'submitted_at' => $now,
            'reviewed_at' => $now,
            'reviewed_by_type' => User::class,
            'reviewed_by_id' => 777,
            'source' => 'core_hosted_forms',
            'provider' => 'core_hosted_forms',
            'external_id' => 'submission-320',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Project State feature test',
            'payload' => json_encode([
                'first_name' => 'Taylor',
                'email' => 'taylor@example.test',
            ]),
            'raw_payload' => json_encode([
                'email' => 'taylor@example.test',
                'first_name' => 'Taylor',
            ]),
            'meta' => json_encode([
                '_forms' => ['runtime_version' => 1],
                'host' => 'forms.example.test',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('form_submission_values')->insert([
            [
                'id' => 330,
                'form_submission_id' => 320,
                'field_key' => 'first_name',
                'field_label' => 'First Name',
                'field_type' => 'text',
                'value' => json_encode(['value' => 'Taylor']),
                'value_text' => 'Taylor',
                'value_number' => null,
                'value_boolean' => null,
                'value_date' => null,
                'value_datetime' => null,
                'sort_order' => 10,
                'meta' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 331,
                'form_submission_id' => 320,
                'field_key' => 'email',
                'field_label' => 'Email',
                'field_type' => 'email',
                'value' => json_encode(['value' => 'taylor@example.test']),
                'value_text' => 'taylor@example.test',
                'value_number' => null,
                'value_boolean' => null,
                'value_date' => null,
                'value_datetime' => null,
                'sort_order' => 20,
                'meta' => json_encode(['normalized' => true]),
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);
    }

    private function prepareFreshPresetSyncedTarget(): void
    {
        DB::table('form_submission_values')->delete();
        DB::table('form_submissions')->delete();
        DB::table('form_definitions')->update(['current_form_version_id' => null]);
        DB::table('form_versions')->delete();
        DB::table('form_definitions')->delete();
        DB::table('contacts')->delete();

        $now = now()->startOfSecond();

        DB::table('form_definitions')->insert([
            'id' => 900,
            'key' => 'homebuyer_pre_game_check',
            'name' => 'Fresh preset Homebuyer Pre-Game Check',
            'description' => 'Fresh target preset.',
            'status' => FormDefinition::STATUS_ACTIVE,
            'category' => FormDefinition::CATEGORY_QUESTIONNAIRE,
            'is_public' => true,
            'current_form_version_id' => null,
            'source' => 'preset',
            'provider' => null,
            'external_id' => null,
            'meta' => json_encode(['preset' => ['source_version' => 2]]),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('form_versions')->insert([
            'id' => 901,
            'form_definition_id' => 900,
            'version' => 1,
            'status' => FormVersion::STATUS_PUBLISHED,
            'name' => 'Fresh preset version',
            'description' => null,
            'schema' => json_encode([
                'sections' => [
                    [
                        'key' => 'placeholder',
                        'label' => 'Placeholder',
                        'fields' => [
                            [
                                'key' => 'placeholder',
                                'label' => 'Placeholder',
                                'type' => 'text',
                                'required' => false,
                            ],
                        ],
                    ],
                ],
            ]),
            'rules' => json_encode([]),
            'layout' => json_encode([]),
            'settings' => json_encode([]),
            'published_at' => $now,
            'archived_at' => null,
            'source' => 'preset',
            'provider' => null,
            'external_id' => null,
            'meta' => json_encode(['preset' => ['source_version' => 2]]),
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        DB::table('form_definitions')
            ->where('id', 900)
            ->update(['current_form_version_id' => 901]);
    }
}