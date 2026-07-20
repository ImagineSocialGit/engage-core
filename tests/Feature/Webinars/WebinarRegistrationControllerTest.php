<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebinarRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->configureWebinarRegistrationChannelAvailability();
        $this->configureRegistrationConsentContract([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ]);
    }

    public function test_registration_queue_configuration_matches_the_runtime_job_key(): void
    {
        $this->assertIsString(config('webinars.queues.registration'));
        $this->assertNull(config('webinars.queues.registrations'));
    }

    public function test_show_displays_notify_me_page_when_no_upcoming_webinar_exists(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'first-time-homebuyer',
            'title' => 'First Time Homebuyer',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->subDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();

        $response->assertSee($series->title);
        $response->assertSee(route('webinar.waitlist.store', $series->slug), false);
    }

    public function test_show_displays_register_page_when_upcoming_webinar_exists(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'va-loans',
            'title' => 'VA Loans',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();

        $response->assertSee($series->title);
        $response->assertSee(
            $this->registrationPath($series, $webinar),
        );
    }

    public function test_register_page_renders_the_current_session_csrf_token(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'session-specific-register',
            'title' => 'Session Specific Register',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $firstResponse = $this
            ->withSession(['_token' => 'register-session-token-one'])
            ->get(route('webinar.show', $series->slug));

        $firstResponse
            ->assertOk()
            ->assertSee('value="register-session-token-one"', false);

        $secondResponse = $this
            ->withSession(['_token' => 'register-session-token-two'])
            ->get(route('webinar.show', $series->slug));

        $secondResponse
            ->assertOk()
            ->assertSee('value="register-session-token-two"', false)
            ->assertDontSee('value="register-session-token-one"', false);
    }

    public function test_notify_me_page_renders_the_current_session_csrf_token(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'session-specific-waitlist',
            'title' => 'Session Specific Waitlist',
        ]);

        $firstResponse = $this
            ->withSession(['_token' => 'waitlist-session-token-one'])
            ->get(route('webinar.show', $series->slug));

        $firstResponse
            ->assertOk()
            ->assertSee('value="waitlist-session-token-one"', false);

        $secondResponse = $this
            ->withSession(['_token' => 'waitlist-session-token-two'])
            ->get(route('webinar.show', $series->slug));

        $secondResponse
            ->assertOk()
            ->assertSee('value="waitlist-session-token-two"', false)
            ->assertDontSee('value="waitlist-session-token-one"', false);
    }

    public function test_show_intersects_configured_consent_fields_with_channel_availability(): void
    {
        $this->configureRegistrationConsentContract([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => true, 'sms' => true],
        ]);

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'hidden-sms',
            'title' => 'Hidden SMS',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee('name="transactional_email_consent"', false);
        $response->assertSee('name="marketing_email_consent"', false);
        $response->assertDontSee('name="transactional_sms_consent"', false);
        $response->assertDontSee('name="marketing_sms_consent"', false);
    }

    public function test_show_renders_only_fields_enabled_by_the_registration_consent_contract(): void
    {
        $this->enableWebinarRegistrationSms();
        $this->configureRegistrationConsentContract([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => false, 'sms' => false],
        ]);

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'transactional-only',
            'title' => 'Transactional Only',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee('name="transactional_email_consent"', false);
        $response->assertSee('name="transactional_sms_consent"', false);
        $response->assertDontSee('name="marketing_email_consent"', false);
        $response->assertDontSee('name="marketing_sms_consent"', false);
    }

    public function test_index_displays_only_active_series(): void
    {
        $activeSeries = WebinarSeries::factory()->create([
            'status' => 'active',
            'title' => 'Homebuyer Basics',
        ]);

        $inactiveSeries = WebinarSeries::factory()->create([
            'status' => 'inactive',
            'title' => 'Old Webinar',
        ]);

        $response = $this->get(route('webinar.index'));

        $response->assertOk();

        $response->assertViewIs('webinar.index');

        $response->assertViewHas('series', function ($series) use ($activeSeries, $inactiveSeries) {
            return $series->contains($activeSeries)
                && ! $series->contains($inactiveSeries);
        });

        $response->assertSee($activeSeries->title);
        $response->assertDontSee($inactiveSeries->title);
    }

    public function test_store_redirects_back_to_show_page_when_no_upcoming_webinar_exists(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'refinance-workshop',
        ]);

        $response = $this->post($this->registrationUrl($series, 999999), [
            'company_website' => '',
            'registration_form_ready' => 'ready',
            'registration_form_interacted' => 'human',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '6155551212',
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_email_consent' => false,
            'marketing_sms_consent' => false,
        ]);

        $response->assertStatus(302);

        $response->assertRedirect(route('webinar.show', [
            'seriesSlug' => $series->slug,
        ]));
    }

    public function test_show_reflects_current_data_after_supported_caches_are_flushed(): void
    {
        Cache::flush();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'first-time-homebuyer',
            'title' => 'First Time Homebuyer',
        ]);

        $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->assertSee('notify');

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'title' => 'New Webinar',
            'slug' => 'new-webinar',
            'join_url' => 'https://example.com/join',
            'starts_at' => now()->addWeek(),
            'ends_at' => now()->addWeek()->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        app(FlushWebinarCachesAction::class)
            ->handle(seriesSlug: $series->slug);

        $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->assertSee($this->registrationPath($series, $webinar))
            ->assertDontSee(route('webinar.waitlist.store', $series->slug), false);
    }

    public function test_store_rejects_a_selected_consent_field_disabled_by_the_registration_contract(): void
    {
        $this->enableWebinarRegistrationSms();
        $this->configureRegistrationConsentContract([
            'transactional' => ['email' => true, 'sms' => true],
            'marketing' => ['email' => false, 'sms' => false],
        ]);

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'disabled-marketing-consent',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '6155551212',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => true,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('marketing_email_consent');
    }

    public function test_store_rejects_sms_consent_when_sms_is_not_available_for_registration(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => '6155551212',
                'transactional_email_consent' => true,
                'transactional_sms_consent' => true,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('transactional_sms_consent');
    }

    public function test_store_requires_phone_when_transactional_sms_consent_is_checked(): void
    {
        $this->enableWebinarRegistrationSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => true,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('phone');
    }

    public function test_store_requires_phone_when_marketing_sms_consent_is_checked(): void
    {
        $this->enableWebinarRegistrationSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'jeff@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => true,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('phone');
    }

    public function test_store_treats_an_existing_registration_as_idempotent_success(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $contact = Contact::factory()->create([
            'email' => 'jeff@example.com',
        ]);

        WebinarRegistration::factory()->create([
            'contact_id' => $contact->id,
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), [
                'company_website' => '',
                'registration_form_ready' => 'ready',
                'registration_form_interacted' => 'human',
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'JEFF@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $this->assertRegistrationThankYouRedirect($response, $series);
        $response->assertSessionDoesntHaveErrors('email');
        $this->assertDatabaseCount('webinar_registrations', 1);
    }

    public function test_store_rejects_an_invalid_phone_number_as_a_validation_error(): void
    {
        $this->enableWebinarRegistrationSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'invalid-phone',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), $this->registrationPayload([
                'phone' => 'not-a-phone-number',
                'transactional_sms_consent' => true,
            ]));

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    public function test_store_rejects_a_filled_honeypot_without_persisting_registration_data(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'honeypot-test',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), $this->registrationPayload([
                'company_website' => 'https://spam.example',
            ]));

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('registration_form');
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    public function test_store_rejects_submission_without_javascript_readiness_and_interaction_proofs(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'javascript-proof-test',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $payload = $this->registrationPayload();
        unset(
            $payload['registration_form_ready'],
            $payload['registration_form_interacted'],
        );

        $response = $this->from(route('webinar.show', $series->slug))
            ->post($this->registrationUrl($series, $webinar), $payload);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('registration_form');
        $this->assertDatabaseCount('contacts', 0);
        $this->assertDatabaseCount('webinar_registrations', 0);
    }

    public function test_registration_rate_limiter_blocks_repeated_attempts_from_one_ip(): void
    {
        Config::set('webinars.registration.rate_limits', [
            'per_ip_per_minute' => 1,
            'per_ip_per_hour' => 10,
            'per_email_per_hour' => 10,
            'per_phone_per_hour' => 10,
        ]);

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'rate-limit-test',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
            'external_id' => null,
        ]);

        $firstResponse = $this->post(
            $this->registrationUrl($series, $webinar),
            $this->registrationPayload(['email' => 'first@example.com']),
        );

        $this->assertRegistrationThankYouRedirect($firstResponse, $series);

        $response = $this->from(route('webinar.show', $series->slug))->post(
            $this->registrationUrl($series, $webinar),
            $this->registrationPayload(['email' => 'second@example.com']),
        );

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors('email');
        $this->assertDatabaseCount('webinar_registrations', 1);
    }

    public function test_show_resolves_public_layout_image_client_key_from_client_config(): void
    {
        Config::set('app.client_key', null);
        Config::set('client.key', 'example-client');
        Config::set('filesystems.disks.spaces.url', 'https://cdn.example.test');

        Config::set('webinars.content.brand.logo', [
            'path' => 'brand/logo',
            'sizes' => [320],
            'placeholder' => 'brand/logo/placeholder.webp',
        ]);

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'client-key-image-test',
            'title' => 'Client Key Image Test',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();
        $response->assertSee(
            'https://cdn.example.test/example-client/images/brand/logo/320.webp',
            false
        );
    }


    public function test_waitlist_registration_page_prefills_registration_form_without_checking_consents(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'prefill-test',
            'title' => 'Prefill Test',
        ]);

        Cache::forget(CacheKey::activeWebinarSeries());
        Cache::forget(CacheKey::nextUpcomingWebinar($series->slug));

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Tess',
            'last_name' => 'Tester',
            'email' => 'tess@example.com',
            'phone' => '+15555550123',
        ]);

        $signup = \App\Modules\Webinars\Models\WebinarWaitlistSignup::query()->create([
            'contact_id' => $contact->id,
            'webinar_series_id' => $series->id,
            'meta' => [],
        ]);

        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $response = app(\App\Modules\Webinars\Controllers\Public\WebinarRegistrationController::class)
            ->showFromWaitlist(
                seriesSlug: $series->slug,
                signup: $signup->id,
                getActiveWebinarSeriesAction: app(\App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction::class),
                getNextUpcomingWebinarAction: app(\App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction::class),
            );

        $this->assertSame(200, $response->getStatusCode());

        $html = $response->getContent();

        $this->assertStringContainsString('value="Tess"', $html);
        $this->assertStringContainsString('value="Tester"', $html);
        $this->assertStringContainsString('value="tess@example.com"', $html);

        $this->assertStringContainsString('value="+15555550123"', $html);
        $this->assertStringContainsString('name="transactional_email_consent"', $html);
        $this->assertStringNotContainsString('name="transactional_email_consent" type="checkbox" value="1" checked', $html);
    }

    private function assertRegistrationThankYouRedirect(
        TestResponse $response,
        WebinarSeries $series,
    ): void {
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $path = parse_url($location, PHP_URL_PATH);
        $expectedHost = parse_url(
            route('webinar.show', ['seriesSlug' => $series->slug]),
            PHP_URL_HOST,
        );
        $actualHost = parse_url($location, PHP_URL_HOST);

        $this->assertIsString($path);
        $this->assertStringContainsString(
            "/{$series->slug}/thank-you/",
            $path,
        );
        $this->assertIsString($expectedHost);
        $this->assertSame($expectedHost, $actualHost);
        $this->assertTrue(
            URL::hasValidSignature(
                Request::create($location, 'GET'),
                absolute: false,
            ),
            'The registration thank-you redirect must contain a valid relative signature.',
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_replace([
            'company_website' => '',
            'registration_form_ready' => 'ready',
            'registration_form_interacted' => 'human',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => null,
            'transactional_email_consent' => true,
            'transactional_sms_consent' => false,
            'marketing_email_consent' => false,
            'marketing_sms_consent' => false,
        ], $overrides);
    }

    private function registrationPath(
        WebinarSeries $series,
        Webinar|int $webinar,
    ): string {
        $webinarId = $webinar instanceof Webinar
            ? $webinar->getKey()
            : $webinar;

        return URL::signedRoute(
            'webinar.registration.store',
            [
                'seriesSlug' => $series->slug,
                'webinar_id' => $webinarId,
            ],
            absolute: false,
        );
    }

    private function registrationUrl(
        WebinarSeries $series,
        Webinar|int $webinar,
    ): string {
        $showUrl = route('webinar.show', $series->slug);
        $scheme = parse_url($showUrl, PHP_URL_SCHEME);
        $host = parse_url($showUrl, PHP_URL_HOST);
        $port = parse_url($showUrl, PHP_URL_PORT);

        $origin = sprintf(
            '%s://%s%s',
            is_string($scheme) ? $scheme : 'http',
            is_string($host) ? $host : 'localhost',
            is_int($port) ? ':'.$port : '',
        );

        return $origin.$this->registrationPath($series, $webinar);
    }

    private function configureWebinarRegistrationChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'webinar_registrations' => true,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [
                'transactional:webinar' => true,
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    /**
     * @param array<string, array<string, bool>> $consents
     */
    private function configureRegistrationConsentContract(array $consents): void
    {
        Config::set('webinars.register.content.registration.consents', $consents);
    }

    private function enableWebinarRegistrationSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_registrations', true);
    }
}