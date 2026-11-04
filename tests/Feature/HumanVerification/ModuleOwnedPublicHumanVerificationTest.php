<?php

namespace Tests\Feature\HumanVerification;

use App\Modules\Core\Validation\PublicHumanVerificationSetupValidationContributor;
use App\Support\HumanVerification\Contracts\RequiredPublicHumanVerificationSurface;
use App\Support\HumanVerification\Data\HumanVerificationResult;
use App\Support\HumanVerification\Exceptions\HumanVerificationConfigurationException;
use App\Support\HumanVerification\HumanVerificationManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ModuleOwnedPublicHumanVerificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->tag(
            [ExampleRequiredPublicHumanVerificationSurface::class],
            RequiredPublicHumanVerificationSurface::TAG,
        );

        config()->set('human_verification.enabled', false);
        config()->set('human_verification.providers.turnstile.site_key', null);
        config()->set('human_verification.providers.turnstile.secret_key', null);
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => 'testing');

        parent::tearDown();
    }

    public function test_any_tagged_module_surface_is_mandatory_when_deployed_without_legacy_toggles(): void
    {
        config()->set('human_verification.surfaces.example_public.enabled', false);

        foreach (['staging', 'production'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $manager = app(HumanVerificationManager::class);
            $this->assertTrue($manager->enabledForSurface('example_public'));
            $this->assertFalse($manager->enabledForSurface('unregistered_public'));
        }
    }

    public function test_deployed_missing_credentials_block_and_report_configuration_error(): void
    {
        app()->detectEnvironment(fn (): string => 'staging');

        $manager = app(HumanVerificationManager::class);
        $result = $manager->verify('example_public', null, ['example.test']);

        $this->assertTrue($result->required);
        $this->assertFalse($result->passes());
        $this->assertSame(HumanVerificationResult::REASON_CONFIGURATION, $result->reason);
        $this->assertNotEmpty(iterator_to_array(app(
            PublicHumanVerificationSetupValidationContributor::class,
        )->findings()));
    }

    public function test_local_and_testing_bypass_when_verification_is_not_opted_in(): void
    {
        foreach (['testing', 'local'] as $environment) {
            app()->detectEnvironment(fn (): string => $environment);

            $this->assertFalse(app(HumanVerificationManager::class)->enabledForSurface('example_public'));
        }
    }

    public function test_explicit_local_opt_in_uses_module_owned_action(): void
    {
        app()->detectEnvironment(fn (): string => 'local');
        config()->set('human_verification.enabled', true);
        config()->set('human_verification.providers.turnstile.site_key', 'test-key');
        config()->set('human_verification.providers.turnstile.secret_key', 'test-secret');

        $this->assertTrue(app(HumanVerificationManager::class)->enabledForSurface('example_public'));
    }

    public function test_deployed_valid_provider_evidence_uses_module_action(): void
    {
        app()->detectEnvironment(fn (): string => 'production');
        config()->set('human_verification.providers.turnstile.site_key', 'test-key');
        config()->set('human_verification.providers.turnstile.secret_key', 'test-secret');

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => true,
                'hostname' => 'example.test',
                'action' => 'example_action',
                'challenge_ts' => now('UTC')->toIso8601String(),
            ]),
        ]);

        $result = app(HumanVerificationManager::class)->verify(
            'example_public',
            'token-from-provider',
            ['example.test'],
        );

        $this->assertTrue($result->required);
        $this->assertTrue($result->passes());
        $this->assertSame('turnstile', $result->provider);
        Http::assertSentCount(1);
    }

    public function test_deployed_middleware_blocks_unverified_post_before_handler_runs(): void
    {
        app()->detectEnvironment(fn (): string => 'production');

        Route::middleware(['web', 'public-human:example_public'])
            ->post('/__example-human-verification', static fn () => response('handler-reached'));

        $token = 'example-surface-csrf-token';

        $this->withSession(['_token' => $token])
            ->from('/example')
            ->post('/__example-human-verification', ['_token' => $token])
            ->assertRedirect()
            ->assertSessionHasErrors('human_verification');
    }

    public function test_unselected_module_declaration_does_not_activate_provider_verification(): void
    {
        $this->app->tag(
            [UnselectedExamplePublicHumanVerificationSurface::class],
            RequiredPublicHumanVerificationSurface::TAG,
        );
        config()->set('modules.enabled', []);
        app()->detectEnvironment(fn (): string => 'production');

        $manager = app(HumanVerificationManager::class);

        $this->assertTrue($manager->enabledForSurface('example_public'));
        $this->assertFalse($manager->enabledForSurface('not_selected_public'));
    }

    public function test_duplicate_surface_declarations_fail_closed(): void
    {
        $this->app->tag(
            [DuplicateExampleRequiredPublicHumanVerificationSurface::class],
            RequiredPublicHumanVerificationSurface::TAG,
        );

        $this->expectException(HumanVerificationConfigurationException::class);

        app(HumanVerificationManager::class)->enabledForSurface('example_public');
    }
}

final class ExampleRequiredPublicHumanVerificationSurface implements RequiredPublicHumanVerificationSurface
{
    public function moduleKey(): string
    {
        return 'core';
    }

    public function surface(): string
    {
        return 'example_public';
    }

    public function action(): string
    {
        return 'example_action';
    }
}

final class UnselectedExamplePublicHumanVerificationSurface implements RequiredPublicHumanVerificationSurface
{
    public function moduleKey(): string
    {
        return 'unselected_module';
    }

    public function surface(): string
    {
        return 'not_selected_public';
    }

    public function action(): string
    {
        return 'not_selected';
    }
}

final class DuplicateExampleRequiredPublicHumanVerificationSurface implements RequiredPublicHumanVerificationSurface
{
    public function moduleKey(): string
    {
        return 'core';
    }

    public function surface(): string
    {
        return 'example_public';
    }

    public function action(): string
    {
        return 'different_action';
    }
}