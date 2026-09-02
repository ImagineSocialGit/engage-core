<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Deployment\WebinarsDeploymentPlanContributor;
use App\Modules\Webinars\Providers\WebinarsModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class WebinarsDeploymentPlanContributorTest extends TestCase
{
    private string $originalApplicationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApplicationEnvironment = app()->environment();

        config()->set('webinars.provider', 'zoom');
        config()->set('webinars.providers', [
            'zoom' => [
                'provider' => \App\Integrations\Webinars\Zoom\ZoomWebinarProvider::class,
                'event_types' => [
                    'webinar' => [
                        'provider' => \App\Integrations\Webinars\Zoom\ZoomWebinarProvider::class,
                    ],
                    'meeting' => [
                        'provider' => \App\Integrations\Webinars\Zoom\ZoomMeetingProvider::class,
                    ],
                ],
            ],
        ]);
        config()->set('webinars.post_event.attendance.enabled', true);
        config()->set('webinars.post_event.recordings.enabled', true);
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(
            fn (): string => $this->originalApplicationEnvironment,
        );

        parent::tearDown();
    }

    public function test_safe_webinar_defaults_do_not_force_redundant_environment_values(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::DEFAULTED,
            $requirements['WEBINAR_PROVIDER']->requirement,
        );
        $this->assertSame(['zoom'], $requirements['WEBINAR_PROVIDER']->allowedValues);
        $this->assertSame(
            EnvironmentRequirement::DEFAULTED,
            $requirements['WEBINAR_APP_URL']->requirement,
        );

        foreach ([
            'CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS',
            'CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS',
            'CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS',
            'WEBINAR_REGISTRATION_QUEUE',
            'WEBINAR_WEBHOOK_QUEUE',
            'WEBINAR_REMINDER_QUEUE',
            'WEBINAR_CONFIRMATION_MESSAGE_QUEUE',
            'WEBINAR_FOLLOWUP_QUEUE',
            'ZOOM_BASE_URL',
            'ZOOM_OAUTH_URL',
            'ZOOM_OAUTH_TOKEN_TTL_SECONDS',
            'ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::DEFAULTED,
                $requirements[$key]->requirement,
                $key,
            );
        }

        $this->assertArrayNotHasKey('WEBINAR_BOOKING_URL', $requirements);
    }

    public function test_local_zoom_credentials_and_webhook_secret_remain_optional(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        foreach ([
            'ZOOM_ACCOUNT_ID',
            'ZOOM_CLIENT_ID',
            'ZOOM_CLIENT_SECRET',
            'ZOOM_WEBHOOK_SECRET',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::OPTIONAL,
                $requirements[$key]->requirement,
                $key,
            );
        }
    }

    public function test_staging_zoom_requires_oauth_credentials_and_enabled_webhook_secret(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        foreach ([
            'ZOOM_ACCOUNT_ID',
            'ZOOM_CLIENT_ID',
            'ZOOM_CLIENT_SECRET',
            'ZOOM_WEBHOOK_SECRET',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::REQUIRED,
                $requirements[$key]->requirement,
                $key,
            );
        }
    }

    public function test_staging_without_post_event_webhook_capabilities_keeps_webhook_secret_optional(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('webinars.post_event.attendance.enabled', false);
        config()->set('webinars.post_event.recordings.enabled', false);

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['ZOOM_WEBHOOK_SECRET']->requirement,
        );

        foreach ([
            'ZOOM_ACCOUNT_ID',
            'ZOOM_CLIENT_ID',
            'ZOOM_CLIENT_SECRET',
        ] as $key) {
            $this->assertSame(
                EnvironmentRequirement::REQUIRED,
                $requirements[$key]->requirement,
                $key,
            );
        }
    }

    public function test_unsupported_provider_selection_stops_provider_specific_requirement_resolution(): void
    {
        config()->set('webinars.provider', 'unsupported');

        $requirements = $this->requirements();

        $this->assertSame(['zoom'], $requirements['WEBINAR_PROVIDER']->allowedValues);
        $this->assertArrayNotHasKey('ZOOM_ACCOUNT_ID', $requirements);
        $this->assertArrayNotHasKey('ZOOM_WEBHOOK_SECRET', $requirements);
    }

    public function test_webinars_module_provider_registers_deployment_contributor(): void
    {
        $this->app->register(WebinarsModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(WebinarsDeploymentPlanContributor::class, $classes);
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach ((new WebinarsDeploymentPlanContributor())->environmentRequirements() as $requirement) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }
}