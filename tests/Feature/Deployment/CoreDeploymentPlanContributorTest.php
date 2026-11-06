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

    public function test_live_environments_require_ansible_provisioned_database_and_redis_transport(): void
    {
        foreach (['staging', 'production'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $requirements = $this->requirements();

            foreach ([
                'DB_CONNECTION',
                'DB_HOST',
                'MYSQL_ATTR_SSL_CA',
                'REDIS_CLIENT',
                'REDIS_HOST',
                'REDIS_USERNAME',
                'REDIS_PASSWORD',
                'REDIS_SCHEME',
                'REDIS_TLS_CA',
            ] as $key) {
                $this->assertSame(
                    EnvironmentRequirement::REQUIRED,
                    $requirements[$key]->requirement,
                );
            }

            $this->assertSame('mysql', $requirements['DB_CONNECTION']->expectedValue);
            $this->assertSame('phpredis', $requirements['REDIS_CLIENT']->expectedValue);
            $this->assertSame('tls', $requirements['REDIS_SCHEME']->expectedValue);
            $this->assertArrayNotHasKey('REDIS_URL', $requirements);
        }
    }

    public function test_local_infrastructure_transport_requirements_are_not_mandatory(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        foreach (['DB_HOST', 'REDIS_HOST', 'REDIS_USERNAME', 'REDIS_SCHEME'] as $key) {
            $this->assertSame(EnvironmentRequirement::DEFAULTED, $requirements[$key]->requirement);
        }

        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirements['REDIS_TLS_CA']->requirement);
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