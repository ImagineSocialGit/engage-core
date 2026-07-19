<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarRegistrationBoundaryContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_waitlist_and_registration_use_separate_named_limiters(): void
    {
        $routes = file_get_contents(base_path('routes/webinar.php'));

        $this->assertIsString($routes);
        $this->assertStringContainsString("throttle:webinar-waitlist", $routes);
        $this->assertStringContainsString("throttle:webinar-registration", $routes);
    }

    public function test_rate_limit_identity_material_is_hashed_and_scoped(): void
    {
        $provider = file_get_contents(base_path(
            'app/Modules/Webinars/Providers/WebinarsModuleServiceProvider.php',
        ));

        $this->assertIsString($provider);
        $this->assertStringContainsString('hash_hmac(', $provider);
        $this->assertStringContainsString("'webinar:%s:%s:email:%s'", $provider);
        $this->assertStringContainsString("'webinar:%s:%s:phone:%s'", $provider);
        $this->assertStringContainsString("'webinar-public:ip:'", $provider);
    }

    public function test_final_rendered_webinar_html_and_obsolete_cache_contract_are_absent(): void
    {
        $controller = file_get_contents(base_path(
            'app/Modules/Webinars/Controllers/Public/WebinarRegistrationController.php',
        ));
        $cacheKeys = file_get_contents(base_path(
            'app/Support/Caching/CacheKey.php',
        ));
        $environmentExample = file_get_contents(base_path('.env.example'));
        $cacheConfiguration = require base_path('config/cache-keys.php');
        $webinarConfiguration = require base_path('config/webinars.php');

        $this->assertIsString($controller);
        $this->assertIsString($cacheKeys);
        $this->assertIsString($environmentExample);

        $this->assertStringNotContainsString('Cache::remember(', $controller);
        $this->assertStringNotContainsString('webinarLandingPage(', $controller);
        $this->assertStringNotContainsString('publicPageConfig(', $cacheKeys);

        $this->assertArrayNotHasKey('enabled', $cacheConfiguration);
        $this->assertArrayNotHasKey(
            'public_page_config_seconds',
            $cacheConfiguration['ttl'],
        );
        $this->assertArrayNotHasKey(
            'webinar_landing_page_seconds',
            $cacheConfiguration['ttl'],
        );
        $this->assertArrayNotHasKey('cache', $webinarConfiguration);

        foreach ([
            'PUBLIC_RESPONSE_CACHE_ENABLED',
            'CACHE_PUBLIC_PAGE_CONFIG_SECONDS',
            'CACHE_WEBINAR_LANDING_PAGE_SECONDS',
        ] as $obsoleteVariable) {
            $this->assertStringNotContainsString(
                $obsoleteVariable,
                $environmentExample,
            );
        }
    }

    public function test_get_head_and_repeated_get_only_show_cancellation_confirmation(): void
    {
        $registration = WebinarRegistration::factory()->create([
            'status' => 'registered',
            'cancelled_at' => null,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $url = $this->signedCancellationUrl(
            routeName: 'webinar.registration.cancellation.show',
            registration: $registration,
        );

        foreach (['get', 'head', 'get'] as $method) {
            $response = $this->{$method}($url);

            $response->assertOk();

            if ($method === 'get') {
                $response
                    ->assertViewIs('webinar.registration-cancellation-confirm')
                    ->assertViewHas('registration', function (
                        WebinarRegistration $viewRegistration,
                    ) use ($registration): bool {
                        return $viewRegistration->is($registration);
                    })
                    ->assertViewHas('cancelUrl');
            }
        }

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame('registered', $registration->status);
        $this->assertNull($registration->cancelled_at);
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );
    }

    public function test_signed_post_cancels_once_and_repeated_post_is_idempotent(): void
    {
        $webinar = Webinar::factory()->create([
            'external_id' => null,
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'registered',
                'cancelled_at' => null,
            ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);

        $url = $this->signedCancellationUrl(
            routeName: 'webinar.registration.cancellation.store',
            registration: $registration,
        );

        $this->post($url)
            ->assertOk()
            ->assertViewIs('webinar.registration-cancelled');

        $registration->refresh();
        $scheduledMessage->refresh();

        $firstCancelledAt = $registration->cancelled_at?->toISOString();
        $firstCancellationMeta = data_get($registration->meta, 'cancellation');

        $this->assertSame('cancelled', $registration->status);
        $this->assertNotNull($firstCancelledAt);
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $scheduledMessage->status,
        );

        $this->post($url)
            ->assertOk()
            ->assertViewIs('webinar.registration-cancelled');

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame($firstCancelledAt, $registration->cancelled_at?->toISOString());
        $this->assertSame($firstCancellationMeta, data_get($registration->meta, 'cancellation'));
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $scheduledMessage->status,
        );
    }

    public function test_join_redirect_get_does_not_record_a_trusted_click_or_skip_live_reminders(): void
    {
        $webinar = Webinar::factory()->create([
            'join_url' => 'https://example.test/webinar/join',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'registered',
                'meta' => [
                    'existing' => 'preserved',
                ],
            ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'skip_when_join_clicked' => true,
            ],
        ]);

        $url = route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ]);

        foreach (['get', 'head', 'get'] as $method) {
            $this->{$method}($url)
                ->assertRedirect('https://example.test/webinar/join');
        }

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame([
            'existing' => 'preserved',
        ], $registration->meta);
        $this->assertNull(data_get($registration->meta, 'join_clicked_at'));
        $this->assertNull(data_get($registration->meta, 'join_click_count'));
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );
    }

    public function test_expired_cancellation_signatures_render_the_webinar_error_page_without_mutation(): void
    {
        $registration = WebinarRegistration::factory()->create([
            'status' => 'registered',
            'cancelled_at' => null,
        ]);

        foreach ([
            ['get', 'webinar.registration.cancellation.show'],
            ['post', 'webinar.registration.cancellation.store'],
        ] as [$method, $routeName]) {
            $path = URL::temporarySignedRoute(
                name: $routeName,
                expiration: now()->subMinute(),
                parameters: [
                    'registration' => $registration,
                ],
                absolute: false,
            );

            $url = rtrim(route('webinar.index'), '/').$path;

            $this->{$method}($url)
                ->assertForbidden()
                ->assertViewIs('webinar.signed-link-invalid')
                ->assertSee('This webinar link is no longer valid.')
                ->assertDontSee('Unsubscribe Link Expired');
        }

        $registration->refresh();

        $this->assertSame('registered', $registration->status);
        $this->assertNull($registration->cancelled_at);
    }

    private function signedCancellationUrl(
        string $routeName,
        WebinarRegistration $registration,
    ): string {
        $path = URL::temporarySignedRoute(
            name: $routeName,
            expiration: now()->addMinutes(30),
            parameters: [
                'registration' => $registration,
            ],
            absolute: false,
        );

        return rtrim(route('webinar.index'), '/').$path;
    }
}
