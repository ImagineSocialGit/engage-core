<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarProviderEventJob;
use App\Modules\Webinars\Jobs\PostEvent\RoutePostWebinarRegistrationJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Tests\TestCase;

class ProcessPostWebinarEventJobTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_records_attendance_for_configured_webinar_ended_event(): void
    {
        Queue::fake([RoutePostWebinarRegistrationJob::class]);

        Config::set('webinars.post_event.events', [
            'webinar.ended' => [
                RecordWebinarProviderAttendanceAction::class,
            ],
        ]);

        Config::set('webinars.post_event.attendance.enabled', true);

        Carbon::setTestNow('2026-06-12 12:00:00');

        [$webinar, $attendedRegistration, $missedRegistration, $attendanceRecord] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) use ($webinar, $attendanceRecord): void {
            $mock->shouldReceive('key')
                ->zeroOrMoreTimes()
                ->andReturn('zoom');

            $mock->shouldReceive('listAttendanceRecords')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar) => $passedWebinar->is($webinar))
                ->andReturn(collect([$attendanceRecord]));

            $mock->shouldNotReceive('getRecording');
        });

        $this->mockProviderManager($provider);

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.ended',
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $webinar->refresh();

        $this->assertNull($webinar->playback_url);
        $this->assertNull($webinar->playback_passcode);
        $this->assertNull(data_get($webinar->meta, 'normalized.post_event.playback_resolved_at'));
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at'));

        $attendedRegistration->refresh();
        $missedRegistration->refresh();

        $this->assertNotNull($attendedRegistration->attended_at);
        $this->assertSame('attended', data_get($attendedRegistration->meta, 'attendance.status'));
        $this->assertSame('zoom', data_get($attendedRegistration->meta, 'attendance.provider'));

        $this->assertNull($missedRegistration->attended_at);
        $this->assertSame('missed', data_get($missedRegistration->meta, 'attendance.status'));
        $this->assertSame('zoom', data_get($missedRegistration->meta, 'attendance.provider'));

        Queue::assertNotPushed(RoutePostWebinarRegistrationJob::class);
    }

    public function test_it_runs_configured_post_event_actions_in_order(): void
    {
        Queue::fake([RoutePostWebinarRegistrationJob::class]);

        Config::set('webinars.post_event.events', [
            'webinar.ended' => [
                RecordWebinarProviderAttendanceAction::class,
                ResolveWebinarPlaybackAction::class,
                DispatchPostWebinarFollowUpsAction::class,
            ],
        ]);

        Config::set('webinars.post_event.attendance.enabled', true);
        Config::set('webinars.post_event.recordings.enabled', true);

        Config::set('webinars.post_event.outcome_messages', [
            'enabled' => true,
            'dispatch_key' => 'webinar_ended',
            'conditions' => [
                [
                    'field' => 'webinar.playback_url',
                    'operator' => 'filled',
                ],
            ],
        ]);

        Carbon::setTestNow('2026-06-12 12:00:00');

        [$webinar, $attendedRegistration, $missedRegistration, $attendanceRecord] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) use ($webinar, $attendanceRecord): void {
            $mock->shouldReceive('key')
                ->zeroOrMoreTimes()
                ->andReturn('zoom');

            $mock->shouldReceive('listAttendanceRecords')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar) => $passedWebinar->is($webinar))
                ->andReturn(collect([$attendanceRecord]));

            $mock->shouldReceive('getRecording')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar) => $passedWebinar->is($webinar))
                ->andReturn(new ProviderRecordingData(
                    playbackUrl: 'https://zoom.example.test/rec/play/abc123',
                    playbackPasscode: 'pass123',
                    raw: ['recording_id' => 'recording-1'],
                ));
        });

        $this->mockProviderManager($provider);

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.ended',
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $webinar->refresh();

        $this->assertSame('https://zoom.example.test/rec/play/abc123', $webinar->playback_url);
        $this->assertSame('pass123', $webinar->playback_passcode);
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.playback_resolved_at'));
        $this->assertNotNull(data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at'));
        $this->assertNotNull(data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at'));

        $attendedRegistration->refresh();
        $missedRegistration->refresh();

        $this->assertNotNull($attendedRegistration->attended_at);
        $this->assertSame('attended', data_get($attendedRegistration->meta, 'attendance.status'));

        $this->assertNull($missedRegistration->attended_at);
        $this->assertSame('missed', data_get($missedRegistration->meta, 'attendance.status'));

        Queue::assertNotPushed(RoutePostWebinarRegistrationJob::class);
    }

    public function test_it_safely_no_ops_when_event_has_no_configured_actions(): void
    {
        Queue::fake([RoutePostWebinarRegistrationJob::class]);

        Config::set('webinars.post_event.events', []);

        Carbon::setTestNow('2026-06-12 12:00:00');

        [$webinar] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('key');
            $mock->shouldNotReceive('listAttendanceRecords');
            $mock->shouldNotReceive('getRecording');
        });

        $this->mockProviderManager($provider, shouldResolve: false);

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.started',
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $webinar->refresh();

        $this->assertNull(data_get($webinar->meta, 'normalized.post_event.attendance_recorded_at'));
        $this->assertNull(data_get($webinar->meta, 'normalized.post_event.playback_resolved_at'));
        $this->assertNull(data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at'));

        Queue::assertNotPushed(RoutePostWebinarRegistrationJob::class);
    }

    private function makeWebinarWithRegistrations(): array
    {
        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'external_id' => '123456789',
            'playback_url' => null,
            'playback_passcode' => null,
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->subHour(),
            'meta' => [],
        ]);

        $attendedContact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);

        $missedContact = Contact::factory()->create([
            'email' => 'missed@example.com',
        ]);

        $attendedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($attendedContact)
            ->create([
                'attended_at' => null,
                'meta' => [
                    'provider' => [
                        'data' => [
                            'registrant_id' => 'registrant-1',
                        ],
                    ],
                ],
            ]);

        $missedRegistration = WebinarRegistration::factory()
            ->for($webinar)
            ->for($missedContact)
            ->create([
                'attended_at' => null,
                'meta' => [],
            ]);

        $attendanceRecord = new WebinarAttendanceRecord(
            registrantId: 'registrant-1',
            email: 'person@example.com',
            status: 'attended',
            duration: 3600,
            joinTime: now()->subMinutes(55),
            leaveTime: now()->subMinutes(5),
            raw: ['provider_record' => true],
        );

        return [$webinar, $attendedRegistration, $missedRegistration, $attendanceRecord];
    }

    private function mockProviderManager(WebinarProvider $provider, bool $shouldResolve = true): void
    {
        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider, $shouldResolve): void {
            if (! $shouldResolve) {
                $mock->shouldNotReceive('provider');

                return;
            }

            $mock->shouldReceive('provider')
                ->once()
                ->with('zoom')
                ->andReturn($provider);
        });
    }
}