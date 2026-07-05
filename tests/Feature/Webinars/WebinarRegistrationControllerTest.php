<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class WebinarRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->configureWebinarRegistrationChannelAvailability();
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

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();

        $response->assertSee($series->title);
        $response->assertSee(route('webinar.registration.store', $series->slug), false);
    }

    public function test_show_hides_sms_consent_options_when_sms_is_not_available_for_registration(): void
    {
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

    public function test_show_displays_sms_consent_options_when_sms_is_available_for_registration(): void
    {
        $this->enableWebinarRegistrationSms();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'visible-sms',
            'title' => 'Visible SMS',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->get(route('webinar.show', $series->slug));

        $response->assertOk();

        $response->assertSee('name="transactional_sms_consent"', false);
        $response->assertSee('name="marketing_sms_consent"', false);
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

        $response = $this->post(route('webinar.registration.store', $series->slug), [
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

    public function test_show_page_cache_is_flushed_after_sync(): void
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

        Webinar::factory()->create([
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
            ->assertSee(route('webinar.registration.store', $series->slug), false)
            ->assertDontSee(route('webinar.waitlist.store', $series->slug), false);
    }

    public function test_store_rejects_sms_consent_when_sms_is_not_available_for_registration(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.registration.store', $series->slug), [
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

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.registration.store', $series->slug), [
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

        Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->from(route('webinar.show', $series->slug))
            ->post(route('webinar.registration.store', $series->slug), [
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

    public function test_store_fails_validation_when_email_already_registered_for_webinar(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'homebuyer-basics',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->id,
            'starts_at' => now()->addDay(),
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
            ->post(route('webinar.registration.store', $series->slug), [
                'first_name' => 'Jeff',
                'last_name' => 'Yarnall',
                'email' => 'JEFF@example.com',
                'phone' => null,
                'transactional_email_consent' => true,
                'transactional_sms_consent' => false,
                'marketing_email_consent' => false,
                'marketing_sms_consent' => false,
            ]);

        $response->assertRedirect(route('webinar.show', $series->slug));
        $response->assertSessionHasErrors([
            'email' => 'This email has already been used to register for this webinar.',
        ]);
    }

    public function test_show_resolves_public_layout_image_client_key_from_client_config(): void
    {
        Config::set('app.client_key', null);
        Config::set('client.key', 'slam-dunk-crm');
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
            'https://cdn.example.test/slam-dunk-crm/images/brand/logo/320.webp',
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

    private function enableWebinarRegistrationSms(): void
    {
        Config::set('messaging.channel_availability.sms.surfaces.webinar_registrations', true);
    }
}
