<?php

namespace Tests\Feature\HumanVerification;

use App\Modules\Core\Validation\PublicHumanVerificationSetupValidationContributor;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\HumanVerificationManager;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DeployedFormsHumanVerificationDefaultTest extends TestCase
{
    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');

        parent::tearDown();
    }

    public function test_deployed_forms_cannot_be_disabled_by_legacy_global_or_surface_toggles(): void
    {
        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
        config()->set('human_verification.surfaces.forms.enabled', false);

        foreach (['staging', 'production'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $this->assertTrue(app(HumanVerificationManager::class)->enabledForSurface('forms'));
        }
    }

    public function test_local_forms_preserve_optional_verification(): void
    {
        config()->set('human_verification.enabled', false);

        foreach (['local', 'testing'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $this->assertFalse(app(HumanVerificationManager::class)->enabledForSurface('forms'));
        }
    }

    public function test_deployed_forms_fail_closed_and_setup_reports_missing_credentials(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');
        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
        config()->set('human_verification.providers.turnstile.site_key', null);
        config()->set('human_verification.providers.turnstile.secret_key', null);

        $manager = app(HumanVerificationManager::class);
        $result = $manager->verify('forms', null, ['forms.example.test']);

        $this->assertTrue($result->required);
        $this->assertFalse($result->passes());
        $this->assertSame(HumanVerificationResult::REASON_CONFIGURATION, $result->reason);
        $this->assertNotEmpty(iterator_to_array(app(
            PublicHumanVerificationSetupValidationContributor::class,
        )->findings()));
    }

    public function test_deployed_forms_accept_only_verified_provider_evidence(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
        config()->set('human_verification.providers.turnstile.site_key', 'test-site-key');
        config()->set('human_verification.providers.turnstile.secret_key', 'test-secret-key');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => true,
                'hostname' => 'forms.example.test',
                'action' => 'forms',
                'challenge_ts' => now('UTC')->toIso8601String(),
            ]),
        ]);

        $result = app(HumanVerificationManager::class)->verify(
            'forms',
            'provider-test-token',
            ['forms.example.test'],
        );

        $this->assertTrue($result->required);
        $this->assertTrue($result->passes());
        $this->assertSame('turnstile', $result->provider);
        Http::assertSentCount(1);
    }

    public function test_deployed_forms_route_denies_post_without_verification_even_when_global_disabled(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set('modules.enabled', ['forms']);
        config()->set('human_verification.enabled', false);
        config()->set('human_verification.providers.turnstile.site_key', null);
        config()->set('human_verification.providers.turnstile.secret_key', null);

        // Simulating production also enables Laravel's normal CSRF middleware.
        // Supply a real CSRF token so this request reaches the verification guard.
        $csrfToken = 'deployed-forms-route-test-csrf-token';

        $this->withSession(['_token' => $csrfToken])
            ->from('/some-form')
            ->post(
                route('forms.public.store', ['formSlug' => 'some-form']),
                ['_token' => $csrfToken],
            )
            ->assertRedirect()
            ->assertSessionHasErrors('human_verification');
    }
}