<?php

namespace Tests\Feature\InternalNotifications;

use App\Modules\InternalNotifications\Deployment\InternalNotificationsDeploymentPlanContributor;
use App\Modules\InternalNotifications\Providers\InternalNotificationsModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use Tests\TestCase;

class InternalNotificationsDeploymentPlanContributorTest extends TestCase
{
    private string $originalApplicationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApplicationEnvironment = app()->environment();

        config()->set(
            'messaging.channel_availability.email.surfaces.internal_notifications',
            true,
        );
        config()->set(
            'messaging.internal_notifications.email.from_address',
            null,
        );
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(
            fn (): string => $this->originalApplicationEnvironment,
        );

        parent::tearDown();
    }

    public function test_local_keeps_internal_sender_overrides_optional(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INTERNAL_NOTIFICATION_FROM_ADDRESS']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INTERNAL_NOTIFICATION_FROM_NAME']->requirement,
        );
    }

    public function test_staging_requires_internal_sender_when_shared_fallback_is_unresolved(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::REQUIRED,
            $requirements['INTERNAL_NOTIFICATION_FROM_ADDRESS']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INTERNAL_NOTIFICATION_FROM_NAME']->requirement,
        );
    }

    public function test_staging_keeps_internal_sender_optional_when_shared_fallback_resolves(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set(
            'messaging.internal_notifications.email.from_address',
            'notifications@example.test',
        );

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INTERNAL_NOTIFICATION_FROM_ADDRESS']->requirement,
        );
    }

    public function test_disabled_internal_email_surface_does_not_require_sender(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set(
            'messaging.channel_availability.email.surfaces.internal_notifications',
            false,
        );

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INTERNAL_NOTIFICATION_FROM_ADDRESS']->requirement,
        );
    }

    public function test_internal_notifications_does_not_reclaim_messaging_or_inbound_keys(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        foreach ([
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
            'EMAIL_PROVIDER',
            'RESEND_API_KEY',
            'RESEND_WEBHOOK_SECRET',
            'SMS_ENABLED',
            'SMS_PROVIDER',
            'TELNYX_API_KEY',
            'TELNYX_FROM_NOTIFICATIONS',
            'INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $requirements);
        }
    }

    public function test_module_provider_registers_deployment_contributor(): void
    {
        $this->app->register(
            InternalNotificationsModuleServiceProvider::class,
            force: true,
        );

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(
            InternalNotificationsDeploymentPlanContributor::class,
            $classes,
        );
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach (
            (new InternalNotificationsDeploymentPlanContributor())
                ->environmentRequirements() as $requirement
        ) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }
}