<?php

namespace Tests\Feature\CRM;

use App\Actions\Caching\FlushWebinarCachesAction;
use App\Integrations\Webinars\Zoom\ZoomWebinarService;
use App\Jobs\Webinars\NotifyWebinarWaitlistJob;
use App\Models\Contact;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class WebinarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_creates_webinars_from_provider_payload(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([
                [
                    'external_id' => 'zoom-1001',
                    'title' => 'Home Buyer Game Plan',
                    'join_url' => 'https://example.com/join-1001',
                    'starts_at' => Carbon::parse('2026-05-01 19:00:00', 'America/Chicago')->utc(),
                    'ends_at' => Carbon::parse('2026-05-01 20:00:00', 'America/Chicago')->utc(),
                    'timezone' => 'America/Chicago',
                    'description' => 'First webinar',
                    'meta' => [
                        'zoom_uuid' => 'uuid-1001',
                    ],
                ],
                [
                    'external_id' => 'zoom-1002',
                    'title' => 'Home Buyer Game Plan',
                    'join_url' => 'https://example.com/join-1002',
                    'starts_at' => Carbon::parse('2026-05-08 19:00:00', 'America/Chicago')->utc(),
                    'ends_at' => Carbon::parse('2026-05-08 20:00:00', 'America/Chicago')->utc(),
                    'timezone' => 'America/Chicago',
                    'description' => 'Second webinar',
                    'meta' => [
                        'zoom_uuid' => 'uuid-1002',
                    ],
                ],
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Sync complete: 2 created, 0 updated, 0 deleted, 0 missing preserved.');

        $this->assertDatabaseCount('webinars', 2);

        $this->assertDatabaseHas('webinars', [
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1001',
            'title' => 'Home Buyer Game Plan',
            'timezone' => 'America/Chicago',
            'join_url' => 'https://example.com/join-1001',
            'registration_url' => null,
            'description' => 'First webinar',
        ]);

        $this->assertDatabaseHas('webinars', [
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1002',
            'title' => 'Home Buyer Game Plan',
            'timezone' => 'America/Chicago',
            'join_url' => 'https://example.com/join-1002',
            'registration_url' => null,
            'description' => 'Second webinar',
        ]);

        $this->assertSame(
            ['zoom_uuid' => 'uuid-1001'],
            Webinar::query()->where('external_id', 'zoom-1001')->firstOrFail()->meta
        );

        $this->assertSame(
            ['zoom_uuid' => 'uuid-1002'],
            Webinar::query()->where('external_id', 'zoom-1002')->firstOrFail()->meta
        );

        Carbon::setTestNow();
    }

    public function test_sync_updates_zoom_owned_fields_and_preserves_app_owned_registration_url(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $webinar = Webinar::query()->create([
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1001',
            'title' => 'Old Title',
            'slug' => 'old-title',
            'join_url' => 'https://example.com/old-join',
            'registration_url' => 'https://example.com/old-register',
            'starts_at' => Carbon::parse('2026-05-01 18:00:00', 'America/Chicago')->utc(),
            'ends_at' => Carbon::parse('2026-05-01 19:00:00', 'America/Chicago')->utc(),
            'timezone' => 'America/Chicago',
            'description' => 'Old description',
            'meta' => [
                'zoom_uuid' => 'old-uuid',
            ],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([
                [
                    'external_id' => 'zoom-1001',
                    'title' => 'Home Buyer Game Plan',
                    'join_url' => 'https://example.com/new-join',
                    'registration_url' => 'https://example.com/new-register',
                    'starts_at' => Carbon::parse('2026-05-01 19:00:00', 'America/Chicago')->utc(),
                    'ends_at' => Carbon::parse('2026-05-01 20:00:00', 'America/Chicago')->utc(),
                    'timezone' => 'America/Chicago',
                    'description' => 'Updated description',
                    'meta' => [
                        'zoom_uuid' => 'new-uuid',
                    ],
                ],
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 1 updated, 0 deleted, 0 missing preserved.');

        $webinar->refresh();

        $this->assertSame('Home Buyer Game Plan', $webinar->title);
        $this->assertSame('https://example.com/new-join', $webinar->join_url);
        $this->assertSame('https://example.com/old-register', $webinar->registration_url);
        $this->assertSame('America/Chicago', $webinar->timezone);
        $this->assertSame('Updated description', $webinar->description);
        $this->assertSame(['zoom_uuid' => 'new-uuid'], $webinar->meta);

        Carbon::setTestNow();
    }

    public function test_sync_deletes_missing_webinar_with_no_registrations(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $missingWebinar = Webinar::query()->create([
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-missing-1',
            'title' => 'Missing Webinar',
            'slug' => 'missing-webinar',
            'join_url' => 'https://example.com/join-missing',
            'registration_url' => 'https://example.com/register-missing',
            'starts_at' => Carbon::parse('2026-05-15 19:00:00', 'America/Chicago')->utc(),
            'ends_at' => Carbon::parse('2026-05-15 20:00:00', 'America/Chicago')->utc(),
            'timezone' => 'America/Chicago',
            'description' => 'Will be deleted',
            'meta' => [
                'zoom_uuid' => 'uuid-missing-1',
            ],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 0 updated, 1 deleted, 0 missing preserved.');

        $this->assertDatabaseMissing('webinars', [
            'id' => $missingWebinar->id,
        ]);

        $this->assertSame([], session('sync_missing', []));

        Carbon::setTestNow();
    }

    public function test_sync_preserves_missing_webinar_with_registrations(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $missingWebinar = Webinar::query()->create([
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-missing-active',
            'title' => 'Missing Webinar',
            'slug' => 'missing-webinar',
            'join_url' => 'https://example.com/join-active',
            'registration_url' => 'https://example.com/register-active',
            'starts_at' => Carbon::parse('2026-05-15 19:00:00', 'America/Chicago')->utc(),
            'ends_at' => Carbon::parse('2026-05-15 20:00:00', 'America/Chicago')->utc(),
            'timezone' => 'America/Chicago',
            'description' => 'Should be preserved',
            'meta' => [
                'zoom_uuid' => 'uuid-missing-active',
            ],
        ]);

        $contact = Contact::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Registrant',
            'email' => 'registered@example.com',
            'status' => 'new',
            'source' => 'webinar_subdomain',
        ]);

        $missingWebinar->registrations()->create([
            'contact_id' => $contact->id,
            'webinar_slug' => $missingWebinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'email' => 'registered@example.com',
            'registered_at' => now(),
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 0 updated, 0 deleted, 1 missing preserved.');

        $this->assertDatabaseHas('webinars', [
            'id' => $missingWebinar->id,
        ]);

        $missing = session('sync_missing', []);

        $this->assertCount(1, $missing);
        $this->assertSame('Missing Webinar', $missing[0]['title']);

        Carbon::setTestNow();
    }

    public function test_sync_dispatches_waitlist_notifications_when_series_becomes_scheduled(): void
    {
        Queue::fake();

        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan',
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);

        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->andReturn(collect([
                [
                    'external_id' => 'zoom-1001',
                    'title' => 'Home Buyer Game Plan',
                    'join_url' => 'https://example.com/join',
                    'registration_url' => 'https://example.com/register',
                    'starts_at' => now()->addDays(7),
                    'ends_at' => now()->addDays(7)->addHour(),
                    'timezone' => 'America/Chicago',
                    'description' => 'Upcoming webinar',
                    'meta' => [],
                ],
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        Queue::assertPushed(NotifyWebinarWaitlistJob::class);
    }

    public function test_sync_does_not_dispatch_waitlist_notifications_when_series_was_already_scheduled(): void
    {
        Queue::fake();

        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan',
        ]);

        Webinar::query()->create([
            'webinar_series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-existing',
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan-existing',
            'join_url' => 'https://example.com/existing-join',
            'registration_url' => 'https://example.com/existing-register',
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(3)->addHour(),
            'timezone' => 'America/Chicago',
            'description' => 'Existing webinar',
            'meta' => [],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);

        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([
                [
                    'external_id' => 'zoom-existing',
                    'title' => 'Home Buyer Game Plan',
                    'join_url' => 'https://example.com/existing-join',
                    'registration_url' => 'https://example.com/existing-register',
                    'starts_at' => now()->addDays(3),
                    'ends_at' => now()->addDays(3)->addHour(),
                    'timezone' => 'America/Chicago',
                    'description' => 'Existing webinar',
                    'meta' => [],
                ],
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        Queue::assertNotPushed(NotifyWebinarWaitlistJob::class);
    }

    public function test_sync_flushes_webinar_show_page_cache(): void
    {
        Cache::flush();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'home-buyer-game-plan',
        ]);

        Cache::put(
            CacheKey::webinarLandingPage($series->slug),
            'stale cached page',
            now()->addMinutes(10)
        );

        $this->assertTrue(Cache::has(CacheKey::webinarLandingPage($series->slug)));

        app(FlushWebinarCachesAction::class)
            ->handle(seriesSlug: $series->slug);

        $this->assertFalse(Cache::has(CacheKey::webinarLandingPage($series->slug)));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Mockery::close();

        parent::tearDown();
    }
}
