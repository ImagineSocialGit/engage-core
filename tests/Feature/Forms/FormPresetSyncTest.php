<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Actions\SyncFormPresetsAction;
use App\Modules\Forms\Data\FormPresetSyncResult;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Support\ConfigContracts\Contracts\PresetPackageConfigContract;
use App\Support\Presets\Enums\PresetDomain;
use App\Support\Presets\PresetCompositionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class FormPresetSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_form_presets_publish_versioned_definitions_and_reuse_identical_snapshots(): void
    {
        $this->configureFormPreset();

        $resolved = app(PresetCompositionResolver::class)->resolve(
            'forms_test',
            PresetDomain::Forms,
        );

        $first = app(SyncFormPresetsAction::class)->handle($resolved);

        $this->assertSame(1, $first->definitionsCreated);
        $this->assertSame(0, $first->definitionsUpdated);
        $this->assertSame(0, $first->definitionsUnchanged);
        $this->assertSame(1, $first->versionsPublished);
        $this->assertSame(0, $first->versionsReused);

        $definition = FormDefinition::query()
            ->where('key', 'test_artist_updates')
            ->sole();
        $version = $definition->currentVersion()->sole();

        $this->assertSame(FormDefinition::STATUS_ACTIVE, $definition->status);
        $this->assertSame(FormDefinition::CATEGORY_INTAKE, $definition->category);
        $this->assertTrue($definition->is_public);
        $this->assertSame('preset', $definition->source);
        $this->assertSame(1, $version->version);
        $this->assertSame(FormVersion::STATUS_PUBLISHED, $version->status);
        $this->assertNotNull($version->published_at);
        $this->assertSame('preset', $version->source);
        $this->assertSame('forms_test', data_get($version->meta, 'preset.preset_key'));
        $this->assertSame(
            ['artist_forms'],
            data_get($version->meta, 'preset.groups'),
        );
        $this->assertSame(
            'email',
            data_get($version->schema, 'sections.0.fields.0.key'),
        );

        $second = app(SyncFormPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'forms_test',
                PresetDomain::Forms,
            ),
        );

        $this->assertSame(0, $second->definitionsCreated);
        $this->assertSame(0, $second->definitionsUpdated);
        $this->assertSame(1, $second->definitionsUnchanged);
        $this->assertSame(0, $second->versionsPublished);
        $this->assertSame(1, $second->versionsReused);
        $this->assertDatabaseCount('form_versions', 1);
    }

    public function test_changed_preset_content_publishes_a_new_version_and_preserves_the_old_snapshot(): void
    {
        $this->configureFormPreset();
        $this->syncConfiguredForms();

        $original = FormVersion::query()
            ->where('version', 1)
            ->sole();

        $forms = config('presets.modules.client.forms');
        $forms['definitions']['test_artist_updates']['settings']['success_message_key'] = 'fan_updates_saved';
        config()->set('presets.modules.client.forms', $forms);

        $result = $this->syncConfiguredForms();

        $this->assertSame(0, $result->definitionsCreated);
        $this->assertSame(1, $result->definitionsUpdated);
        $this->assertSame(1, $result->versionsPublished);
        $this->assertSame(0, $result->versionsReused);
        $this->assertDatabaseCount('form_versions', 2);

        $definition = FormDefinition::query()
            ->where('key', 'test_artist_updates')
            ->sole();
        $current = $definition->currentVersion()->sole();

        $this->assertSame(2, $current->version);
        $this->assertSame('fan_updates_saved', data_get($current->settings, 'success_message_key'));
        $this->assertNull(data_get($original->fresh()->settings, 'success_message_key'));

        $this->expectException(LogicException::class);

        $original->forceFill([
            'name' => 'Mutated after publication',
        ])->save();
    }

    public function test_matching_preset_draft_is_not_reused_as_the_published_current_version(): void
    {
        $this->configureFormPreset();

        $definition = FormDefinition::factory()->active()->create([
            'key' => 'test_artist_updates',
            'name' => 'Artist Updates',
            'description' => 'Reusable fan update intake.',
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => true,
            'source' => 'preset',
        ]);

        $configured = config('presets.modules.client.forms.definitions.test_artist_updates');

        $draft = FormVersion::factory()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => FormVersion::STATUS_DRAFT,
            'name' => $configured['name'],
            'description' => $configured['description'],
            'schema' => $configured['schema'],
            'rules' => $configured['rules'],
            'layout' => $configured['layout'],
            'settings' => $configured['settings'],
            'published_at' => null,
            'source' => 'preset',
        ]);

        $definition->forceFill([
            'current_form_version_id' => $draft->getKey(),
        ])->save();

        $result = $this->syncConfiguredForms();
        $definition->refresh();
        $current = $definition->currentVersion()->sole();

        $this->assertSame(1, $result->versionsPublished);
        $this->assertSame(0, $result->versionsReused);
        $this->assertSame(2, $current->version);
        $this->assertSame(FormVersion::STATUS_PUBLISHED, $current->status);
        $this->assertNotNull($current->published_at);
        $this->assertSame(FormVersion::STATUS_DRAFT, $draft->fresh()->status);
    }

    public function test_invalid_form_schema_is_rejected_before_any_form_records_are_written(): void
    {
        $this->configureFormPreset();

        $forms = config('presets.modules.client.forms');
        $forms['definitions']['test_artist_updates']['schema']['sections'][0]['fields'][] = [
            'key' => 'email',
            'label' => 'Duplicate Email',
            'type' => 'email',
            'required' => false,
        ];
        config()->set('presets.modules.client.forms', $forms);

        try {
            $this->syncConfiguredForms();
            $this->fail('Invalid form schema should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('duplicate field key [email]', $exception->getMessage());
        }

        $this->assertDatabaseCount('form_definitions', 0);
        $this->assertDatabaseCount('form_versions', 0);
    }

    public function test_form_preset_sync_refuses_to_overwrite_a_manual_definition_with_the_same_key(): void
    {
        $this->configureFormPreset();

        FormDefinition::factory()->create([
            'key' => 'test_artist_updates',
            'name' => 'Manual Artist Updates',
            'source' => 'manual',
        ]);

        try {
            $this->syncConfiguredForms();
            $this->fail('Manual FormDefinition collision should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'collides with existing non-preset FormDefinition',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('form_versions', 0);
        $this->assertDatabaseHas('form_definitions', [
            'key' => 'test_artist_updates',
            'name' => 'Manual Artist Updates',
            'source' => 'manual',
        ]);
    }

    public function test_global_preset_sync_runs_forms_only_when_forms_is_enabled_and_selected(): void
    {
        $this->configureFormPreset();
        config()->set('modules.enabled', ['forms']);

        $this->artisan('presets:sync', ['preset' => 'forms_test'])
            ->expectsOutputToContain('Forms')
            ->expectsOutputToContain('Definitions created')
            ->assertExitCode(0);

        $this->assertDatabaseHas('form_definitions', [
            'key' => 'test_artist_updates',
            'status' => FormDefinition::STATUS_ACTIVE,
            'source' => 'preset',
        ]);
    }

    public function test_forms_group_remains_optional_for_existing_preset_packages(): void
    {
        $violations = (new PresetPackageConfigContract())
            ->schema()
            ->validate([
                'name' => 'Legacy package shape',
                'groups' => [
                    'contact_statuses' => [],
                    'tasks' => [],
                    'campaigns' => [],
                    'flow_routes' => [],
                ],
            ], 'presets.packages.legacy');

        $this->assertSame([], $violations);
    }

    private function configureFormPreset(): void
    {
        config()->set('client.key', 'forms-test-client');
        config()->set('presets.packages.forms_test', [
            'name' => 'Forms Test',
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
                'forms' => ['artist_forms'],
            ],
        ]);
        config()->set('presets.modules.client.forms', [
            'groups' => [
                'artist_forms' => [
                    'test_artist_updates',
                ],
            ],
            'definitions' => [
                'test_artist_updates' => [
                    'key' => 'test_artist_updates',
                    'name' => 'Artist Updates',
                    'description' => 'Reusable fan update intake.',
                    'category' => FormDefinition::CATEGORY_INTAKE,
                    'is_public' => true,
                    'schema' => [
                        'sections' => [
                            [
                                'key' => 'contact',
                                'label' => 'Contact',
                                'fields' => [
                                    [
                                        'key' => 'email',
                                        'label' => 'Email',
                                        'type' => 'email',
                                        'required' => true,
                                    ],
                                    [
                                        'key' => 'interests',
                                        'label' => 'Updates',
                                        'type' => 'checkboxes',
                                        'required' => false,
                                        'options' => [
                                            ['value' => 'music', 'label' => 'Music'],
                                            ['value' => 'tour', 'label' => 'Tour'],
                                            ['value' => 'vip', 'label' => 'VIP'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'rules' => [],
                    'layout' => [],
                    'settings' => [
                        'submit_action' => 'create_submission',
                    ],
                    'meta' => [
                        'purpose' => 'fan_updates',
                    ],
                ],
            ],
        ]);
    }

    private function syncConfiguredForms(): FormPresetSyncResult
    {
        return app(SyncFormPresetsAction::class)->handle(
            app(PresetCompositionResolver::class)->resolve(
                'forms_test',
                PresetDomain::Forms,
            ),
        );
    }
}