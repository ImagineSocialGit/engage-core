<?php

namespace Tests\Feature\Deployment;

use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\Contracts\DeploymentSetupContributor;
use App\Support\Deployment\Data\DeploymentSetupStep;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Deployment\EnvironmentFileRepository;
use App\Support\Modules\ModuleManager;
use InvalidArgumentException;
use Tests\TestCase;

class DeploymentSetupPlanTest extends TestCase
{
    public function test_setup_steps_are_sorted_and_exposed_in_the_machine_plan(): void
    {
        $plan = $this->resolver([
            $this->contributor('core', [
                new DeploymentSetupStep(
                    key: 'core.turnstile',
                    title: 'Turnstile',
                    reason: 'Public verification is enabled.',
                    instructions: ['Create the widget.'],
                    environmentKeys: ['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'],
                    verification: ['Verify one protected form.'],
                    priority: 20,
                ),
            ]),
            $this->contributor('webinars', [
                new DeploymentSetupStep(
                    key: 'webinars.zoom',
                    title: 'Zoom',
                    reason: 'Webinars are enabled.',
                    instructions: ['Configure the Zoom app.'],
                    environmentKeys: ['ZOOM_ACCOUNT_ID', 'ZOOM_CLIENT_ID', 'ZOOM_CLIENT_SECRET'],
                    verification: ['Verify registration.'],
                    priority: 60,
                ),
            ]),
        ])->resolve();

        $this->assertSame(
            ['core.turnstile', 'webinars.zoom'],
            array_map(
                static fn ($step): string => $step->step->key,
                $plan->setupSteps,
            ),
        );

        $serialized = $plan->toArray()['setup_steps'];

        $this->assertSame('core', $serialized[0]['owner']);
        $this->assertSame('core.turnstile', $serialized[0]['key']);
        $this->assertSame(
            ['TURNSTILE_SITE_KEY', 'TURNSTILE_SECRET_KEY'],
            $serialized[0]['environment_keys'],
        );
        $this->assertSame('webinars', $serialized[1]['owner']);
        $this->assertSame('webinars.zoom', $serialized[1]['key']);
    }

    public function test_setup_step_cannot_reference_an_unknown_environment_key(): void
    {
        $resolver = $this->resolver([
            $this->contributor('core', [
                new DeploymentSetupStep(
                    key: 'core.bad',
                    title: 'Bad step',
                    reason: 'Test invalid environment ownership.',
                    instructions: ['Do something.'],
                    environmentKeys: ['NOT_A_REAL_ENGAGE_ENV_KEY'],
                ),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('references unknown environment key');

        $resolver->resolve();
    }

    public function test_setup_step_keys_must_be_unique_across_active_contributors(): void
    {
        $resolver = $this->resolver([
            $this->contributor('core', [
                new DeploymentSetupStep(
                    key: 'shared.step',
                    title: 'First',
                    reason: 'First reason.',
                    instructions: ['First instruction.'],
                ),
            ]),
            $this->contributor('webinars', [
                new DeploymentSetupStep(
                    key: 'shared.step',
                    title: 'Second',
                    reason: 'Second reason.',
                    instructions: ['Second instruction.'],
                ),
            ]),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('was contributed more than once');

        $resolver->resolve();
    }

    /** @param array<int, object> $contributors */
    private function resolver(array $contributors): DeploymentPlanResolver
    {
        $repository = new class extends EnvironmentFileRepository
        {
            public function pathForScope(string $scope): string
            {
                return base_path($scope === 'root' ? '.env' : 'client/test-client/.env');
            }

            public function valuesForScope(string $scope): array
            {
                return $scope === 'root'
                    ? ['APP_ENV' => 'testing']
                    : [];
            }
        };

        return new DeploymentPlanResolver(
            contributors: $contributors,
            environmentFiles: $repository,
            modules: new ModuleManager(),
        );
    }

    /** @param array<int, DeploymentSetupStep> $steps */
    private function contributor(string $owner, array $steps): object
    {
        return new class ($owner, $steps) implements DeploymentPlanContributor, DeploymentSetupContributor
        {
            /** @param array<int, DeploymentSetupStep> $steps */
            public function __construct(
                private readonly string $ownerValue,
                private readonly array $steps,
            ) {}

            public function owner(): string
            {
                return $this->ownerValue;
            }

            public function environmentRequirements(): iterable
            {
                if ($this->ownerValue === 'core') {
                    yield EnvironmentRequirement::required(
                        'APP_ENV',
                        'Runtime environment must be explicit.',
                    );
                }
            }

            public function setupSteps(): iterable
            {
                yield from $this->steps;
            }
        };
    }
}