<?php

namespace Tests\Feature\Media;

use App\Modules\Media\Deployment\MediaStorageDeploymentPlanContributor;
use App\Modules\Media\Providers\MediaModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class MediaDeploymentPlanContributorTest extends TestCase
{
    private string $originalApplicationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApplicationEnvironment = app()->environment();
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => $this->originalApplicationEnvironment);

        parent::tearDown();
    }

    public function test_local_media_storage_values_remain_optional(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        foreach ([
            'FILESYSTEM_DISK',
            'DO_SPACES_KEY',
            'DO_SPACES_SECRET',
            'DO_SPACES_ENDPOINT',
            'DO_SPACES_REGION',
            'DO_SPACES_BUCKET',
            'CDN_BASE_URL',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::OPTIONAL,
                $requirements[$key]->requirement,
                $key,
            );
        }
    }

    public function test_staging_media_requires_writable_spaces_and_public_cdn_identity(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        foreach ([
            'FILESYSTEM_DISK',
            'DO_SPACES_KEY',
            'DO_SPACES_SECRET',
            'DO_SPACES_ENDPOINT',
            'DO_SPACES_REGION',
            'DO_SPACES_BUCKET',
            'CDN_BASE_URL',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::REQUIRED,
                $requirements[$key]->requirement,
                $key,
            );
        }

        $this->assertSame('spaces', $requirements['FILESYSTEM_DISK']->expectedValue);
        $this->assertSame(['spaces'], $requirements['FILESYSTEM_DISK']->allowedValues);
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            $requirements['DO_SPACES_ENDPOINT']->valueRule,
        );
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_HTTP_ORIGIN,
            $requirements['CDN_BASE_URL']->valueRule,
        );
    }

    public function test_media_provider_registers_storage_deployment_coverage(): void
    {
        $this->app->register(MediaModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );
        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(
            MediaStorageDeploymentPlanContributor::class,
            $classes,
        );
        $this->assertSame(
            'storage',
            app(MediaStorageDeploymentPlanContributor::class)->owner(),
        );
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach (app(MediaStorageDeploymentPlanContributor::class)->environmentRequirements() as $requirement) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }
}