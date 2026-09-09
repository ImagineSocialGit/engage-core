<?php

namespace Tests\Feature\HumanVerification;

use App\Integrations\HumanVerification\Turnstile\TurnstileHumanVerificationProvider;
use App\Support\Environment\EnvironmentVariableCatalog;
use App\Support\HumanVerification\Contracts\HumanVerificationProvider;
use App\Support\HumanVerification\Data\HumanVerificationRequest;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Data\HumanVerificationWidget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class PublicHumanVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('human_verification.enabled', true);
        config()->set('human_verification.provider', 'turnstile');
        config()->set('human_verification.grant_ttl_seconds', 1800);
        config()->set('human_verification.surfaces.webinars', [
            'enabled' => true,
            'action' => 'webinars',
        ]);
        config()->set(
            'human_verification.providers.turnstile.site_key',
            'test-site-key',
        );
        config()->set(
            'human_verification.providers.turnstile.secret_key',
            'test-secret-key',
        );
        config()->set(
            'human_verification.providers.turnstile.timeout_seconds',
            5,
        );
        config()->set(
            'human_verification.providers.turnstile.connect_timeout_seconds',
            2,
        );
        config()->set(
            'human_verification.providers.turnstile.retry_attempts',
            2,
        );
        config()->set(
            'human_verification.providers.turnstile.retry_sleep_milliseconds',
            0,
        );
        config()->set(
            'human_verification.providers.turnstile.challenge_max_age_seconds',
            300,
        );

        TestHumanVerificationProvider::$verifyCalls = 0;

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_successful_verification_is_reused_as_a_session_grant(): void
    {
        config()->set('human_verification.provider', 'test');
        config()->set('human_verification.providers.test', [
            'driver' => TestHumanVerificationProvider::class,
        ]);

        $this->registerProtectedRoute();

        $this->post('/_test/public-human-verification', [
            'cf-turnstile-response' => 'first-provider-token',
        ])
            ->assertOk()
            ->assertJsonPath('verified.provider', 'test');

        $this->post('/_test/public-human-verification')
            ->assertOk()
            ->assertJsonPath('verified.provider', 'test');

        $this->assertSame(
            1,
            TestHumanVerificationProvider::$verifyCalls,
        );
    }

    public function test_turnstile_is_validated_server_side_against_the_provider_response(): void
    {
        $hostname = 'public.example.test';

        Http::fake([
            TurnstileHumanVerificationProvider::SITEVERIFY_URL => Http::response([
                'success' => true,
                'challenge_ts' => CarbonImmutable::now('UTC')
                    ->subSecond()
                    ->toIso8601String(),
                'hostname' => $hostname,
                'action' => 'webinars',
                'error-codes' => [],
            ]),
        ]);

        $configuration = config(
            'human_verification.providers.turnstile',
        );

        $this->assertIsArray($configuration);

        $result = app(
            TurnstileHumanVerificationProvider::class,
        )->verify(
            new HumanVerificationRequest(
                surface: 'webinars',
                token: 'first-provider-token',
                expectedAction: 'webinars',
                expectedHostnames: [$hostname],
                remoteIp: '127.0.0.1',
            ),
            $configuration,
        );

        $this->assertTrue(
            $result->passes(),
            'Turnstile verification failed with reason ['.
                ($result->reason ?? 'unknown').
                '].',
        );
        $this->assertTrue($result->verified);
        $this->assertSame('turnstile', $result->provider);
        $this->assertSame($hostname, $result->hostname);
        $this->assertSame('webinars', $result->action);
        $this->assertNull($result->reason);

        Http::assertSentCount(1);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->url()
                    === TurnstileHumanVerificationProvider::SITEVERIFY_URL
                && ($data['secret'] ?? null) === 'test-secret-key'
                && ($data['response'] ?? null) === 'first-provider-token'
                && ($data['remoteip'] ?? null) === '127.0.0.1'
                && is_string($data['idempotency_key'] ?? null)
                && trim((string) $data['idempotency_key']) !== '';
        });
    }

    public function test_missing_or_invalid_verification_fails_closed_when_the_surface_is_enabled(): void
    {
        $this->registerProtectedRoute();

        Http::fake();

        $this->from('/before')
            ->post('/_test/public-human-verification')
            ->assertRedirect('/before')
            ->assertSessionHasErrors('human_verification');

        Http::assertNothingSent();
    }

    public function test_disabled_human_verification_does_not_require_a_provider_token(): void
    {
        config()->set('human_verification.enabled', false);

        $this->registerProtectedRoute();

        $this->post('/_test/public-human-verification')
            ->assertOk()
            ->assertJsonPath('verified', null);
    }

    public function test_browser_bridge_intercepts_programmatic_post_submissions_used_by_existing_public_flows(): void
    {
        $script = file_get_contents(
            resource_path('js/public-human-verification.js'),
        );

        $this->assertIsString($script);
        $this->assertStringContainsString(
            'const bootstrap = window.__publicHumanVerificationBootstrap || null',
            $script,
        );
        $this->assertStringContainsString(
            'HTMLFormElement.prototype.submit = function publicHumanVerificationSubmit()',
            $script,
        );
        $this->assertStringContainsString(
            'nativeSubmit.call(form)',
            $script,
        );
        $this->assertStringContainsString(
            'bootstrap.pendingForms.splice(0)',
            $script,
        );

        $component = file_get_contents(resource_path(
            'views/components/public-surface/human-verification.blade.php',
        ));

        $this->assertIsString($component);
        $this->assertStringContainsString(
            'publicHumanVerificationBootstrapSubmit',
            $component,
        );
    }

    public function test_turnstile_environment_ownership_and_secret_classification_are_explicit(): void
    {
        $enabled = EnvironmentVariableCatalog::definition(
            'PUBLIC_HUMAN_VERIFICATION_ENABLED',
        );
        $siteKey = EnvironmentVariableCatalog::definition(
            'TURNSTILE_SITE_KEY',
        );
        $secretKey = EnvironmentVariableCatalog::definition(
            'TURNSTILE_SECRET_KEY',
        );
        $timeout = EnvironmentVariableCatalog::definition(
            'TURNSTILE_TIMEOUT_SECONDS',
        );

        $this->assertSame('client', $enabled->scope);
        $this->assertSame('core', $enabled->owner);
        $this->assertFalse($enabled->secret);

        $this->assertSame('client', $siteKey->scope);
        $this->assertFalse($siteKey->secret);

        $this->assertSame('client', $secretKey->scope);
        $this->assertTrue($secretKey->secret);

        $this->assertSame('root', $timeout->scope);
        $this->assertFalse($timeout->secret);
    }

    private function registerProtectedRoute(): void
    {
        Route::middleware([
            'web',
            'public-human:webinars',
        ])->post(
            '/_test/public-human-verification',
            function (Request $request) {
                return response()->json([
                    'verified' => $request->attributes->get(
                        'public_human_verification',
                    ),
                ]);
            },
        );
    }
}

final class TestHumanVerificationProvider implements HumanVerificationProvider
{
    public static int $verifyCalls = 0;

    public function key(): string
    {
        return 'test';
    }

    public function validateConfiguration(array $configuration): void
    {
        //
    }

    public function widget(
        string $action,
        array $configuration,
    ): HumanVerificationWidget {
        return new HumanVerificationWidget(
            provider: 'test',
            scriptUrl: 'https://example.test/widget.js',
            siteKey: 'test-site-key',
            action: $action,
        );
    }

    public function verify(
        HumanVerificationRequest $request,
        array $configuration,
    ): HumanVerificationResult {
        self::$verifyCalls++;

        return HumanVerificationResult::verified(
            provider: 'test',
            verifiedAt: CarbonImmutable::now('UTC'),
            challengeAt: CarbonImmutable::now('UTC')->subSecond(),
            hostname: $request->expectedHostnames[0],
            action: $request->expectedAction,
        );
    }
}