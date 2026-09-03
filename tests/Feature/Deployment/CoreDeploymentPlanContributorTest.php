<?php

namespace Tests\Feature\Deployment;

use App\Modules\Core\Deployment\CoreDeploymentPlanContributor;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class CoreDeploymentPlanContributorTest extends TestCase
{
    public function test_local_and_testing_keep_client_namespace_prefixes_optional(): void
    {
        foreach (['local', 'testing'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $requirements = $this->requirements();

            foreach ($this->namespaceKeys() as $key) {
                $this->assertSame(
                    EnvironmentRequirement::OPTIONAL,
                    $requirements[$key]->requirement,
                    "Expected [{$key}] to remain optional in [{$environment}].",
                );
            }
        }
    }

    public function test_staging_and_production_require_client_namespace_prefixes(): void
    {
        foreach (['staging', 'production'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $requirements = $this->requirements();

            foreach ($this->namespaceKeys() as $key) {
                $this->assertSame(
                    EnvironmentRequirement::REQUIRED,
                    $requirements[$key]->requirement,
                    "Expected [{$key}] to be required in [{$environment}].",
                );
            }
        }
    }

    public function test_core_identity_and_database_values_remain_required_in_local(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        foreach ([
            'ROOT_DOMAIN',
            'APP_URL',
            'CRM_APP_URL',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::REQUIRED,
                $requirements[$key]->requirement,
            );
        }
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach ((new CoreDeploymentPlanContributor())->environmentRequirements() as $requirement) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }

    /** @return array<int, string> */
    private function namespaceKeys(): array
    {
        return [
            'CACHE_PREFIX',
            'REDIS_PREFIX',
            'HORIZON_PREFIX',
        ];
    }
}