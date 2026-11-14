<?php

namespace Tests\Feature\Staging;

use App\Providers\ClientServiceProvider;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class StagingAccessTest extends TestCase
{
    private const USER = 'preview-operator';
    private const PASSWORD = 'correct-preview-password';
    private const TOKEN = 'known-test-csrf-token';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->app->detectEnvironment(static fn (): string => 'staging');
        config()->set('app.env', 'staging');
        config()->set('app.url', 'https://staging.example.test');
        config()->set(
            'app.key',
            'base64:'.base64_encode(str_repeat('a', 32))
        );
        config()->set('session.secure', true);
        config()->set('session.http_only', true);
        config()->set('session.same_site', 'lax');
        config()->set('staging.user', self::USER);
        config()->set('staging.password_hash', password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4]));

        Route::middleware('web')
            ->get('/__staging-private', static fn () => response('private content'))
            ->name('testing.staging.private');

        Route::middleware('web')
            ->post('/__staging-feature-submit', static fn () => response('submitted'))
            ->name('testing.staging.feature.submit');
    }

    public function test_anonymous_get_redirects_and_valid_login_returns_to_local_intended_path(): void
    {
        $this->get('/__staging-private?preview=1')
            ->assertRedirect(url('/staging-login'))
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        $this->get('/staging-login')
            ->assertOk()
            ->assertViewIs('staging.login');

        $oldSessionId = session()->getId();

        $this->submit(self::USER, self::PASSWORD)
            ->assertRedirect(url('/__staging-private?preview=1'));

        $this->assertNotSame($oldSessionId, session()->getId());

        $this->get('/__staging-private')
            ->assertOk()
            ->assertSee('private content');
    }

    public function test_login_rejects_bad_credentials_with_generic_error(): void
    {
        $this->submit('not-an-operator', self::PASSWORD)
            ->assertRedirect(url('/staging-login'))
            ->assertSessionHasErrors('login');

        $this->submit(self::USER, 'wrong-password')
            ->assertRedirect(url('/staging-login'))
            ->assertSessionHasErrors('login');

        $this->get('/__staging-private')->assertRedirect(url('/staging-login'));
    }

    public function test_missing_hash_and_legacy_plaintext_are_fail_closed(): void
    {
        config()->set('staging.password_hash', null);
        config()->set('staging.password', self::PASSWORD);

        $this->get('/__staging-private')->assertStatus(503);
        $this->get('/staging-login')->assertStatus(503);
    }

    public function test_insecure_session_configuration_fails_closed(): void
    {
        config()->set('session.secure', false);

        $this->get('/__staging-private')->assertStatus(503);
        $this->get('/staging-login')->assertStatus(503);
    }

    public function test_post_requires_csrf_and_login_is_throttled(): void
    {
        $this->post('/staging-login', [
            'user' => self::USER,
            'password' => self::PASSWORD,
        ])->assertStatus(419);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->submit(self::USER, 'wrong-password')
                ->assertRedirect(url('/staging-login'));
        }

        $this->submit(self::USER, self::PASSWORD)->assertStatus(429);
    }

    public function test_logout_invalidates_session_and_reissues_csrf_token(): void
    {
        $this->submit(self::USER, self::PASSWORD)->assertRedirect(url('/'));
        $csrfBefore = session()->token();

        $this->withSession(['_token' => $csrfBefore])
            ->post('/staging-logout', ['_token' => $csrfBefore])
            ->assertRedirect(url('/staging-login'));

        $this->assertNotSame($csrfBefore, session()->token());
        $this->get('/__staging-private')->assertRedirect(url('/staging-login'));
    }

    public function test_rotating_the_password_hash_revokes_existing_staging_sessions(): void
    {
        $this->submit(self::USER, self::PASSWORD)->assertRedirect(url('/'));
        $this->get('/__staging-private')->assertOk();

        config()->set('staging.password_hash', password_hash('a-new-password', PASSWORD_BCRYPT, ['cost' => 4]));

        $this->get('/__staging-private')->assertRedirect(url('/staging-login'));
    }

    public function test_external_intended_url_is_discarded(): void
    {
        $this->withSession(['staging.intended_path' => 'https://outside.example.test/steal']);

        $this->submit(self::USER, self::PASSWORD)->assertRedirect(url('/'));
    }

    public function test_anonymous_state_changing_feature_routes_are_forbidden(): void
    {
        $this->withSession(['_token' => self::TOKEN])
            ->post('/__staging-feature-submit', ['_token' => self::TOKEN])
            ->assertForbidden();
    }

    public function test_staging_access_does_not_affect_production_or_local_runtimes(): void
    {
        foreach (['production', 'local'] as $environment) {
            $this->app->detectEnvironment(static fn (): string => $environment);
            config()->set('app.env', $environment);

            $this->get('/__staging-private')->assertOk();
            $this->get('/staging-login')->assertNotFound();
        }
    }

    public function test_webhook_transport_exemptions_are_explicit_and_host_scoped(): void
    {
        config()->set('app.root_domain', 'example.test');

        Route::middleware('web')
            ->domain('webhooks.example.test')
            ->get('/__staging-external-form', static fn () => response('transport'))
            ->name('webhooks.forms.show');

        Route::middleware('web')
            ->domain('webhooks.example.test')
            ->get('/__staging-unreviewed-webhook', static fn () => response('private'))
            ->name('webhooks.unreviewed');

        $this->get('https://webhooks.example.test/__staging-external-form')->assertOk();
        $this->get('https://webhooks.example.test/__staging-unreviewed-webhook')
            ->assertRedirect('https://webhooks.example.test/staging-login');

        Route::middleware('web')
            ->get('/__staging-wrong-host', static fn () => response('private'))
            ->name('webhooks.forms.show');

        $this->get('https://staging.example.test/__staging-wrong-host')
            ->assertRedirect('https://staging.example.test/staging-login');
    }

    public function test_core_health_route_stays_available_without_staging_session(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_client_configuration_cannot_replace_staging_credentials(): void
    {
        $directory = sys_get_temp_dir().'/engage-core-staging-'.bin2hex(random_bytes(5));
        mkdir($directory, 0700, true);
        $file = $directory.'/staging.php';
        file_put_contents($file, "<?php return ['user' => 'unsafe'];\n");
        config()->set('client.config_path', $directory);

        try {
            $this->expectException(RuntimeException::class);
            (new ClientServiceProvider($this->app))->register();
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    private function submit(string $user, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->withSession(['_token' => self::TOKEN])
            ->post('/staging-login', [
                '_token' => self::TOKEN,
                'user' => $user,
                'password' => $password,
            ]);
    }
}