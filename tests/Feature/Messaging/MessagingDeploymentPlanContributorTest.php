<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Deployment\MessagingDeploymentPlanContributor;
use App\Modules\Messaging\Providers\MessagingModuleServiceProvider;
use App\Support\Deployment\Data\EnvironmentRequirement;
use App\Support\Modules\ModuleManager;
use Tests\TestCase;

class MessagingDeploymentPlanContributorTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    private string $originalApplicationEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApplicationEnvironment = app()->environment();

        foreach ([
            'EMAIL_PROVIDER',
            'SMS_ENABLED',
            'SMS_PROVIDER',
        ] as $key) {
            $this->originalEnvironment[$key] = getenv($key);
        }

        config()->set('modules.enabled', ['messaging']);
        config()->set('messaging.email.providers', [
            'resend' => ['provider' => \App\Integrations\Messaging\Email\Resend\ResendEmailProvider::class],
        ]);
        config()->set('sms.providers', [
            'telnyx' => ['from' => ['transactional' => null, 'marketing' => null]],
            'twilio' => ['from' => ['transactional' => null, 'marketing' => null]],
        ]);
        config()->set('messaging.email.from.transactional.address', null);
        config()->set('messaging.email.from.marketing.address', null);
        config()->set('messaging.email.providers.resend.from.transactional.address', null);
        config()->set('messaging.email.providers.resend.from.marketing.address', null);
        config()->set('sms.from.transactional', null);
        config()->set('sms.from.marketing', null);

        $this->setEnvironment('EMAIL_PROVIDER', 'resend');
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key.'='.$value);
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        app()->detectEnvironment(fn (): string => $this->originalApplicationEnvironment);

        parent::tearDown();
    }

    public function test_sms_disabled_does_not_claim_sms_provider_or_provider_credentials(): void
    {
        $this->setEnvironment('SMS_ENABLED', 'false');

        $requirements = $this->requirements();

        $this->assertSame(EnvironmentRequirement::REQUIRED, $requirements['SMS_ENABLED']->requirement);
        $this->assertArrayNotHasKey('SMS_PROVIDER', $requirements);
        $this->assertArrayNotHasKey('TELNYX_API_KEY', $requirements);
        $this->assertArrayNotHasKey('TWILIO_AUTH_TOKEN', $requirements);
    }

    public function test_local_selected_provider_credentials_remain_optional_because_dev_sink_owns_delivery(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        $this->setEnvironment('SMS_ENABLED', 'true');
        $this->setEnvironment('SMS_PROVIDER', 'telnyx');

        $requirements = $this->requirements();

        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirements['RESEND_API_KEY']->requirement);
        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirements['TELNYX_API_KEY']->requirement);
        $this->assertSame(EnvironmentRequirement::REQUIRED, $requirements['SMS_PROVIDER']->requirement);
    }

    public function test_staging_telnyx_requires_live_telnyx_values_and_not_twilio_values(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['messaging', 'inbound_messaging']);
        $this->setEnvironment('SMS_ENABLED', 'true');
        $this->setEnvironment('SMS_PROVIDER', 'telnyx');

        $requirements = $this->requirements();

        foreach ([
            'MAIL_MAILER',
            'MAIL_FROM_ADDRESS',
            'RESEND_API_KEY',
            'RESEND_WEBHOOK_SECRET',
            'TELNYX_API_KEY',
            'TELNYX_WEBHOOK_PUBLIC_KEY',
            'TELNYX_FROM_TRANSACTIONAL',
            'TELNYX_FROM_MARKETING',
        ] as $key) {
            $this->assertSame(EnvironmentRequirement::REQUIRED, $requirements[$key]->requirement);
        }

        $this->assertArrayNotHasKey('TWILIO_SID', $requirements);
        $this->assertArrayNotHasKey('TWILIO_AUTH_TOKEN', $requirements);
    }

    public function test_staging_rejects_twilio_until_live_inbound_webhook_routing_supports_it(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['messaging', 'inbound_messaging']);
        $this->setEnvironment('SMS_ENABLED', 'true');
        $this->setEnvironment('SMS_PROVIDER', 'twilio');

        $requirements = $this->requirements();

        $this->assertSame(['telnyx'], $requirements['SMS_PROVIDER']->allowedValues);
        $this->assertArrayNotHasKey('TWILIO_SID', $requirements);
        $this->assertArrayNotHasKey('TWILIO_AUTH_TOKEN', $requirements);
        $this->assertArrayNotHasKey('TELNYX_API_KEY', $requirements);
    }

    public function test_staging_live_sms_is_blocked_until_inbound_messaging_is_enabled(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['messaging']);
        $this->setEnvironment('SMS_ENABLED', 'true');
        $this->setEnvironment('SMS_PROVIDER', 'telnyx');

        $requirements = $this->requirements();

        $this->assertSame(['false'], $requirements['SMS_ENABLED']->allowedValues);
        $this->assertArrayNotHasKey('SMS_PROVIDER', $requirements);
        $this->assertArrayNotHasKey('TELNYX_API_KEY', $requirements);
    }

    public function test_existing_effective_telnyx_sender_fallback_avoids_redundant_sender_requirement(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['messaging', 'inbound_messaging']);
        $this->setEnvironment('SMS_ENABLED', 'true');
        $this->setEnvironment('SMS_PROVIDER', 'telnyx');
        config()->set('sms.providers.telnyx.from.transactional', '+15550000001');
        config()->set('sms.providers.telnyx.from.marketing', '+15550000002');

        $requirements = $this->requirements();

        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirements['TELNYX_FROM_TRANSACTIONAL']->requirement);
        $this->assertSame(EnvironmentRequirement::OPTIONAL, $requirements['TELNYX_FROM_MARKETING']->requirement);
    }

    public function test_staging_resend_requires_delivery_feedback_signature_secret_even_without_inbound_workspace(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['messaging']);
        $this->setEnvironment('SMS_ENABLED', 'false');

        $requirements = $this->requirements();

        $this->assertSame(EnvironmentRequirement::REQUIRED, $requirements['RESEND_WEBHOOK_SECRET']->requirement);
        $this->assertSame(EnvironmentRequirement::DEFAULTED, $requirements['RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS']->requirement);
    }

    public function test_messaging_module_provider_registers_deployment_contributor(): void
    {
        $this->app->register(MessagingModuleServiceProvider::class, force: true);

        $contributors = iterator_to_array(
            $this->app->tagged('deployment.plan_contributors'),
            false,
        );

        $classes = array_map(
            static fn (object $contributor): string => $contributor::class,
            $contributors,
        );

        $this->assertContains(MessagingDeploymentPlanContributor::class, $classes);
    }

    /** @return array<string, EnvironmentRequirement> */
    private function requirements(): array
    {
        $requirements = [];

        foreach ((new MessagingDeploymentPlanContributor(new ModuleManager()))->environmentRequirements() as $requirement) {
            $requirements[$requirement->key] = $requirement;
        }

        return $requirements;
    }

    private function setEnvironment(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}