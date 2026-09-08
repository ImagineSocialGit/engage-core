<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\ZoomEventService;
use App\Models\User;
use App\Modules\Webinars\Actions\ResolveRegisterableWebinarAction;
use App\Modules\Webinars\Actions\SyncWebinarSeriesFromProviderAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderRegistrationData;
use App\Modules\Webinars\Data\ProviderWebhookEvent;
use App\Modules\Webinars\Data\ProviderWebinarData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarOccurrenceSuppression;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

class WebinarOccurrenceHistoryHygieneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-09-04 12:00:00 UTC'));
        ScheduleGridMeetingProvider::$snapshot = null;

        Config::set('modules.enabled', [
            'core',
            'messaging',
            'webinars',
            'reporting',
        ]);
        Config::set('webinars.provider', 'zoom');
        Config::set(
            'webinars.providers.zoom.event_types.meeting.provider',
            ScheduleGridMeetingProvider::class,
        );
        Config::set(
            'webinars.providers.zoom.event_types.meeting.schedule_increment_minutes',
            15,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        ScheduleGridMeetingProvider::$snapshot = null;

        parent::tearDown();
    }

    public function test_webinar_type_history_collapses_provider_swap_duplicates_and_ignores_schedule_outliers(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Canonical History Test',
        ]);

        $legacyOnly = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Webinar,
            externalId: 'legacy-only',
            startsAt: '2026-07-14 00:00:00 UTC',
        );
        WebinarRegistration::factory()->for($legacyOnly)->create();

        $oldEmpty = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Webinar,
            externalId: 'old-empty',
            startsAt: '2026-07-28 00:00:00 UTC',
        );
        $meetingWithHistory = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'meeting-history',
            startsAt: '2026-07-28 00:00:00 UTC',
        );
        WebinarRegistration::factory()->count(2)->for($meetingWithHistory)->create();

        $oldWithHistory = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Webinar,
            externalId: 'old-history',
            startsAt: '2026-08-11 00:00:00 UTC',
        );
        WebinarRegistration::factory()->count(3)->for($oldWithHistory)->create();
        $meetingEmpty = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'meeting-empty',
            startsAt: '2026-08-11 00:00:00 UTC',
        );

        $quarterHour = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'quarter-hour',
            startsAt: '2026-08-18 00:15:00 UTC',
            providerListed: true,
        );
        $offGrid = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'rehearsal-1754',
            startsAt: '2026-08-25 00:54:00 UTC',
            providerListed: true,
        );

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $series))
            ->assertOk()
            ->assertViewHas('historyWebinars', function (Collection $history) use (
                $legacyOnly,
                $oldEmpty,
                $meetingWithHistory,
                $oldWithHistory,
                $meetingEmpty,
                $quarterHour,
                $offGrid,
            ): bool {
                $ids = $history->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

                return in_array($legacyOnly->getKey(), $ids, true)
                    && ! in_array($oldEmpty->getKey(), $ids, true)
                    && in_array($meetingWithHistory->getKey(), $ids, true)
                    && in_array($oldWithHistory->getKey(), $ids, true)
                    && ! in_array($meetingEmpty->getKey(), $ids, true)
                    && in_array($quarterHour->getKey(), $ids, true)
                    && ! in_array($offGrid->getKey(), $ids, true)
                    && count($ids) === 4;
            });
    }

    public function test_explicit_replacement_wins_canonical_history_slot(): void
    {
        $user = User::factory()->create();
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Replacement History Test',
        ]);

        $source = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Webinar,
            externalId: 'source-history',
            startsAt: '2026-08-11 00:00:00 UTC',
        );
        WebinarRegistration::factory()->count(3)->for($source)->create();

        $replacement = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'replacement-history',
            startsAt: '2026-08-11 00:00:00 UTC',
            replacementOf: $source,
        );

        $this->actingAs($user)
            ->get(route('crm.webinar-series.show', $series))
            ->assertOk()
            ->assertViewHas('historyWebinars', fn (Collection $history): bool =>
                $history->count() === 1
                && $history->first()?->is($replacement)
            );
    }

    public function test_zoom_list_normalization_marks_provider_schedule_provenance(): void
    {
        Config::set('services.zoom.account_id', 'account-id');
        Config::set('services.zoom.client_id', 'client-id');
        Config::set('services.zoom.client_secret', 'client-secret');

        Http::fake([
            'https://zoom.us/oauth/token*' => Http::response([
                'access_token' => 'token',
            ]),
            'https://api.zoom.us/v2/users/me/meetings*' => Http::response([
                'meetings' => [[
                    'id' => 'zoom-list-meeting',
                    'uuid' => 'zoom-list-uuid',
                    'topic' => 'Provider Provenance Test',
                    'start_time' => '2026-09-08T18:00:00Z',
                    'duration' => 60,
                    'timezone' => 'America/Denver',
                ]],
                'next_page_token' => '',
            ]),
        ]);

        $snapshot = app(ZoomEventService::class)->listEventsByTitle(
            WebinarProviderEventType::Meeting,
            'Provider Provenance Test',
        );

        $this->assertTrue($snapshot->authoritative);
        $this->assertSame(
            'zoom_list_api',
            $snapshot->webinars[0]->meta['schedule_source'] ?? null,
        );
    }

    public function test_meeting_sync_ignores_same_title_events_outside_configured_schedule_grid(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Schedule Grid Sync Test',
        ]);

        ScheduleGridMeetingProvider::$snapshot = ProviderWebinarSnapshot::authoritative([
            $this->providerOccurrence('off-grid', '2026-09-08 17:54:00 UTC'),
            $this->providerOccurrence('on-hour', '2026-09-08 18:00:00 UTC'),
            $this->providerOccurrence('quarter-hour', '2026-09-08 18:15:00 UTC'),
        ]);

        $result = app(SyncWebinarSeriesFromProviderAction::class)->execute($series);

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['ignored_schedule_outliers']);
        $this->assertDatabaseMissing('webinars', [
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'off-grid',
        ]);
        $this->assertDatabaseHas('webinars', [
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'on-hour',
        ]);
        $this->assertDatabaseHas('webinars', [
            'webinar_series_id' => $series->getKey(),
            'external_id' => 'quarter-hour',
        ]);
    }

    public function test_ignored_provider_schedule_outlier_is_not_falsely_marked_missing(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Provider Presence Test',
        ]);
        $existing = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'provider-present-off-grid',
            startsAt: '2026-09-08 17:54:00 UTC',
        );

        ScheduleGridMeetingProvider::$snapshot = ProviderWebinarSnapshot::authoritative([
            new ProviderWebinarData(
                externalId: 'provider-present-off-grid',
                title: 'Provider Presence Test',
                joinUrl: 'https://zoom.example.test/provider-present-off-grid',
                registrationUrl: null,
                startsAt: Carbon::parse('2026-09-08 17:54:00 UTC'),
                endsAt: Carbon::parse('2026-09-08 19:24:00 UTC'),
                timezone: 'America/Denver',
                description: null,
                meta: [
                    'zoom_uuid' => 'uuid-provider-present-off-grid',
                    'schedule_source' => 'zoom_list_api',
                ],
            ),
        ]);

        $result = app(SyncWebinarSeriesFromProviderAction::class)->execute($series);

        $this->assertSame(1, $result['ignored_schedule_outliers']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($existing->refresh()->isProviderActive());
    }

    public function test_existing_off_grid_meeting_is_not_registerable(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Registerable Grid Test',
        ]);

        $offGrid = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'off-grid-upcoming',
            startsAt: '2026-09-04 12:54:00 UTC',
            providerListed: true,
        );
        $valid = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'valid-upcoming',
            startsAt: '2026-09-04 13:00:00 UTC',
            providerListed: true,
        );

        $resolver = app(ResolveRegisterableWebinarAction::class);

        $this->assertFalse($resolver->isRegisterableForSeries($offGrid, $series));
        $this->assertTrue($resolver->getForSeries($series)?->is($valid) ?? false);
    }

    public function test_schedule_outlier_reconciliation_is_dry_run_by_default_and_uses_normal_removal_on_apply(): void
    {
        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Schedule Cleanup Test',
        ]);
        $offGrid = $this->occurrence(
            series: $series,
            providerEventType: WebinarProviderEventType::Meeting,
            externalId: 'cleanup-off-grid',
            startsAt: '2026-08-25 00:54:00 UTC',
        );

        $this->artisan('webinars:reconcile-schedule-outliers', [
            '--series' => (string) $series->getKey(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('webinars', [
            'id' => $offGrid->getKey(),
        ]);

        $this->artisan('webinars:reconcile-schedule-outliers', [
            '--series' => (string) $series->getKey(),
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('webinars', [
            'id' => $offGrid->getKey(),
        ]);
        $this->assertDatabaseHas('webinar_occurrence_suppressions', [
            'webinar_series_id' => $series->getKey(),
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'external_id' => 'cleanup-off-grid',
            'reason' => WebinarOccurrenceSuppression::REASON_OPERATOR_REMOVED,
        ]);
    }

    private function occurrence(
        WebinarSeries $series,
        WebinarProviderEventType $providerEventType,
        string $externalId,
        string $startsAt,
        ?Webinar $replacementOf = null,
        bool $providerListed = false,
    ): Webinar {
        $start = Carbon::parse($startsAt);

        return Webinar::factory()->for($series, 'webinarSeries')->create([
            'replacement_of_webinar_id' => $replacementOf?->getKey(),
            'platform' => 'zoom',
            'provider_event_type' => $providerEventType->value,
            'external_id' => $externalId,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(90),
            'meta' => $providerListed
                ? [
                    'provider' => [
                        'key' => 'zoom',
                        'data' => [
                            'schedule_source' => 'zoom_list_api',
                        ],
                    ],
                ]
                : [],
        ]);
    }

    private function providerOccurrence(string $externalId, string $startsAt): ProviderWebinarData
    {
        $start = Carbon::parse($startsAt);

        return new ProviderWebinarData(
            externalId: $externalId,
            title: 'Schedule Grid Sync Test',
            joinUrl: 'https://zoom.example.test/'.$externalId,
            registrationUrl: null,
            startsAt: $start,
            endsAt: $start->copy()->addMinutes(90),
            timezone: 'America/Denver',
            description: null,
            meta: [
                'zoom_uuid' => 'uuid-'.$externalId,
                'schedule_source' => 'zoom_list_api',
            ],
        );
    }
}

final class ScheduleGridMeetingProvider implements WebinarProvider
{
    public static ?ProviderWebinarSnapshot $snapshot = null;

    public function name(): string
    {
        return 'Zoom';
    }

    public function key(): string
    {
        return 'zoom';
    }

    public function listWebinarsByTitle(string $title): iterable
    {
        return static::$snapshot
            ?? ProviderWebinarSnapshot::authoritative([]);
    }

    public function registerAttendee(
        Webinar $webinar,
        WebinarRegistration $registration,
    ): ProviderRegistrationData {
        throw new LogicException('Not used by schedule-grid tests.');
    }

    public function cancelRegistration(WebinarRegistration $registration): void
    {
        throw new LogicException('Not used by schedule-grid tests.');
    }

    public function parseWebhook(Request $request): ProviderWebhookEvent
    {
        throw new LogicException('Not used by schedule-grid tests.');
    }

    public function listAttendanceRecords(Webinar $webinar): ProviderAttendanceSnapshot
    {
        return ProviderAttendanceSnapshot::nonAuthoritative(
            records: [],
            reason: 'not_used_by_schedule_grid_test',
        );
    }

    public function getRecording(Webinar $webinar): ?ProviderRecordingData
    {
        return null;
    }
}