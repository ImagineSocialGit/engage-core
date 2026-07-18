<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Requests\StoreWebinarRegistrationRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class WebinarRegistrationOccurrenceResolutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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
            'provider_enabled' => false,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'webinar_registrations' => false,
            ],
            'purpose_scopes' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_form_and_request_keep_the_same_occurrence_when_the_series_order_changes(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'occurrence-pinning',
        ]);

        $displayed = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->assertSee($this->registrationPath($series, $displayed));

        $newEarlierOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $request = $this->registrationRequest(
            seriesSlug: $series->slug,
            webinarId: $displayed->getKey(),
        );

        $this->assertTrue(
            $request->registerableWebinar()?->is($displayed),
        );
        $this->assertFalse(
            $request->registerableWebinar()?->is($newEarlierOccurrence),
        );
    }

    public function test_request_does_not_move_to_the_next_occurrence_after_the_displayed_occurrence_expires(): void
    {
        Carbon::setTestNow('2026-07-17 12:00:00');

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'expired-occurrence',
        ]);

        $displayed = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->subMinutes(9),
        ]);

        $next = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $this->get(route('webinar.show', $series->slug))
            ->assertOk()
            ->assertSee($this->registrationPath($series, $displayed));

        Carbon::setTestNow(now()->addMinutes(2));

        $request = $this->registrationRequest(
            seriesSlug: $series->slug,
            webinarId: $displayed->getKey(),
        );

        $this->assertNull($request->registerableWebinar());
        $this->assertTrue(
            app(GetNextUpcomingWebinarAction::class)
                ->getForSeries($series)
                ?->is($next),
        );
    }

    public function test_request_rejects_an_occurrence_from_another_series(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'requested-series',
        ]);

        $otherSeries = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'other-series',
        ]);

        $otherOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $otherSeries->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $request = $this->registrationRequest(
            seriesSlug: $series->slug,
            webinarId: $otherOccurrence->getKey(),
        );

        $this->assertNull($request->registerableWebinar());
    }

    public function test_body_occurrence_cannot_override_the_signed_query_occurrence(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'signed-query-source',
        ]);

        $displayed = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $otherOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $request = StoreWebinarRegistrationRequest::create(
            $this->registrationUrl($series, $displayed),
            'POST',
            [
                'webinar_id' => $otherOccurrence->getKey(),
            ],
        );

        $route = $this->app['router']
            ->getRoutes()
            ->match($request);

        $request->setContainer($this->app);
        $request->setRouteResolver(fn (): Route => $route);

        $this->assertTrue(
            $request->registerableWebinar()?->is($displayed),
        );
        $this->assertFalse(
            $request->registerableWebinar()?->is($otherOccurrence),
        );
    }

    public function test_signed_occurrence_cannot_be_changed_by_the_request(): void
    {
        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'signed-occurrence',
        ]);

        $displayed = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHour(),
        ]);

        $otherOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'starts_at' => now()->addHours(2),
        ]);

        $signedUrl = $this->registrationUrl($series, $displayed);
        $tamperedUrl = str_replace(
            'webinar_id='.$displayed->getKey(),
            'webinar_id='.$otherOccurrence->getKey(),
            $signedUrl,
        );

        $this->assertNotSame($signedUrl, $tamperedUrl);

        $this->post($tamperedUrl)->assertForbidden();
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

    private function registrationRequest(
        string $seriesSlug,
        int $webinarId,
    ): StoreWebinarRegistrationRequest {
        $series = WebinarSeries::query()
            ->where('slug', $seriesSlug)
            ->firstOrFail();

        $request = StoreWebinarRegistrationRequest::create(
            $this->registrationUrl($series, $webinarId),
            'POST',
        );

        $route = $this->app['router']
            ->getRoutes()
            ->match($request);

        $this->assertInstanceOf(Route::class, $route);

        $request->setContainer($this->app);
        $request->setRouteResolver(fn (): Route => $route);

        return $request;
    }
}
