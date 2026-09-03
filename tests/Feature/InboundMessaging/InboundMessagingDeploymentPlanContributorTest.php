<?php

namespace Tests\Feature\InboundMessaging;

use App\Modules\InboundMessaging\Deployment\InboundMessagingDeploymentPlanContributor;
use App\Modules\InboundMessaging\Providers\InboundMessagingModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class InboundMessagingDeploymentPlanContributorTest extends TestCase
{
    private string $originalApplicationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApplicationEnvironment = app()->environment();
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
            'internal_notifications',
        ]);
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(
            fn (): string => $this->originalApplicationEnvironment,
        );

        parent::tearDown();
    }

    public function test_local_keeps_live_receiving_domain_optional(): void
    {
        app()->detectEnvironment(fn (): string => 'local');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INBOUND_EMAIL_DOMAIN']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
            $requirements['INBOUND_EMAIL_DOMAIN']->valueRule,
        );
        $this->assertSame(
            EnvironmentRequirement::OPTIONAL,
            $requirements['INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL']->requirement,
        );
    }

    public function test_staging_requires_live_receiving_domain(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        $this->assertSame(
            EnvironmentRequirement::REQUIRED,
            $requirements['INBOUND_EMAIL_DOMAIN']->requirement,
        );
        $this->assertSame(
            EnvironmentRequirement::VALUE_RULE_EMAIL_DOMAIN,
            $requirements['INBOUND_EMAIL_DOMAIN']->valueRule,
        );
    }

    public function test_inbound_messaging_does_not_reclaim_messaging_provider_credentials(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $requirements = $this->requirements();

        foreach ([
            'EMAIL_PROVIDER',
            'RESEND_API_KEY',
            'RESEND_WEBHOOK_SECRET',
            'SMS_ENABLED',
            'SMS_PROVIDER',
            'TELNYX_API_KEY',
            'TELNYX_WEBHOOK_PUBLIC_KEY',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $requirements);
        }
    }

    public function test_notification_fallback_is_not_claimed_when_internal_notifications_are_disabled(): void
    {
        config()->set('modules.enabled', [
            'messaging',
            'inbound_messaging',
        ]);

        $requirements = $this->requirements();

        $this->assertArrayNotHasKey(
            'INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL',
            $requirements,
        );
    }

    public function test_module_provider_registers_deployment_contributor(): void
    {
        $this->app->register(
            InboundMessagingModuleServiceProvider::class,
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
            InboundMessagingDeploymentPlanContributor::class,
            $classes,
        );
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach (
            (new InboundMessagingDeploymentPlanContributor(
                new ModuleManager(),
            ))->environmentRequirements() as $requirement
        ) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }
}