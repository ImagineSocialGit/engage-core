<?php

namespace Tests\Feature\SetupValidation;

use App\Modules\Campaigns\Validation\CampaignsSetupValidationContributor;
use App\Modules\Core\Validation\CoreSetupValidationContributor;
use App\Modules\FlowRoutes\Validation\FlowRoutesSetupValidationContributor;
use App\Modules\Messaging\Validation\MessagingSetupValidationContributor;
use App\Modules\Tasks\Validation\TasksSetupValidationContributor;
use App\Modules\Webinars\Validation\WebinarsSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ConfigContractsSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ModuleDependenciesSetupValidationContributor;
use App\Support\SetupValidation\Contributors\ReferenceRegistrySetupValidationContributor;
use App\Support\SetupValidation\SetupValidationManager;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SetupValidationRegistrationTest extends TestCase
{
    public function test_app_level_manager_resolves_tagged_core_contributor(): void
    {
        Config::set('client.preset', 'registration_test');

        Config::set('presets.packages.registration_test', [
            'groups' => [
                'contact_statuses' => [
                    'missing_group',
                ],
            ],
        ]);

        $result = app(SetupValidationManager::class)->validate();

        $this->assertContains(
            'app.presets.selected_group_missing',
            array_map(
                fn ($finding): string => $finding->code,
                $result->findings(),
            ),
        );
    }

    public function test_app_level_composition_registers_all_current_setup_validation_contributors(): void
    {
        $contributors = iterator_to_array(
            $this->app->tagged('setup.validation_contributors'),
            false,
        );

        $classes = array_map(
            fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        foreach ([
            ConfigContractsSetupValidationContributor::class,
            ModuleDependenciesSetupValidationContributor::class,
            ReferenceRegistrySetupValidationContributor::class,
            CoreSetupValidationContributor::class,
            TasksSetupValidationContributor::class,
            MessagingSetupValidationContributor::class,
            WebinarsSetupValidationContributor::class,
            CampaignsSetupValidationContributor::class,
            FlowRoutesSetupValidationContributor::class,
        ] as $expectedContributor) {
            $this->assertContains($expectedContributor, $classes);
        }
    }

}
