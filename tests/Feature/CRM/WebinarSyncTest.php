<?php

namespace Tests\Feature\CRM;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarSeries;
use App\Services\Zoom\ZoomWebinarService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
                    'registration_url' => 'https://example.com/register-1001',
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
                    'registration_url' => 'https://example.com/register-1002',
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
            'series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinars.index'));
        $response->assertSessionHas('success', 'Sync complete: 2 created, 0 updated, 0 deleted, 0 missing preserved.');

        $this->assertDatabaseCount('webinars', 2);

        $this->assertDatabaseHas('webinars', [
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1001',
            'title' => 'Home Buyer Game Plan',
            'status' => 'scheduled',
            'timezone' => 'America/Chicago',
            'join_url' => 'https://example.com/join-1001',
            'registration_url' => 'https://example.com/register-1001',
            'description' => 'First webinar',
        ]);

        $this->assertDatabaseHas('webinars', [
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1002',
            'title' => 'Home Buyer Game Plan',
            'status' => 'scheduled',
            'timezone' => 'America/Chicago',
            'join_url' => 'https://example.com/join-1002',
            'registration_url' => 'https://example.com/register-1002',
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

    public function test_sync_updates_zoom_owned_fields_but_preserves_status(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $webinar = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1001',
            'title' => 'Old Title',
            'slug' => 'old-title',
            'status' => 'active',
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
            'series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinars.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 1 updated, 0 deleted, 0 missing preserved.');

        $webinar->refresh();

        $this->assertSame('active', $webinar->status);
        $this->assertSame('Home Buyer Game Plan', $webinar->title);
        $this->assertSame('https://example.com/new-join', $webinar->join_url);
        $this->assertSame('https://example.com/new-register', $webinar->registration_url);
        $this->assertSame('America/Chicago', $webinar->timezone);
        $this->assertSame('Updated description', $webinar->description);
        $this->assertSame(['zoom_uuid' => 'new-uuid'], $webinar->meta);

        Carbon::setTestNow();
    }

    public function test_sync_deletes_missing_scheduled_webinar_with_no_registrations(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $missingWebinar = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-missing-1',
            'title' => 'Missing Webinar',
            'slug' => 'missing-webinar',
            'status' => 'scheduled',
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
            'series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinars.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 0 updated, 1 deleted, 0 missing preserved.');

        $this->assertDatabaseMissing('webinars', [
            'id' => $missingWebinar->id,
        ]);

        $this->assertSame([], session('sync_missing', []));

        Carbon::setTestNow();
    }

    public function test_sync_preserves_missing_active_webinar(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $missingWebinar = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-missing-active',
            'title' => 'Active Webinar',
            'slug' => 'active-webinar',
            'status' => 'active',
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

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(collect([]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinars.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 0 updated, 0 deleted, 1 missing preserved.');

        $this->assertDatabaseHas('webinars', [
            'id' => $missingWebinar->id,
            'status' => 'active',
        ]);

        $missing = session('sync_missing', []);

        $this->assertCount(1, $missing);
        $this->assertSame('Active Webinar', $missing[0]['title']);
        $this->assertSame('active', $missing[0]['status']);
        $this->assertFalse($missing[0]['has_registrations']);

        Carbon::setTestNow();
    }

    public function test_fix_active_corrects_conflicted_active_webinar(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $earliest = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-1',
            'title' => 'Earlier Webinar',
            'slug' => 'earlier-webinar',
            'status' => 'scheduled',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        $wrongActive = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-2',
            'title' => 'Later Webinar',
            'slug' => 'later-webinar',
            'status' => 'active',
            'starts_at' => now()->addDays(14),
            'ends_at' => now()->addDays(14)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->andReturn(collect([
                [
                    'external_id' => 'zoom-1',
                    'title' => 'Earlier Webinar',
                    'join_url' => null,
                    'registration_url' => null,
                    'starts_at' => $earliest->starts_at,
                    'ends_at' => $earliest->ends_at,
                    'timezone' => 'America/Chicago',
                    'description' => null,
                    'meta' => [],
                ],
                [
                    'external_id' => 'zoom-2',
                    'title' => 'Later Webinar',
                    'join_url' => null,
                    'registration_url' => null,
                    'starts_at' => $wrongActive->starts_at,
                    'ends_at' => $wrongActive->ends_at,
                    'timezone' => 'America/Chicago',
                    'description' => null,
                    'meta' => [],
                ],
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $sync = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'series_id' => $series->id,
        ]);

        $sync->assertSessionHas('sync_conflicts');

        $fix = $this->actingAs($user)->post(
            route('crm.webinar-series.fix-active', $series)
        );

        $fix->assertRedirect(route('crm.webinars.index'));
        $fix->assertSessionHas('success', 'Active webinar corrected.');

        $earliest->refresh();
        $wrongActive->refresh();

        $this->assertSame('active', $earliest->status);
        $this->assertSame('scheduled', $wrongActive->status);

        Carbon::setTestNow();
    }

    public function test_scheduler_advances_series_statuses_correctly(): void
    {
        $this->freezeTime();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $currentActive = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-active',
            'title' => 'Current Active Webinar',
            'slug' => 'current-active-webinar',
            'status' => 'active',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subMinutes(5),
            'timezone' => 'America/Chicago',
        ]);

        $nextScheduled = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-next',
            'title' => 'Next Scheduled Webinar',
            'slug' => 'next-scheduled-webinar',
            'status' => 'scheduled',
            'starts_at' => now()->addDays(7),
            'ends_at' => now()->addDays(7)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        $laterScheduled = Webinar::query()->create([
            'series_id' => $series->id,
            'platform' => 'zoom',
            'external_id' => 'zoom-later',
            'title' => 'Later Scheduled Webinar',
            'slug' => 'later-scheduled-webinar',
            'status' => 'scheduled',
            'starts_at' => now()->addDays(14),
            'ends_at' => now()->addDays(14)->addHour(),
            'timezone' => 'America/Chicago',
        ]);

        $this->artisan('webinars:advance-series')
            ->assertSuccessful();

        $currentActive->refresh();
        $nextScheduled->refresh();
        $laterScheduled->refresh();

        $this->assertSame('completed', $currentActive->status);
        $this->assertSame('active', $nextScheduled->status);
        $this->assertSame('scheduled', $laterScheduled->status);

        Carbon::setTestNow();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Mockery::close();

        parent::tearDown();
    }
}