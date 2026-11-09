<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentIntegrityFinding;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class EnvironmentIntegrityPlanTest extends TestCase
{
    public function test_unregistered_requirement_becomes_redacted_blocker_instead_of_exception(): void
    {
        $secret = 'must-not-appear-in-plan';
        $resolver = $this->resolver(
            [$this->contributor('donations', [
                EnvironmentRequirement::required(
                    'DONATIONS_UNREGISTERED_TEST_KEY',
                    'The selected checkout requires this signing credential.',
                ),
            ])],
            ['root' => [], 'client' => ['DONATIONS_UNREGISTERED_TEST_KEY' => $secret]],
        );

        $plan = $resolver->resolve();
        $report = $plan->toArray();

        $this->assertFalse($plan->ready());
        $this->assertSame([], $report['environment_requirements']);
        $this->assertSame(
            [EnvironmentIntegrityFinding::UNREGISTERED_REQUIREMENT],
            array_column($report['environment_integrity_findings'], 'code'),
        );
        $this->assertSame('donations', $report['environment_integrity_findings'][0]['owner']);
        $this->assertStringNotContainsString($secret, json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function test_unregistered_optional_requirement_is_still_an_integrity_blocker(): void
    {
        $plan = $this->resolver([
            $this->contributor('donations', [
                EnvironmentRequirement::optional('DONATIONS_UNREGISTERED_OPTIONAL_KEY', 'Optional behavior.'),
            ]),
        ])->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame(
            EnvironmentIntegrityFinding::UNREGISTERED_REQUIREMENT,
            $plan->environmentIntegrityFindings[0]->code,
        );
    }

    public function test_wrong_scope_is_blocked_even_if_required_value_is_also_present_correctly(): void
    {
        $plan = $this->resolver(
            [$this->contributor('core', [
                EnvironmentRequirement::required('APP_URL', 'Client URL must be explicit.'),
                EnvironmentRequirement::required('APP_ENV', 'Process environment must be explicit.'),
            ])],
            [
                'root' => ['APP_ENV' => 'testing', 'APP_URL' => 'https://incorrect.example'],
                'client' => ['APP_URL' => 'https://correct.example', 'APP_ENV' => 'testing'],
            ],
        )->resolve();

        $this->assertFalse($plan->ready());
        $this->assertCount(2, $plan->environmentIntegrityFindings);
        $this->assertEqualsCanonicalizing(
            ['APP_ENV', 'APP_URL'],
            array_column($plan->toArray()['environment_integrity_findings'], 'key'),
        );
        $this->assertSame([], $plan->blockingEnvironmentRequirements());
    }

    public function test_unknown_unowned_key_in_env_does_not_block_platform_plan(): void
    {
        $plan = $this->resolver(
            [$this->contributor('core', [EnvironmentRequirement::required('APP_ENV', 'Environment.')])],
            ['root' => ['APP_ENV' => 'testing', 'SOME_EXTERNAL_TOOL_SETTING' => 'kept'], 'client' => []],
        )->resolve();

        $this->assertTrue($plan->ready());
        $this->assertSame([], $plan->environmentIntegrityFindings);
    }

    public function test_unregistered_setup_key_reports_its_step_without_exposing_values(): void
    {
        $plan = $this->resolver([
            $this->contributor('core', [], [
                new DeploymentSetupStep(
                    key: 'core.external',
                    title: 'External setup',
                    reason: 'Configure the external endpoint.',
                    instructions: ['Verify it.'],
                    environmentKeys: ['CORE_UNREGISTERED_SETUP_TEST_KEY'],
                ),
            ]),
        ])->resolve();

        $this->assertFalse($plan->ready());
        $this->assertSame('core.external', $plan->environmentIntegrityFindings[0]->setupStep);
        $this->assertSame(EnvironmentIntegrityFinding::UNREGISTERED_SETUP_KEY, $plan->environmentIntegrityFindings[0]->code);
    }

    public function test_json_command_emits_a_failure_plan_instead_of_crashing(): void
    {
        $this->app->instance(DeploymentPlanResolver::class, $this->resolver([
            $this->contributor('donations', [
                EnvironmentRequirement::required('DONATIONS_UNREGISTERED_PLAN_KEY', 'Missing catalog registration.'),
            ]),
        ]));

        $exitCode = Artisan::call('engage:deployment-plan', ['--json' => true]);
        $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['ready']);
        $this->assertSame(
            ['unregistered_requirement'],
            array_column($report['environment_integrity_findings'], 'code'),
        );
    }

    /**
     * @param array<int, DeploymentPlanContributor> $contributors
     * @param array<string, array<string, string>> $values
     */
    private function resolver(array $contributors, array $values = []): DeploymentPlanResolver
    {
        $repository = new class ($values) extends EnvironmentFileRepository
        {
            /** @param array<string, array<string, string>> $values */
            public function __construct(private readonly array $values) {}

            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/isolated/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $this->values[$scope] ?? [];
            }
        };

        $modules = new class extends ModuleManager
        {
            public function definitions(): array
            {
                return ['core' => []];
            }

            public function enabledKeys(): array
            {
                return ['core'];
            }
        };

        return new DeploymentPlanResolver($contributors, $repository, $modules);
    }

    /**
     * @param array<int, EnvironmentRequirement> $requirements
     * @param array<int, DeploymentSetupStep> $steps
     */
    private function contributor(string $owner, array $requirements, array $steps = []): DeploymentPlanContributor
    {
        return new class ($owner, $requirements, $steps) implements DeploymentPlanContributor, DeploymentSetupContributor
        {
            public function __construct(
                private readonly string $owningModule,
                private readonly array $requirements,
                private readonly array $steps,
            ) {}

            public function owner(): string
            {
                return $this->owningModule;
            }

            public function environmentRequirements(): iterable
            {
                return $this->requirements;
            }

            public function setupSteps(): iterable
            {
                return $this->steps;
            }
        };
    }
}