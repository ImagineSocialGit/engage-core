<?php

namespace Tests\Feature\ConfigGeneration;

use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use App\Support\Modules\ModuleManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class RobTheMortgageCoachClientSelectionTest extends TestCase
{
    /**
     * @var array<string, array{environment: string|false, env_exists: bool, env: mixed, server_exists: bool, server: mixed}>
     */
    private static array $originalEnvironment = [];

    public function createApplication(): Application
    {
        $this->setBootstrapEnvironment('CLIENT_KEY', 'rob-the-mortgage-coach');

        return parent::createApplication();
    }

    public function test_client_key_selects_robs_preset_timezone_and_runtime_modules(): void
    {
        $this->assertSame('rob-the-mortgage-coach', config('client.key'));
        $this->assertSame('Rob The Mortgage Coach', config('client.name'));
        $this->assertSame('America/Denver', config('client.timezone'));
        $this->assertSame('mortgage', config('client.preset'));

        $this->assertContains('mortgage', config('modules.enabled'));

        $modules = app(ModuleManager::class);

        $this->assertTrue($modules->enabled('mortgage'));
        $this->assertContains('mortgage', $modules->enabledKeysWithDependencies());

        $this->assertSame([
            'rob_the_mortgage_coach_default',
            'webinar_default',
        ], config('presets.packages.mortgage.groups.contact_statuses'));

        $this->assertSame([
            'new',
            'prospect',
            'in_process',
            'closed_funded',
            'fallout_inactive',
        ], config('presets.modules.client.contact-statuses.groups.rob_the_mortgage_coach_default'));

    }

    public function test_rob_registration_contract_renders_only_transactional_consent_fields(): void
    {
        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: 'consent-contract-audit',
        );

        $this->assertSame([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => false, 'sms' => false],
        ], data_get($content, 'registration.consents'));

        view()->share('errors', new ViewErrorBag());

        $html = view('components.webinars.registration-form-modal', [
            'page' => $content['registration'],
            'tokens' => [],
            'style' => [],
            'series' => (object) ['slug' => 'consent-contract-audit'],
            'webinar' => new class
            {
                public function getKey(): int
                {
                    return 1;
                }
            },
            'webinarRegistrationChannels' => [
                'transactional' => ['email', 'sms'],
                'marketing' => ['email', 'sms'],
            ],
            'registrationPrefill' => [],
        ])->render();

        $this->assertStringContainsString('name="transactional_email_consent"', $html);
        $this->assertStringContainsString('name="transactional_sms_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_email_consent"', $html);
        $this->assertStringNotContainsString('name="marketing_sms_consent"', $html);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->restoreBootstrapEnvironment();
    }

    private function setBootstrapEnvironment(string $key, string $value): void
    {
        if (! array_key_exists($key, self::$originalEnvironment)) {
            self::$originalEnvironment[$key] = [
                'environment' => getenv($key),
                'env_exists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
            ];
        }

        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function restoreBootstrapEnvironment(): void
    {
        foreach (self::$originalEnvironment as $key => $original) {
            $original['environment'] === false
                ? putenv($key)
                : putenv("{$key}={$original['environment']}");

            if ($original['env_exists']) {
                $_ENV[$key] = $original['env'];
            } else {
                unset($_ENV[$key]);
            }

            if ($original['server_exists']) {
                $_SERVER[$key] = $original['server'];
            } else {
                unset($_SERVER[$key]);
            }
        }

        self::$originalEnvironment = [];
    }
}
