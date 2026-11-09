<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Data\DeploymentPlan;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class CoreCapabilityDiscoveryTest extends TestCase
{
    public function test_read_only_discovery_distinguishes_known_selected_and_dependency_modules(): void
    {
        $plan = $this->resolver(
            definitions: [
                'core' => [],
                'events' => [],
                'signups' => ['depends_on' => ['events']],
                'available_but_disabled' => [],
            ],
            selected: ['core', 'signups'],
        )->resolve();

        $this->assertTrue($plan->ready());
        $this->assertEqualsCanonicalizing(
            ['core', 'events', 'signups', 'available_but_disabled'],
            $plan->knownModules,
        );
        $this->assertEqualsCanonicalizing(['core', 'signups'], $plan->selectedModules);
        $this->assertEqualsCanonicalizing(['core', 'events', 'signups'], $plan->enabledModules);
        $this->assertNotContains('available_but_disabled', $plan->enabledModules);
        $this->assertSame([], $plan->missingModuleDefinitions);

        $report = $plan->toArray();
        $this->assertTrue($report['ready']);
        $this->assertEqualsCanonicalizing(
            $plan->selectedModules,
            $report['module_selection']['selected_modules'],
        );
        $this->assertEqualsCanonicalizing(
            $plan->knownModules,
            $report['module_selection']['known_modules'],
        );
        $this->assertSame([], $report['module_selection']['missing_module_definitions']);
    }

    public function test_missing_direct_module_definition_blocks_readiness(): void
    {
        $plan = $this->resolver(
            definitions: ['core' => []],
            selected: ['core', 'uninstalled_vertical'],
        )->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame(['uninstalled_vertical'], $plan->missingModuleDefinitions);
        $this->assertSame([], $plan->blockingEnvironmentRequirements());
    }

    public function test_missing_transitive_dependency_blocks_readiness_without_enabling_unrelated_module(): void
    {
        $plan = $this->resolver(
            definitions: [
                'core' => [],
                'signups' => ['depends_on' => ['unavailable_bridge']],
                'unrelated' => [],
            ],
            selected: ['core', 'signups'],
        )->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame(['unavailable_bridge'], $plan->missingModuleDefinitions);
        $this->assertNotContains('unrelated', $plan->enabledModules);
    }

    public function test_existing_json_command_exits_unsuccessfully_for_missing_definition(): void
    {
        $this->app->instance(DeploymentPlanResolver::class, $this->resolver(
            definitions: ['core' => []],
            selected: ['core', 'uninstalled_vertical'],
        ));

        $status = Artisan::call('engage:deployment-plan', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $status);
        $this->assertFalse($report['ready']);
        $this->assertSame(
            ['uninstalled_vertical'],
            $report['module_selection']['missing_module_definitions'],
        );
        $this->assertSame([], $report['environment_requirements']);
    }

    public function test_manual_plan_construction_remains_backward_compatible(): void
    {
        $plan = new DeploymentPlan(
            environment: 'testing',
            clientKey: 'example',
            enabledModules: ['core'],
            environmentRequirements: [],
            unusedEnvironmentKeys: [],
            coveredOwners: [],
        );

        $this->assertTrue($plan->ready());
        $this->assertSame([], $plan->toArray()['module_selection']['missing_module_definitions']);
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<int, string> $selected
     */
    private function resolver(array $definitions, array $selected): DeploymentPlanResolver
    {
        $modules = new class ($definitions, $selected) extends ModuleManager
        {
            /**
             * @param array<string, array<string, mixed>> $definitions
             * @param array<int, string> $selected
             */
            public function __construct(
                private readonly array $availableDefinitions,
                private readonly array $selectedKeys,
            ) {}

            public function definitions(): array
            {
                return $this->availableDefinitions;
            }

            public function enabledKeys(): array
            {
                return $this->selectedKeys;
            }
        };

        $environmentFiles = new class extends EnvironmentFileRepository
        {
            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/example/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return [];
            }
        };

        return new DeploymentPlanResolver(
            contributors: [],
            environmentFiles: $environmentFiles,
            modules: $modules,
        );
    }
}