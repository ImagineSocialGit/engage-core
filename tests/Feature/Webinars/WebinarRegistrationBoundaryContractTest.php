<?php

namespace Tests\Feature\Webinars;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Support\WebinarJoinLinkGenerator;
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

    public function test_join_get_head_and_repeated_get_only_show_confirmation_without_mutation(): void
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
            $response = $this->{$method}($url)->assertOk();

            if ($method === 'get') {
                $response
                    ->assertViewIs('webinar.join-confirm')
                    ->assertViewHas('registration', function (
                        WebinarRegistration $viewRegistration,
                    ) use ($registration): bool {
                        return $viewRegistration->is($registration);
                    })
                    ->assertViewHas('continueUrl')
                    ->assertSee('method="POST"', false)
                    ->assertDontSee('https://example.test/webinar/join');
            }
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

    public function test_signed_join_post_records_trusted_interaction_and_skips_eligible_reminders(): void
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

        $skippableMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'skip_when_join_clicked' => true,
            ],
        ]);

        $unrelatedMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'skip_when_join_clicked' => false,
            ],
        ]);

        $url = $this->signedJoinContinuationUrl($registration);

        $this->post($url)
            ->assertRedirect('https://example.test/webinar/join');

        $registration->refresh();
        $skippableMessage->refresh();
        $unrelatedMessage->refresh();

        $this->assertSame('preserved', data_get($registration->meta, 'existing'));
        $this->assertNotNull(data_get($registration->meta, 'join_clicked_at'));
        $this->assertSame(1, data_get($registration->meta, 'join_click_count'));
        $this->assertSame(
            'public_signed_post',
            data_get($registration->meta, 'join_interaction.source'),
        );
        $this->assertSame(
            data_get($registration->meta, 'join_interaction.first_confirmed_at'),
            data_get($registration->meta, 'join_interaction.last_confirmed_at'),
        );
        $this->assertSame(
            1,
            data_get($registration->meta, 'join_interaction.confirmed_count'),
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $skippableMessage->status,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $unrelatedMessage->status,
        );
    }

    public function test_repeated_signed_join_posts_preserve_first_confirmation_and_remain_controlled(): void
    {
        $webinar = Webinar::factory()->create([
            'join_url' => 'https://example.test/webinar/join',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'registered',
            ]);

        $scheduledMessage = ScheduledMessage::factory()->create([
            'context_type' => $registration->getMorphClass(),
            'context_id' => $registration->getKey(),
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => [
                'skip_when_join_clicked' => true,
            ],
        ]);

        $url = $this->signedJoinContinuationUrl($registration);

        $this->post($url)
            ->assertRedirect('https://example.test/webinar/join');

        $registration->refresh();
        $firstConfirmedAt = data_get(
            $registration->meta,
            'join_interaction.first_confirmed_at',
        );

        $this->post($url)
            ->assertRedirect('https://example.test/webinar/join');

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame(
            $firstConfirmedAt,
            data_get($registration->meta, 'join_interaction.first_confirmed_at'),
        );
        $this->assertSame(2, data_get($registration->meta, 'join_click_count'));
        $this->assertSame(
            2,
            data_get($registration->meta, 'join_interaction.confirmed_count'),
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $scheduledMessage->status,
        );
    }

    public function test_fragment_proof_auto_continuation_records_only_after_valid_post(): void
    {
        $webinar = Webinar::factory()->create([
            'join_url' => 'https://example.test/webinar/join',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
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

        $joinUrl = app(WebinarJoinLinkGenerator::class)
            ->forRegistration($registration);

        [$generatedRequestUrl, $fragment] = explode('#', $joinUrl, 2);

        $this->assertStringStartsWith('join_proof=', $fragment);

        $expectedPath = '/j/'.$registration->join_token;

        $this->assertStringEndsWith(
            $expectedPath,
            $generatedRequestUrl,
        );

        $requestUrl = route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ]);

        $this->get($requestUrl)
            ->assertOk()
            ->assertViewIs('webinar.join-confirm')
            ->assertViewHas('continueUrl')
            ->assertSee('webinar-browser-proof', false)
            ->assertSee('window.location.hash', false)
            ->assertSee('form.submit()', false);

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame([
            'existing' => 'preserved',
        ], $registration->meta);
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );

        $proof = rawurldecode(substr(
            $fragment,
            strlen('join_proof='),
        ));

        $this->post(
            $this->signedJoinContinuationUrl($registration),
            [
                'browser_proof' => $proof,
            ],
        )->assertRedirect('https://example.test/webinar/join');

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame('preserved', data_get($registration->meta, 'existing'));
        $this->assertNotNull(data_get($registration->meta, 'join_clicked_at'));
        $this->assertSame(1, data_get($registration->meta, 'join_click_count'));
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $scheduledMessage->status,
        );
    }

    public function test_invalid_fragment_proof_returns_to_manual_confirmation_without_mutation(): void
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

        $this->post(
            $this->signedJoinContinuationUrl($registration),
            [
                'browser_proof' => 'invalid-browser-proof',
            ],
        )
            ->assertRedirect(route('webinar.join.redirect', [
                'token' => $registration->join_token,
            ]))
            ->assertSessionHas('join_auto_continue_failed');

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame([
            'existing' => 'preserved',
        ], $registration->meta);
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );
    }

    public function test_invalid_or_expired_join_post_signature_does_not_mutate_registration(): void
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

        $expiredPath = URL::temporarySignedRoute(
            name: 'webinar.join.continue',
            expiration: now()->subMinute(),
            parameters: [
                'token' => $registration->join_token,
            ],
            absolute: false,
        );

        $expiredUrl = rtrim(route('webinar.index'), '/').$expiredPath;

        $this->post($expiredUrl)
            ->assertForbidden()
            ->assertViewIs('webinar.signed-link-invalid');

        $registration->refresh();
        $scheduledMessage->refresh();

        $this->assertSame([
            'existing' => 'preserved',
        ], $registration->meta);
        $this->assertSame(
            ScheduledMessage::STATUS_PENDING,
            $scheduledMessage->status,
        );
    }

    public function test_cancelled_registration_cannot_render_or_continue_join_flow(): void
    {
        $webinar = Webinar::factory()->create([
            'join_url' => 'https://example.test/webinar/join',
        ]);

        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'meta' => [
                    'existing' => 'preserved',
                ],
            ]);

        $this->get(route('webinar.join.redirect', [
            'token' => $registration->join_token,
        ]))->assertNotFound();

        $this->post($this->signedJoinContinuationUrl($registration))
            ->assertNotFound();

        $registration->refresh();

        $this->assertSame([
            'existing' => 'preserved',
        ], $registration->meta);
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
                ->assertDontSee('https://example.test/webinar/join');
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

    private function signedJoinContinuationUrl(
        WebinarRegistration $registration,
    ): string {
        $path = URL::temporarySignedRoute(
            name: 'webinar.join.continue',
            expiration: now()->addMinutes(30),
            parameters: [
                'token' => $registration->join_token,
            ],
            absolute: false,
        );

        return rtrim(route('webinar.index'), '/').$path;
    }
}