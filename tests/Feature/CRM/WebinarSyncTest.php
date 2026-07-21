<?php

namespace Tests\Feature\CRM;

use App\Modules\Webinars\Actions\FlushWebinarCachesAction;
use App\Integrations\Webinars\Zoom\ZoomEventService;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Integrations\Webinars\Zoom\ZoomWebinarService;
use App\Modules\Webinars\Jobs\NotifyWebinarWaitlistJob;
use App\Modules\Core\Models\Contact;
use App\Models\User;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\Caching\CacheKey;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $this->providerWebinar(
                    externalId: 'zoom-1001',
                    joinUrl: 'https://example.com/join-1001',
                    startsAt: Carbon::parse('2026-05-01 19:00:00', 'America/Chicago')->utc(),
                    endsAt: Carbon::parse('2026-05-01 20:00:00', 'America/Chicago')->utc(),
                    description: 'First webinar',
                    meta: ['zoom_uuid' => 'uuid-1001'],
                ),
                $this->providerWebinar(
                    externalId: 'zoom-1002',
                    joinUrl: 'https://example.com/join-1002',
                    startsAt: Carbon::parse('2026-05-08 19:00:00', 'America/Chicago')->utc(),
                    endsAt: Carbon::parse('2026-05-08 20:00:00', 'America/Chicago')->utc(),
                    description: 'Second webinar',
                    meta: ['zoom_uuid' => 'uuid-1002'],
                ),
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
            'provider_event_type' => 'webinar',
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
            'provider_event_type' => 'webinar',
            'external_id' => 'zoom-1002',
            'title' => 'Home Buyer Game Plan',
            'timezone' => 'America/Chicago',
            'join_url' => 'https://example.com/join-1002',
            'registration_url' => null,
            'description' => 'Second webinar',
        ]);

        $this->assertSame(
            [
                'provider' => [
                    'key' => 'zoom',
                    'data' => ['zoom_uuid' => 'uuid-1001'],
                ],
            ],
            Webinar::query()->where('external_id', 'zoom-1001')->firstOrFail()->meta
        );

        $this->assertSame(
            [
                'provider' => [
                    'key' => 'zoom',
                    'data' => ['zoom_uuid' => 'uuid-1002'],
                ],
            ],
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
                'normalized' => [
                    'post_event' => [
                        'attendance_recorded_at' => '2026-05-01T20:30:00Z',
                    ],
                ],
                'automation_events' => [
                    'webinar_ended_recorded_at' => '2026-05-01T20:31:00Z',
                ],
                'provider' => [
                    'key' => 'zoom',
                    'data' => [
                        'zoom_uuid' => 'older-uuid',
                        'obsolete_provider_value' => 'remove-me',
                    ],
                ],
            ],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $this->providerWebinar(
                    externalId: 'zoom-1001',
                    joinUrl: 'https://example.com/new-join',
                    registrationUrl: null,
                    startsAt: Carbon::parse('2026-05-01 19:00:00', 'America/Chicago')->utc(),
                    endsAt: Carbon::parse('2026-05-01 20:00:00', 'America/Chicago')->utc(),
                    description: 'Updated description',
                    meta: ['zoom_uuid' => 'new-uuid'],
                ),
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
        $this->assertSame(
            '2026-05-01T20:30:00Z',
            data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at'),
        );
        $this->assertSame(
            '2026-05-01T20:31:00Z',
            data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at'),
        );
        $this->assertSame('zoom', data_get($webinar->meta, 'provider.key'));
        $this->assertSame(
            ['zoom_uuid' => 'new-uuid'],
            data_get($webinar->meta, 'provider.data'),
        );
        $this->assertArrayNotHasKey('zoom_uuid', $webinar->meta);

        Carbon::setTestNow();
    }

    public function test_sync_reports_authoritative_empty_snapshot_without_deleting_webinar(): void
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
            'description' => 'Must be preserved for review',
            'meta' => [
                'zoom_uuid' => 'uuid-missing-1',
            ],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(ProviderWebinarSnapshot::authoritative([]));

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
        $this->assertSame($missingWebinar->getKey(), $missing[0]['webinar_id']);
        $this->assertSame('zoom-missing-1', $missing[0]['external_id']);
        $this->assertFalse($missing[0]['has_registrations']);

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
            ->andReturn(ProviderWebinarSnapshot::authoritative([]));

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

    public function test_sync_skips_missing_reconciliation_for_non_authoritative_empty_snapshot(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();

        $series = WebinarSeries::query()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'external_id' => 'zoom-existing',
            'title' => 'Home Buyer Game Plan',
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andReturn(ProviderWebinarSnapshot::nonAuthoritative(
                webinars: [],
                reason: 'no_exact_title_matches',
            ));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Sync complete: 0 created, 0 updated, 0 deleted, 0 missing preserved.');
        $response->assertSessionHas(
            'error',
            'Zoom returned a non-authoritative Webinar result. Returned events were imported, but missing-event reconciliation was skipped and no local events were removed.',
        );

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
        ]);
        $this->assertSame([], session('sync_missing', []));

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
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $this->providerWebinar(
                    externalId: 'zoom-1001',
                    joinUrl: 'https://example.com/join',
                    registrationUrl: 'https://example.com/register',
                    startsAt: now()->addDays(7),
                    endsAt: now()->addDays(7)->addHour(),
                    description: 'Upcoming webinar',
                ),
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
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $this->providerWebinar(
                    externalId: 'zoom-existing',
                    joinUrl: 'https://example.com/existing-join',
                    registrationUrl: 'https://example.com/existing-register',
                    startsAt: now()->addDays(3),
                    endsAt: now()->addDays(3)->addHour(),
                    description: 'Existing webinar',
                ),
            ]));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->id,
        ]);

        Queue::assertNotPushed(NotifyWebinarWaitlistJob::class);
    }

    public function test_provider_connection_failure_preserves_existing_webinars(): void
    {
        $user = User::factory()->create();

        $series = WebinarSeries::factory()->create([
            'title' => 'Home Buyer Game Plan',
        ]);

        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'external_id' => 'zoom-existing',
            'title' => 'Home Buyer Game Plan',
            'meta' => [
                'normalized' => [
                    'post_event' => [
                        'attendance_recorded_at' => '2026-05-01T20:30:00Z',
                    ],
                ],
            ],
        ]);

        $zoomWebinarService = Mockery::mock(ZoomWebinarService::class);
        $zoomWebinarService->shouldReceive('listWebinarsByTitle')
            ->once()
            ->with('Home Buyer Game Plan')
            ->andThrow(new ConnectionException('Provider unavailable.'));

        $this->app->instance(ZoomWebinarService::class, $zoomWebinarService);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->getKey(),
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('zoom_sync_error', 'Unable to connect to Zoom.');

        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->getKey(),
            'external_id' => 'zoom-existing',
        ]);
        $this->assertSame(
            '2026-05-01T20:30:00Z',
            data_get($webinar->fresh()->meta, 'normalized.post_event.attendance_recorded_at'),
        );
    }

    public function test_sync_flushes_webinar_public_data_caches(): void
    {
        Cache::flush();

        $series = WebinarSeries::factory()->create([
            'status' => 'active',
            'slug' => 'home-buyer-game-plan',
        ]);

        $globalUpcomingKey = CacheKey::nextUpcomingWebinar();
        $seriesUpcomingKey = CacheKey::nextUpcomingWebinar($series->slug);
        $activeSeriesKey = CacheKey::activeWebinarSeries();
        $unrelatedKey = 'unrelated-cache-key';

        Cache::put($globalUpcomingKey, 100, now()->addMinutes(10));
        Cache::put($seriesUpcomingKey, 100, now()->addMinutes(10));
        Cache::put($activeSeriesKey, [$series->getKey()], now()->addMinutes(10));
        Cache::put($unrelatedKey, 'preserve me', now()->addMinutes(10));

        $this->assertTrue(Cache::has($globalUpcomingKey));
        $this->assertTrue(Cache::has($seriesUpcomingKey));
        $this->assertTrue(Cache::has($activeSeriesKey));
        $this->assertTrue(Cache::has($unrelatedKey));

        app(FlushWebinarCachesAction::class)->handle(
            seriesSlug: $series->slug,
        );

        $this->assertFalse(Cache::has($globalUpcomingKey));
        $this->assertFalse(Cache::has($seriesUpcomingKey));
        $this->assertFalse(Cache::has($activeSeriesKey));

        $this->assertTrue(Cache::has($unrelatedKey));
    }

    public function test_series_creation_requires_and_persists_provider_event_type(): void
    {
        $user = User::factory()->create();

        $missingType = $this->actingAs($user)
            ->from(route('crm.webinar-series.index'))
            ->post(route('crm.webinar-series.store'), [
                'title' => 'Weekly Planning Session',
            ]);

        $missingType->assertRedirect(route('crm.webinar-series.index'));
        $missingType->assertSessionHasErrors('provider_event_type');

        $response = $this->actingAs($user)->post(route('crm.webinar-series.store'), [
            'title' => 'Weekly Planning Session',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas('success', 'Webinar series created.');

        $this->assertDatabaseHas('webinar_series', [
            'title' => 'Weekly Planning Session',
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
        ]);
    }

    public function test_series_event_type_update_does_not_retype_existing_occurrences(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->create([
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);
        $historicalOccurrence = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
        ]);

        $response = $this->actingAs($user)->patch(
            route('crm.webinar-series.provider-event-type.update', $series),
            [
                'provider_event_type' => WebinarProviderEventType::Meeting->value,
            ],
        );

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas(
            'success',
            'Series event type updated to Meeting. Existing occurrences were not changed.',
        );

        $this->assertSame(
            WebinarProviderEventType::Meeting->value,
            $series->refresh()->provider_event_type,
        );
        $this->assertSame(
            WebinarProviderEventType::Webinar->value,
            $historicalOccurrence->refresh()->provider_event_type,
        );
    }

    public function test_meeting_series_sync_uses_meeting_provider_adapter(): void
    {
        $this->freezeTime();

        $user = User::factory()->create();
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Weekly Planning Session',
        ]);

        $events = Mockery::mock(ZoomEventService::class);
        $events->shouldReceive('listEventsByTitle')
            ->once()
            ->with(
                WebinarProviderEventType::Meeting,
                'Weekly Planning Session',
            )
            ->andReturn(ProviderWebinarSnapshot::authoritative([
                $this->providerWebinar(
                    externalId: 'zoom-meeting-1001',
                    title: 'Weekly Planning Session',
                    joinUrl: 'https://example.com/meeting-1001',
                    startsAt: now()->addWeek(),
                    endsAt: now()->addWeek()->addHour(),
                    description: 'A synced Zoom Meeting',
                    meta: ['zoom_uuid' => 'meeting-uuid-1001'],
                ),
            ]));

        $this->app->instance(ZoomEventService::class, $events);

        $response = $this->actingAs($user)->post(route('crm.webinar-series.sync'), [
            'webinar_series_id' => $series->getKey(),
        ]);

        $response->assertRedirect(route('crm.webinar-series.index'));
        $response->assertSessionHas(
            'success',
            'Sync complete: 1 created, 0 updated, 0 deleted, 0 missing preserved.',
        );

        $this->assertDatabaseHas('webinars', [
            'webinar_series_id' => $series->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'external_id' => 'zoom-meeting-1001',
            'title' => 'Weekly Planning Session',
        ]);

        Carbon::setTestNow();
    }

    private function providerWebinar(
        string $externalId,
        string $title = 'Home Buyer Game Plan',
        ?string $joinUrl = null,
        ?string $registrationUrl = null,
        mixed $startsAt = null,
        mixed $endsAt = null,
        string $timezone = 'America/Chicago',
        ?string $description = null,
        array $meta = [],
    ): ProviderWebinarData {
        return new ProviderWebinarData(
            externalId: $externalId,
            title: $title,
            joinUrl: $joinUrl,
            registrationUrl: $registrationUrl,
            startsAt: $startsAt,
            endsAt: $endsAt,
            timezone: $timezone,
            description: $description,
            meta: $meta,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        Mockery::close();

        parent::tearDown();
    }
}