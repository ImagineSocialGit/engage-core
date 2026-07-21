<?php

namespace Tests\Feature\Webinars;

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
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class WebinarProviderEventTypeFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        FoundationZoomWebinarProvider::$snapshot = null;
        FoundationZoomMeetingProvider::$snapshot = null;

        Config::set('webinars.provider', 'zoom');
        Config::set('webinars.provider_event_type', 'webinar');
        Config::set(
            'webinars.providers.zoom.event_types.webinar.provider',
            FoundationZoomWebinarProvider::class,
        );
        Config::set(
            'webinars.providers.zoom.event_types.meeting.provider',
            FoundationZoomMeetingProvider::class,
        );
    }

    public function test_series_and_webinars_persist_provider_family_and_event_type(): void
    {
        $series = WebinarSeries::query()->create([
            'title' => 'Provider Identity Foundation',
        ]);

        $webinar = Webinar::query()->create([
            'webinar_series_id' => $series->getKey(),
            'title' => 'Provider Identity Event',
            'slug' => 'provider-identity-event',
        ]);

        $series->refresh();
        $webinar->refresh();

        $this->assertSame('zoom', $series->platform);
        $this->assertSame('webinar', $series->provider_event_type);
        $this->assertSame('zoom', $series->providerKey());
        $this->assertSame('webinar', $series->providerEventTypeKey());

        $this->assertSame('zoom', $webinar->platform);
        $this->assertSame('webinar', $webinar->provider_event_type);
        $this->assertSame('zoom', $webinar->providerKey());
        $this->assertSame('webinar', $webinar->providerEventTypeKey());
    }

    public function test_provider_manager_resolves_adapters_by_provider_family_and_event_type(): void
    {
        $manager = app(WebinarProviderManager::class);

        $this->assertInstanceOf(
            FoundationZoomWebinarProvider::class,
            $manager->provider('zoom', WebinarProviderEventType::Webinar),
        );
        $this->assertInstanceOf(
            FoundationZoomMeetingProvider::class,
            $manager->provider('zoom', 'meeting'),
        );
        $this->assertSame(
            ['webinar', 'meeting'],
            $manager->configuredEventTypes('zoom'),
        );

        $meetingSeries = WebinarSeries::factory()->meeting()->create();
        $meeting = Webinar::factory()
            ->for($meetingSeries)
            ->meeting()
            ->create();

        $this->assertInstanceOf(
            FoundationZoomMeetingProvider::class,
            $manager->forSeries($meetingSeries),
        );
        $this->assertInstanceOf(
            FoundationZoomMeetingProvider::class,
            $manager->forWebinar($meeting),
        );
    }

    public function test_provider_manager_rejects_unsupported_or_unconfigured_event_types(): void
    {
        $manager = app(WebinarProviderManager::class);

        try {
            $manager->provider('zoom', 'conference');
            $this->fail('Unsupported event types must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(
                'Unsupported Webinar provider event type [conference]',
                $exception->getMessage(),
            );
        }

        Config::set(
            'webinars.providers.zoom.event_types.meeting.provider',
            null,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Webinar provider [zoom] does not configure event type [meeting].',
        );

        $manager->provider('zoom', 'meeting');
    }

    public function test_meeting_sync_cannot_reinterpret_or_reconcile_webinar_records(): void
    {
        Queue::fake();

        $series = WebinarSeries::factory()->meeting()->create([
            'title' => 'Shared Zoom Topic',
        ]);

        $existingWebinar = Webinar::factory()
            ->for($series)
            ->create([
                'provider_event_type' => 'webinar',
                'external_id' => 'shared-external-id',
                'title' => 'Existing Webinar Record',
                'slug' => 'existing-webinar-record',
            ]);

        FoundationZoomMeetingProvider::$snapshot = ProviderWebinarSnapshot::authoritative([
            new ProviderWebinarData(
                externalId: 'shared-external-id',
                title: 'Shared Zoom Topic',
                joinUrl: 'https://zoom.example.test/meeting/join',
                registrationUrl: 'https://zoom.example.test/meeting/register',
                startsAt: now()->addDay(),
                endsAt: now()->addDay()->addHour(),
                timezone: 'America/Chicago',
                description: 'Meeting-backed event',
                meta: ['zoom_uuid' => 'meeting-uuid'],
            ),
        ]);

        $createdResult = app(SyncWebinarSeriesFromProviderAction::class)
            ->execute($series);

        $this->assertSame(1, $createdResult['created']);
        $this->assertSame(0, $createdResult['updated']);
        $this->assertSame('zoom', data_get($createdResult, 'reconciliation.provider'));
        $this->assertSame(
            'meeting',
            data_get($createdResult, 'reconciliation.provider_event_type'),
        );

        $meeting = Webinar::query()
            ->where('webinar_series_id', $series->getKey())
            ->where('platform', 'zoom')
            ->where('provider_event_type', 'meeting')
            ->where('external_id', 'shared-external-id')
            ->firstOrFail();

        $existingWebinar->refresh();

        $this->assertNotSame($existingWebinar->getKey(), $meeting->getKey());
        $this->assertSame('Existing Webinar Record', $existingWebinar->title);
        $this->assertSame('webinar', $existingWebinar->provider_event_type);
        $this->assertSame('Shared Zoom Topic', $meeting->title);
        $this->assertSame('meeting', $meeting->provider_event_type);

        FoundationZoomMeetingProvider::$snapshot = ProviderWebinarSnapshot::authoritative([]);

        $missingResult = app(SyncWebinarSeriesFromProviderAction::class)
            ->execute($series->refresh());

        $this->assertCount(1, $missingResult['missing']);
        $this->assertSame(
            $meeting->getKey(),
            $missingResult['missing'][0]['webinar_id'],
        );
        $this->assertSame(
            'meeting',
            $missingResult['missing'][0]['provider_event_type'],
        );
        $this->assertNotSame(
            $existingWebinar->getKey(),
            $missingResult['missing'][0]['webinar_id'],
        );
    }
}

abstract class FoundationZoomProvider implements WebinarProvider
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
        throw new LogicException('Not used by the provider event-type foundation test.');
    }

    public function cancelRegistration(WebinarRegistration $registration): void
    {
        throw new LogicException('Not used by the provider event-type foundation test.');
    }

    public function parseWebhook(Request $request): ProviderWebhookEvent
    {
        throw new LogicException('Not used by the provider event-type foundation test.');
    }

    public function listAttendanceRecords(Webinar $webinar): ProviderAttendanceSnapshot
    {
        return ProviderAttendanceSnapshot::nonAuthoritative(
            records: [],
            reason: 'not_used_by_foundation_test',
        );
    }

    public function getRecording(Webinar $webinar): ?ProviderRecordingData
    {
        return null;
    }
}

final class FoundationZoomWebinarProvider extends FoundationZoomProvider
{
    public static ?ProviderWebinarSnapshot $snapshot = null;
}

final class FoundationZoomMeetingProvider extends FoundationZoomProvider
{
    public static ?ProviderWebinarSnapshot $snapshot = null;
}