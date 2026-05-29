<?php

namespace Tests\Feature\Webinars;

use App\Actions\Caching\FlushWebinarCachesAction;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarRegistrationControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
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
            'transactional_sms_consent' => true,
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
}