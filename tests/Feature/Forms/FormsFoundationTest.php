<?php

namespace Tests\Feature\Forms;

use App\Modules\Core\Models\Contact;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormSubmission;
use App\Modules\Forms\Models\FormSubmissionValue;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FormsFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forms_module_is_registered_without_being_enabled_by_default(): void
    {
        config()->set('modules.enabled', [
            'tasks',
            'workflow',
            'flow_routes',
            'messaging',
            'inbound_messaging',
            'internal_notifications',
            'campaigns',
            'broadcasts',
            'webinars',
            'integrations',
            'reporting',
        ]);

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->known('forms'));
        $this->assertFalse($modules->enabled('forms'));
        $this->assertSame(['core'], $modules->dependencies('forms'));
        $this->assertContains(FormsModuleServiceProvider::class, $modules->providers('forms'));
    }

    public function test_forms_foundation_tables_have_durable_generic_columns(): void
    {
        $this->assertTableHasColumns('form_definitions', [
            'key',
            'name',
            'description',
            'status',
            'category',
            'is_public',
            'current_form_version_id',
            'source',
            'provider',
            'external_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('form_versions', [
            'form_definition_id',
            'version',
            'status',
            'name',
            'description',
            'schema',
            'rules',
            'layout',
            'settings',
            'published_at',
            'archived_at',
            'source',
            'provider',
            'external_id',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('form_submissions', [
            'form_definition_id',
            'form_version_id',
            'contact_id',
            'subject_type',
            'subject_id',
            'status',
            'review_status',
            'submitted_at',
            'reviewed_at',
            'reviewed_by_type',
            'reviewed_by_id',
            'source',
            'provider',
            'external_id',
            'ip_address',
            'user_agent',
            'payload',
            'raw_payload',
            'meta',
            'deleted_at',
        ]);

        $this->assertTableHasColumns('form_submission_values', [
            'form_submission_id',
            'field_key',
            'field_label',
            'field_type',
            'value',
            'value_text',
            'value_number',
            'value_boolean',
            'value_date',
            'value_datetime',
            'sort_order',
            'meta',
            'deleted_at',
        ]);
    }

    public function test_form_definitions_have_versions_and_a_current_version_without_a_builder_table(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'dog_training_intake',
            'name' => 'Dog Training Intake',
        ]);

        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->id,
            'version' => 1,
            'schema' => [
                'sections' => [
                    [
                        'key' => 'dog',
                        'fields' => [
                            ['key' => 'dog_name', 'type' => 'text', 'label' => 'Dog name'],
                        ],
                    ],
                ],
            ],
        ]);

        $definition->update(['current_form_version_id' => $version->id]);
        $definition->refresh();

        $this->assertTrue($definition->versions->contains($version));
        $this->assertTrue($definition->currentVersion->is($version));
        $this->assertSame(FormVersion::STATUS_PUBLISHED, $version->status);
        $this->assertSame('dog_name', $version->schema['sections'][0]['fields'][0]['key']);
        $this->assertFalse(Schema::hasTable('form_fields'));
    }

    public function test_form_submissions_link_to_contacts_subjects_versions_and_values(): void
    {
        $contact = Contact::factory()->create();
        $definition = FormDefinition::factory()->active()->create();
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->id,
        ]);

        $submission = FormSubmission::factory()
            ->forVersion($version)
            ->forSubject($contact)
            ->create([
                'contact_id' => $contact->id,
                'payload' => [
                    'dog_name' => 'Scout',
                ],
            ]);

        $value = FormSubmissionValue::factory()->create([
            'form_submission_id' => $submission->id,
            'field_key' => 'dog_name',
            'field_label' => 'Dog name',
            'field_type' => 'text',
            'value' => ['value' => 'Scout'],
            'value_text' => 'Scout',
        ]);

        $this->assertTrue($submission->formDefinition->is($definition));
        $this->assertTrue($submission->formVersion->is($version));
        $this->assertTrue($submission->contact->is($contact));
        $this->assertTrue($submission->subject->is($contact));
        $this->assertTrue($submission->values->contains($value));
        $this->assertTrue($value->formSubmission->is($submission));
        $this->assertSame('Scout', $value->value_text);
    }

    public function test_form_submissions_track_review_state_without_owning_task_or_notification_lifecycle(): void
    {
        $reviewer = Contact::factory()->create();
        $submission = FormSubmission::factory()
            ->reviewedBy($reviewer, FormSubmission::REVIEW_STATUS_APPROVED)
            ->create();

        $this->assertSame(FormSubmission::STATUS_SUBMITTED, $submission->status);
        $this->assertSame(FormSubmission::REVIEW_STATUS_APPROVED, $submission->review_status);
        $this->assertNotNull($submission->reviewed_at);
        $this->assertTrue($submission->reviewedBy->is($reviewer));
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function assertTableHasColumns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Missing table [{$table}].");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Missing column [{$table}.{$column}].",
            );
        }
    }
}
