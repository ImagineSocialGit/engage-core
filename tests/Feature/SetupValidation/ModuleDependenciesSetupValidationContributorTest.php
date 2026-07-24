<?php

namespace Tests\Feature\SetupValidation;

use App\Support\SetupValidation\Contributors\ModuleDependenciesSetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ModuleDependenciesSetupValidationContributorTest extends TestCase
{
    public function test_it_reports_unknown_enabled_module_unknown_dependency_and_cycle(): void
    {
        Config::set('modules.enabled', ['alpha', 'missing']);
        Config::set('modules.modules', [
            'alpha' => [
                'depends_on' => ['beta'],
                'providers' => [self::class],
            ],
            'beta' => [
                'depends_on' => ['alpha', 'missing_dependency'],
                'providers' => [self::class],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertContains('app.modules.enabled_unknown', $codes);
        $this->assertContains('app.modules.dependency_unknown', $codes);
        $this->assertContains('app.modules.dependency_cycle', $codes);
    }

    public function test_dependency_loaded_modules_are_available_from_runtime_module_configuration(): void
    {
        Config::set('modules.enabled', ['flow_routes']);
        Config::set('modules.modules', [
            'core' => [
                'always_on' => true,
                'depends_on' => [],
                'providers' => [self::class],
            ],
            'workflow' => [
                'depends_on' => ['core'],
                'providers' => [self::class],
            ],
            'flow_routes' => [
                'depends_on' => ['workflow'],
                'providers' => [self::class],
            ],
        ]);

        $codes = array_column($this->findings(), 'code');

        $this->assertNotContains('app.modules.enabled_unknown', $codes);
        $this->assertNotContains('app.modules.provider_missing', $codes);
        $this->assertNotContains('app.modules.provider_class_missing', $codes);
    }

    public function test_providerless_available_module_does_not_report_missing_provider_when_explicitly_allowed(): void
    {
        Config::set('modules.enabled', []);
        Config::set('modules.modules', [
            'dashboard' => [
                'always_on' => true,
                'depends_on' => [],
                'requires_provider' => false,
                'providers' => [],
            ],
        ]);

        $this->assertNotContains(
            'app.modules.provider_missing',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_invalid_requires_provider_flag(): void
    {
        Config::set('modules.enabled', []);
        Config::set('modules.modules', [
            'dashboard' => [
                'always_on' => true,
                'depends_on' => [],
                'requires_provider' => 'no',
                'providers' => [],
            ],
        ]);

        $this->assertContains(
            'app.modules.requires_provider_invalid',
            array_column($this->findings(), 'code'),
        );
    }

    public function test_it_reports_missing_provider_class_for_available_module(): void
    {
        Config::set('modules.enabled', ['tasks']);
        Config::set('modules.modules', [
            'core' => [
                'always_on' => true,
                'depends_on' => [],
                'providers' => [self::class],
            ],
            'tasks' => [
                'depends_on' => ['core'],
                'providers' => [
                    'App\\Modules\\Tasks\\Providers\\DefinitelyMissingProvider',
                ],
            ],
        ]);

        $this->assertContains(
            'app.modules.provider_class_missing',
            array_column($this->findings(), 'code'),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findings(): array
    {
        return array_map(
            fn (SetupValidationFinding $finding): array => $finding->toArray(),
            iterator_to_array(
                app(ModuleDependenciesSetupValidationContributor::class)->findings(),
                false,
            ),
        );
    }
}