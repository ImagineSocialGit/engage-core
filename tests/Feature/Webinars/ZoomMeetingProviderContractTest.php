<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\ZoomEventService;
use App\Integrations\Webinars\Zoom\ZoomMeetingProvider;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\AddRegistrantToWebinarProviderAction;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\ProviderWebinarSnapshot;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ZoomMeetingProviderContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_resolves_meeting_adapter_and_registration_uses_meeting_endpoint(): void
    {
        Config::set(
            'webinars.providers.zoom.event_types.meeting.provider',
            ZoomMeetingProvider::class,
        );
        Config::set('services.zoom.account_id', 'account-id');
        Config::set('services.zoom.client_id', 'client-id');
        Config::set('services.zoom.client_secret', 'client-secret');

        Http::fake([
            'https://zoom.us/oauth/token' => Http::response([
                'access_token' => 'token',
            ]),
            'https://api.zoom.us/v2/meetings/meeting-123/registrants' => Http::response([
                'registrant_id' => 'registrant-123',
                'join_url' => 'https://zoom.example.test/join/registrant-123',
            ]),
        ]);

        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Meeting->value,
            'external_id' => 'meeting-123',
        ]);
        $contact = Contact::factory()->create([
            'first_name' => 'Example',
            'last_name' => 'Registrant',
            'email' => 'registrant@example.test',
        ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($contact)
            ->create();

        $provider = app(WebinarProviderManager::class)->forWebinar($webinar);

        $this->assertInstanceOf(ZoomMeetingProvider::class, $provider);

        $result = app(AddRegistrantToWebinarProviderAction::class)
            ->handle($webinar, $registration);

        $this->assertSame('registrant-123', $result->registrantId);
        $this->assertSame(
            'https://zoom.example.test/join/registrant-123',
            $result->joinUrl,
        );

        Http::assertSent(fn ($request): bool =>
            $request->method() === 'POST'
            && $request->url() === 'https://api.zoom.us/v2/meetings/meeting-123/registrants'
        );
    }

    public function test_meeting_listing_attendance_cancellation_and_recording_use_meeting_contracts(): void
    {
        Config::set('services.zoom.account_id', 'account-id');
        Config::set('services.zoom.client_id', 'client-id');
        Config::set('services.zoom.client_secret', 'client-secret');

        Http::fake([
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'token']),
            'https://api.zoom.us/v2/users/me/meetings*' => Http::response([
                'meetings' => [[
                    'id' => 123456789,
                    'uuid' => 'meeting-uuid',
                    'topic' => 'Weekly Session',
                    'start_time' => '2026-07-22T15:00:00Z',
                    'duration' => 60,
                    'timezone' => 'America/Chicago',
                    'join_url' => 'https://zoom.example.test/host',
                    'registration_url' => 'https://zoom.example.test/register',
                ]],
                'next_page_token' => '',
            ]),
            'https://api.zoom.us/v2/report/meetings/123456789/participants*' => Http::response([
                'participants' => [[
                    'registrant_id' => 'registrant-123',
                    'user_email' => 'registrant@example.test',
                    'duration' => 45,
                ]],
                'next_page_token' => '',
            ]),
            'https://api.zoom.us/v2/meetings/123456789/registrants/registrant-123*' => Http::response([], 204),
            'https://api.zoom.us/v2/meetings/meeting-uuid/recordings' => Http::response([
                'recording_play_passcode' => 'passcode',
                'recording_files' => [[
                    'status' => 'completed',
                    'file_type' => 'MP4',
                    'play_url' => 'https://zoom.example.test/recording',
                ]],
            ]),
        ]);

        $events = app(ZoomEventService::class);
        $listing = $events->listEventsByTitle(
            WebinarProviderEventType::Meeting,
            'Weekly Session',
        );
        $attendance = $events->listPastParticipants(
            WebinarProviderEventType::Meeting,
            '123456789',
        );
        $events->cancelRegistrant(
            WebinarProviderEventType::Meeting,
            '123456789',
            'registrant-123',
        );
        $recording = $events->getRecording('meeting-uuid');

        $this->assertInstanceOf(ProviderWebinarSnapshot::class, $listing);
        $this->assertTrue($listing->authoritative);
        $this->assertCount(1, $listing->webinars);
        $this->assertSame('123456789', $listing->webinars[0]->externalId);

        $this->assertInstanceOf(ProviderAttendanceSnapshot::class, $attendance);
        $this->assertTrue($attendance->authoritative);
        $this->assertCount(1, $attendance->records);

        $this->assertInstanceOf(ProviderRecordingData::class, $recording);
        $this->assertSame('https://zoom.example.test/recording', $recording?->playbackUrl);
        $this->assertSame('passcode', $recording?->playbackPasscode);

        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), '/users/me/meetings')
            && $request['type'] === 'scheduled'
        );
        Http::assertSent(fn ($request): bool =>
            str_contains($request->url(), '/report/meetings/123456789/participants')
        );
        Http::assertSent(fn ($request): bool =>
            $request->method() === 'DELETE'
            && str_contains($request->url(), '/meetings/123456789/registrants/registrant-123')
        );
    }

    public function test_empty_meeting_results_remain_non_authoritative(): void
    {
        Config::set('services.zoom.account_id', 'account-id');
        Config::set('services.zoom.client_id', 'client-id');
        Config::set('services.zoom.client_secret', 'client-secret');

        Http::fake([
            'https://zoom.us/oauth/token' => Http::response(['access_token' => 'token']),
            'https://api.zoom.us/v2/users/me/meetings*' => Http::response([
                'meetings' => [],
                'next_page_token' => '',
            ]),
            'https://api.zoom.us/v2/report/meetings/meeting-123/participants*' => Http::response([
                'participants' => [],
                'next_page_token' => '',
            ]),
        ]);

        $events = app(ZoomEventService::class);

        $listing = $events->listEventsByTitle(
            WebinarProviderEventType::Meeting,
            'Missing Session',
        );
        $attendance = $events->listPastParticipants(
            WebinarProviderEventType::Meeting,
            'meeting-123',
        );

        $this->assertFalse($listing->authoritative);
        $this->assertSame('no_exact_title_matches', $listing->reason);
        $this->assertFalse($attendance->authoritative);
        $this->assertSame('no_participant_records', $attendance->reason);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}