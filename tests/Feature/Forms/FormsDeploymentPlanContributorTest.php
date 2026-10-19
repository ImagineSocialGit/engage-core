<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Deployment\FormsDeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class FormsDeploymentPlanContributorTest extends TestCase
{
    public function test_no_selected_public_forms_keeps_public_surface_and_external_intake_optional(): void
    {
        $this->configureFormsPreset(public: false);
        config()->set('forms.external_intake.enabled', false);

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['FORMS_APP_URL']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            $requirements['FORMS_APP_URL']->valueRule,
        );
        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['FORMS_EXTERNAL_INTAKE_ENABLED']->requirement,
        );
        $this->assertArrayNotHasKey('FORMS_EXTERNAL_INTAKE_CLIENT_SECRET', $requirements);
    }

    public function test_non_hosted_public_form_requires_external_intake_decision_but_not_forms_origin(): void
    {
        $this->configureFormsPreset(public: true, hosted: false);
        config()->set('forms.external_intake.enabled', false);
        app()->detectEnvironment(fn (): string => 'production');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['FORMS_APP_URL']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::REQUIRED,
            $requirements['FORMS_EXTERNAL_INTAKE_ENABLED']->requirement,
        );
        $this->assertStringContainsString(
            'artist_updates',
            $requirements['FORMS_EXTERNAL_INTAKE_ENABLED']->reason,
        );
        $this->assertArrayNotHasKey('FORMS_EXTERNAL_INTAKE_CLIENT_SECRET', $requirements);
    }

    public function test_hosted_public_form_origin_is_optional_in_local_and_testing(): void
    {
        $this->configureFormsPreset(public: true, hosted: true);
        config()->set('forms.external_intake.enabled', false);

        foreach (['local', 'testing'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);
            $requirements = $this->requirements();

            $this->assertSame(
                EnvironmentRequirement::OPTIONAL,
                $requirements['FORMS_APP_URL']->requirement,
                "Expected hosted Forms origin to remain optional in [{$environment}].",
            );
            $this->assertSame(
                EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
                $requirements['FORMS_APP_URL']->valueRule,
            );
        }
    }

    public function test_hosted_public_form_requires_explicit_origin_in_staging_and_production(): void
    {
        $this->configureFormsPreset(public: true, hosted: true);
        config()->set('forms.external_intake.enabled', false);

        foreach (['staging', 'production'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);
            $requirements = $this->requirements();

            $this->assertSame(
                EnvironmentRequirement::REQUIRED,
                $requirements['FORMS_APP_URL']->requirement,
                "Expected hosted Forms origin to be required in [{$environment}].",
            );
            $this->assertSame(
                EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
                $requirements['FORMS_APP_URL']->valueRule,
            );
            $this->assertStringContainsString(
                'artist_updates',
                $requirements['FORMS_APP_URL']->reason,
            );
        }
    }

    public function test_enabled_external_intake_requires_runtime_identity_and_signing_values(): void
    {
        $this->configureFormsPreset(public: true);
        config()->set('forms.external_intake.enabled', true);

        $requirements = $this->requirements();

        foreach ([
            'FORMS_EXTERNAL_INTAKE_CLIENT_ID',
            'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET',
            'FORMS_EXTERNAL_INTAKE_SOURCE',
            'FORMS_EXTERNAL_INTAKE_PROVIDER',
            'FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS',
        ] as $key) {
            $this->assertSame(EnvironmentRequirement::REQUIRED, $requirements[$key]->requirement);
        }

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['FORMS_EXTERNAL_INTAKE_DOMAINS']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::DEFAULTED,
            $requirements['FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES']->requirement,
        );
    }

    private function configureFormsPreset(
        bool $public,
        bool $hosted = false,
    ): void {
        config()->set('modules.enabled', ['forms']);
        config()->set('client.preset', 'deployment_forms_test');
        config()->set('presets.packages.deployment_forms_test', [
            'name' => 'Deployment Forms Test',
            'groups' => [
                'contact_statuses' => [],
                'tasks' => [],
                'campaigns' => [],
                'flow_routes' => [],
                'forms' => ['deployment_forms'],
            ],
        ]);
        config()->set('presets.modules.forms.forms', [
            'groups' => [
                'deployment_forms' => ['artist_updates'],
            ],
            'definitions' => [
                'artist_updates' => [
                    'key' => 'artist_updates',
                    'name' => 'Artist Updates',
                    'is_public' => $public,
                    'settings' => [
                        'public' => [
                            'hosted' => [
                                'enabled' => $hosted,
                            ],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach (app(FormsDeploymentPlanContributor::class)->environmentRequirements() as $requirement) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }
}