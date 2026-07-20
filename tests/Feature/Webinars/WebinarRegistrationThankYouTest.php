<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Data\WebinarRegistrationFinalizationResult;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarRegistrationThankYouTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_pending_finalization_is_presented_as_received_but_not_confirmed(): void
    {
        [$series, $webinar, $registration] = $this->registrationWithFinalization([
            'status' => 'queued',
            'mode' => 'initial_registration',
        ]);

        $this->get($this->thankYouUrl($registration))
            ->assertOk()
            ->assertSee('data-registration-status="processing"', false)
            ->assertSee($webinar->starts_at->timezone($webinar->timezone)->format('F j, Y'));
    }

    public function test_completed_initial_finalization_is_presented_as_confirmed(): void
    {
        [, , $registration] = $this->registrationWithFinalization([
            'status' => 'completed',
            'mode' => 'initial_registration',
            'completed_at' => now()->toISOString(),
        ]);

        $this->get($this->thankYouUrl($registration))
            ->assertOk()
            ->assertSee('data-registration-status="confirmed"', false);
    }

    public function test_ambiguous_or_terminal_initial_finalization_is_presented_as_delayed(): void
    {
        [, , $registration] = $this->registrationWithFinalization([
            'status' => 'reconciliation_required',
            'mode' => 'initial_registration',
            'failure_reason' => 'provider_submission_outcome_unknown',
        ]);

        $this->get($this->thankYouUrl($registration))
            ->assertOk()
            ->assertSee('data-registration-status="delayed"', false)
            ->assertDontSee('provider_submission_outcome_unknown');
    }

    public function test_consent_only_work_does_not_downgrade_an_already_confirmed_registration(): void
    {
        [, , $registration] = $this->registrationWithFinalization([
            'status' => 'pending',
            'mode' => 'consent_acknowledgements',
            'initial_completed_at' => now()->subMinute()->toISOString(),
        ]);

        $this->get($this->thankYouUrl($registration))
            ->assertOk()
            ->assertSee('data-registration-status="confirmed"', false);
    }

    public function test_thank_you_page_uses_the_registered_webinar_not_the_next_upcoming_webinar(): void
    {
        $series = WebinarSeries::factory()->create([
            'slug' => 'exact-webinar-status',
            'status' => 'active',
        ]);

        $nextWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDay()->setTime(9, 0),
            'timezone' => 'America/Chicago',
        ]);

        $registeredWebinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDays(5)->setTime(14, 30),
            'timezone' => 'America/Chicago',
        ]);

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => Contact::factory(),
            'webinar_id' => $registeredWebinar->getKey(),
            'webinar_slug' => $registeredWebinar->slug,
            'meta' => [
                WebinarRegistrationFinalizationResult::META_KEY => [
                    'status' => 'completed',
                    'mode' => 'initial_registration',
                    'completed_at' => now()->toISOString(),
                ],
            ],
        ]);

        $this->get($this->thankYouUrl($registration))
            ->assertOk()
            ->assertSee(
                $registeredWebinar->starts_at
                    ->timezone($registeredWebinar->timezone)
                    ->format('F j, Y'),
            )
            ->assertDontSee(
                $nextWebinar->starts_at
                    ->timezone($nextWebinar->timezone)
                    ->format('F j, Y'),
            );
    }

    public function test_thank_you_route_requires_a_valid_signature_and_matching_series(): void
    {
        [$series, , $registration] = $this->registrationWithFinalization([
            'status' => 'completed',
            'mode' => 'initial_registration',
        ]);

        $validUrl = $this->thankYouUrl($registration);
        $expectedHost = parse_url(
            route('webinar.show', ['seriesSlug' => $series->slug]),
            PHP_URL_HOST,
        );

        $this->assertIsString($expectedHost);
        $this->assertSame(
            $expectedHost,
            parse_url($validUrl, PHP_URL_HOST),
        );
        $this->get($validUrl)->assertOk();

        $this->get(route('webinar.thank-you', [
            'seriesSlug' => $series->slug,
            'registration' => $registration,
        ]))->assertForbidden();

        $otherSeries = WebinarSeries::factory()->create([
            'slug' => 'other-series',
            'status' => 'active',
        ]);

        $mismatchedPath = URL::temporarySignedRoute(
            name: 'webinar.thank-you',
            expiration: now()->addHour(),
            parameters: [
                'seriesSlug' => $otherSeries->slug,
                'registration' => $registration,
            ],
            absolute: false,
        );
        $mismatchedShowUrl = route('webinar.show', [
            'seriesSlug' => $otherSeries->slug,
        ]);
        $scheme = parse_url($mismatchedShowUrl, PHP_URL_SCHEME);
        $host = parse_url($mismatchedShowUrl, PHP_URL_HOST);
        $port = parse_url($mismatchedShowUrl, PHP_URL_PORT);

        $this->assertIsString($scheme);
        $this->assertIsString($host);

        $mismatchedUrl = sprintf(
            '%s://%s%s%s',
            $scheme,
            $host,
            is_int($port) ? ':'.$port : '',
            $mismatchedPath,
        );

        $this->get($mismatchedUrl)->assertNotFound();
    }

    /**
     * @param array<string, mixed> $finalization
     * @return array{WebinarSeries, Webinar, WebinarRegistration}
     */
    private function registrationWithFinalization(array $finalization): array
    {
        $series = WebinarSeries::factory()->create([
            'slug' => fake()->unique()->slug(),
            'status' => 'active',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addDays(2)->setTime(11, 0),
            'timezone' => 'America/Chicago',
        ]);

        $registration = WebinarRegistration::factory()->create([
            'contact_id' => Contact::factory(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'meta' => [
                WebinarRegistrationFinalizationResult::META_KEY => $finalization,
            ],
        ]);

        return [$series, $webinar, $registration];
    }

    private function thankYouUrl(WebinarRegistration $registration): string
    {
        return app(WebinarRegistrationThankYouLinkGenerator::class)
            ->forRegistration($registration);
    }
}