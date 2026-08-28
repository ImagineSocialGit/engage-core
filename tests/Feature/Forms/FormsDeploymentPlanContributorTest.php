<?php

namespace Tests\Feature\Forms;

use App\Modules\Forms\Deployment\FormsDeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class FormsDeploymentPlanContributorTest extends TestCase
{
    public function test_no_selected_public_forms_keeps_external_intake_decision_optional(): void
    {
        $this->configureFormsPreset(public: false);
        config()->set('forms.external_intake.enabled', false);

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['FORMS_EXTERNAL_INTAKE_ENABLED']->requirement,
        );
        $this->assertArrayNotHasKey('FORMS_EXTERNAL_INTAKE_CLIENT_SECRET', $requirements);
    }

    public function test_selected_public_form_requires_an_explicit_external_intake_decision(): void
    {
        $this->configureFormsPreset(public: true);
        config()->set('forms.external_intake.enabled', false);

        $requirements = $this->requirements();

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

    private function configureFormsPreset(bool $public): void
    {
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