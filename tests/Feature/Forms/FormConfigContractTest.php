<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContract;
use App\Modules\Forms\ConfigContracts\FormDefinitionConfigContractTargetProvider;
use App\Modules\Forms\Models\FormDefinition;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;
use Tests\TestCase;

class FormConfigContractTest extends TestCase
{
    public function test_form_definition_contract_accepts_supported_foundational_shape(): void
    {
        $violations = app(FormDefinitionConfigContract::class)
            ->schema()
            ->validate(
                $this->definition(),
                'presets.modules.client.forms.definitions.artist_updates',
            );

        $this->assertSame([], $violations);
    }

    public function test_form_definition_contract_rejects_unsupported_field_type(): void
    {
        $definition = $this->definition();
        $definition['schema']['sections'][0]['fields'][0]['type'] = 'signature_pad';

        $violations = app(FormDefinitionConfigContract::class)
            ->schema()
            ->validate(
                $definition,
                'presets.modules.client.forms.definitions.artist_updates',
            );

        $this->assertNotSame([], $violations);
        $this->assertTrue(collect($violations)->contains(
            fn ($violation): bool =>
                $violation->path === 'presets.modules.client.forms.definitions.artist_updates.schema.sections.0.fields.0.type',
        ));
    }

    public function test_composed_target_provider_discovers_only_selected_form_definitions(): void
    {
        config()->set('client.key', 'forms-contract-test');
        config()->set('client.preset', 'forms_contract_test');
        config()->set('presets.packages.forms_contract_test', [
            'name' => 'Forms Contract Test',
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
                'artist_forms' => ['test_artist_updates'],
                'unused_forms' => ['unused_form'],
            ],
            'definitions' => [
                'test_artist_updates' => [
                    ...$this->definition(),
                    'key' => 'test_artist_updates',
                    'name' => 'Test Artist Updates',
                ],
                'unused_form' => [
                    ...$this->definition(),
                    'key' => 'unused_form',
                    'name' => 'Unused Form',
                ],
            ],
        ]);

        $this->app->register(FormsModuleServiceProvider::class, force: true);

        $targets = iterator_to_array(
            app(FormDefinitionConfigContractTargetProvider::class)->targets(
                ConfigContractTargetContext::current('forms_contract_test'),
            ),
            false,
        );

        $this->assertCount(1, $targets);
        $this->assertSame(
            'presets.modules.client.forms.definitions.test_artist_updates',
            $targets[0]->path,
        );
        $this->assertSame('forms.form_definition', $targets[0]->contractKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'key' => 'artist_updates',
            'name' => 'Artist Updates',
            'description' => 'Reusable fan update intake.',
            'category' => FormDefinition::CATEGORY_INTAKE,
            'is_public' => true,
            'schema' => [
                'sections' => [[
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
                            'options' => [
                                ['value' => 'music', 'label' => 'Music'],
                                ['value' => 'tour', 'label' => 'Tour'],
                            ],
                        ],
                    ],
                ]],
            ],
            'rules' => [],
            'layout' => [],
            'settings' => [
                'submit_action' => 'create_submission',
            ],
            'meta' => [],
        ];
    }
}