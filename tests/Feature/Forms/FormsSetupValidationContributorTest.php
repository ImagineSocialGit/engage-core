<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Models\FormVersion;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Modules\Forms\Validation\FormsSetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormsSetupValidationContributorTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_active_published_form_has_no_forms_runtime_findings(): void
    {
        $this->publishedDefinition('artist_updates');

        $this->assertSame([], $this->findings());
    }

    public function test_active_form_without_current_version_is_reported(): void
    {
        FormDefinition::factory()->active()->create([
            'key' => 'missing_version',
            'current_form_version_id' => null,
        ]);

        $finding = collect($this->findings())
            ->firstWhere('code', 'forms.runtime.current_version_missing');

        $this->assertNotNull($finding);
        $this->assertSame('forms', $finding['module']);
        $this->assertSame(
            'form_definitions.missing_version.current_form_version_id',
            $finding['path'],
        );
    }

    public function test_active_form_with_draft_current_version_is_reported(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'draft_current',
        ]);
        $version = FormVersion::factory()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'status' => FormVersion::STATUS_DRAFT,
            'published_at' => null,
            'schema' => $this->schema(),
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        $finding = collect($this->findings())
            ->firstWhere('code', 'forms.runtime.current_version_unpublished');

        $this->assertNotNull($finding);
        $this->assertSame('draft_current', $finding['context']['form_key']);
    }

    public function test_forms_provider_registers_runtime_setup_validation_contributor(): void
    {
        $this->app->register(FormsModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('setup.validation_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(
            FormsSetupValidationContributor::class,
            $classes,
        );
    }

    public function test_invalid_submission_mapping_is_reported_before_runtime_handoff(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'invalid_mapping',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'schema' => $this->schema(),
            'settings' => [
                'submission' => [
                    'contact' => [
                        'fields' => [
                            'email' => 'missing_email_field',
                        ],
                    ],
                ],
            ],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        $finding = collect($this->findings())
            ->firstWhere('code', 'forms.runtime.submission_mapping_invalid');

        $this->assertNotNull($finding);
        $this->assertSame(
            "form_versions.{$version->getKey()}.settings",
            $finding['path'],
        );
        $this->assertSame('invalid_mapping', $finding['context']['form_key']);
    }

    public function test_invalid_submission_rules_are_reported_before_runtime_handoff(): void
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => 'invalid_rules',
        ]);
        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'schema' => $this->schema(),
            'rules' => [
                'missing_field' => ['required'],
            ],
        ]);
        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        $finding = collect($this->findings())
            ->firstWhere('code', 'forms.runtime.submission_rules_invalid');

        $this->assertNotNull($finding);
        $this->assertSame(
            "form_versions.{$version->getKey()}.rules",
            $finding['path'],
        );
        $this->assertSame('invalid_rules', $finding['context']['form_key']);
    }

    private function publishedDefinition(string $key): FormDefinition
    {
        $definition = FormDefinition::factory()->active()->create([
            'key' => $key,
            'is_public' => true,
        ]);

        $version = FormVersion::factory()->published()->create([
            'form_definition_id' => $definition->getKey(),
            'version' => 1,
            'schema' => $this->schema(),
        ]);

        $definition->forceFill([
            'current_form_version_id' => $version->getKey(),
        ])->save();

        return $definition->refresh();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            static fn ($finding): array => $finding->toArray(),
            iterator_to_array(
                app(FormsSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return [
            'sections' => [[
                'key' => 'contact',
                'label' => 'Contact',
                'fields' => [[
                    'key' => 'email',
                    'label' => 'Email',
                    'type' => 'email',
                    'required' => true,
                ]],
            ]],
        ];
    }
}