<?php

namespace Tests\Feature\Deployment;

use App\Modules\Core\Deployment\CoreDeploymentPlanContributor;
use App\Modules\Forms\Deployment\FormsDeploymentPlanContributor;
use App\Modules\Forms\Providers\FormsModuleServiceProvider;
use App\Support\Deployment\Contracts\DeploymentPlanContributor;
use App\Support\Deployment\DeploymentPlanResolver;
use App\Support\Environment\EnvironmentVariableCatalog;
use Illuminate\Support\ServiceProvider;
use Tests\TestCase;

class DeploymentPlanRegistrationTest extends TestCase
{
    public function test_core_deployment_contributor_is_registered_by_the_active_core_module(): void
    {
        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(CoreDeploymentPlanContributor::class, $classes);
        $this->assertInstanceOf(DeploymentPlanResolver::class, app(DeploymentPlanResolver::class));
    }

    public function test_forms_provider_registers_forms_deployment_contributor(): void
    {
        $this->app->register(FormsModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(FormsDeploymentPlanContributor::class, $classes);
    }

    public function test_every_environment_catalog_owner_has_a_registered_deployment_contributor(): void
    {
        $providers = collect(config('modules.modules', []))
            ->flatMap(
                static fn (mixed $definition): array => is_array($definition)
                    && is_array($definition['providers'] ?? null)
                        ? $definition['providers']
                        : [],
            )
            ->filter(
                static fn (mixed $provider): bool => is_string($provider)
                    && class_exists($provider)
                    && is_subclass_of($provider, ServiceProvider::class),
            )
            ->unique()
            ->values();

        foreach ($providers as $provider) {
            (new $provider($this->app))->register();
        }

        $coveredOwners = collect(
            iterator_to_array(
                $this->app->tagged('deployment.plan_contributors'),
                false,
            ),
        )
            ->filter(
                static fn (mixed $contributor): bool => $contributor instanceof DeploymentPlanContributor,
            )
            ->map(
                static fn (DeploymentPlanContributor $contributor): string => trim($contributor->owner()),
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $catalogOwners = collect(EnvironmentVariableCatalog::definitions())
            ->map(
                static fn ($definition): string => $definition->owner,
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $missingOwners = $catalogOwners
            ->diff($coveredOwners)
            ->values()
            ->all();

        $this->assertSame(
            [],
            $missingOwners,
            'Environment catalog owner(s) missing deployment contributor coverage: '.implode(', ', $missingOwners),
        );
    }

}