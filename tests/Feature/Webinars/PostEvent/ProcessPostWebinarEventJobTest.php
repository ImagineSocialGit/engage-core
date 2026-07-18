<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarProviderEventJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
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
        Queue::fake();

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
                ->andReturn(ProviderAttendanceSnapshot::authoritative([$attendanceRecord]));

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

        $this->assertSame('attended', $attendedRegistration->status);
        $this->assertNotNull($attendedRegistration->attended_at);
        $this->assertSame('attended', data_get($attendedRegistration->meta, 'attendance.status'));
        $this->assertSame('zoom', data_get($attendedRegistration->meta, 'attendance.provider'));

        $this->assertSame('missed', $missedRegistration->status);
        $this->assertNull($missedRegistration->attended_at);
        $this->assertSame('missed', data_get($missedRegistration->meta, 'attendance.status'));
        $this->assertSame('zoom', data_get($missedRegistration->meta, 'attendance.provider'));
    }

    public function test_it_runs_configured_post_event_actions_in_order(): void
    {
        Queue::fake();

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
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'channels' => ['email'],
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
                ->andReturn(ProviderAttendanceSnapshot::authoritative([$attendanceRecord]));

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
        $this->assertNotNull(data_get($webinar->meta, 'automation_events.webinar_replay_available_recorded_at'));
        $this->assertNotNull(data_get($webinar->meta, 'automation_events.webinar_ended_recorded_at'));

        $attendedRegistration->refresh();
        $missedRegistration->refresh();

        $this->assertSame('attended', $attendedRegistration->status);
        $this->assertNotNull($attendedRegistration->attended_at);
        $this->assertSame('attended', data_get($attendedRegistration->meta, 'attendance.status'));

        $this->assertSame('missed', $missedRegistration->status);
        $this->assertNull($missedRegistration->attended_at);
        $this->assertSame('missed', data_get($missedRegistration->meta, 'attendance.status'));
    }

    public function test_it_dispatches_transactional_follow_ups_for_configured_channels_with_canonical_playback_payload(): void
    {
        Queue::fake();

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
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'channels' => ['email', 'sms'],
            'conditions' => [
                [
                    'field' => 'webinar.playback_url',
                    'operator' => 'filled',
                ],
            ],
        ]);

        $this->configurePostEventMessagesAndScheduleProfile();

        Carbon::setTestNow('2026-06-12 12:00:00');

        [$webinar, , , $attendanceRecord] = $this->makeWebinarWithRegistrations();

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) use ($webinar, $attendanceRecord): void {
            $mock->shouldReceive('key')
                ->zeroOrMoreTimes()
                ->andReturn('zoom');

            $mock->shouldReceive('listAttendanceRecords')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar) => $passedWebinar->is($webinar))
                ->andReturn(ProviderAttendanceSnapshot::authoritative([$attendanceRecord]));

            $mock->shouldReceive('getRecording')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar) => $passedWebinar->is($webinar))
                ->andReturn(new ProviderRecordingData(
                    playbackUrl: 'https://zoom.example.test/rec/play/abc123',
                    playbackPasscode: 'pass123',
                    raw: ['recording_id' => 'recording-1'],
                ));
        });

        $dispatches = [];

        $this->mock(DispatchMessageAction::class, function (MockInterface $mock) use (&$dispatches): void {
            $mock->shouldReceive('handle')
                ->times(4)
                ->andReturnUsing(function (...$arguments) use (&$dispatches): array {
                    $dispatches[] = $arguments;

                    return [];
                });
        });

        $this->mockProviderManager($provider);

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.ended',
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $this->assertCount(4, $dispatches);
        $this->assertSame([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ], array_map(fn (array $dispatch): string => $dispatch[1], $dispatches));

        foreach ($dispatches as $dispatch) {
            $payload = $dispatch[5];

            $this->assertSame(MessagePurpose::Transactional->value, $dispatch[2]);
            $this->assertSame('webinar', $dispatch[3]);
            $this->assertSame('webinar_ended', $dispatch[4]);

            $this->assertArrayHasKey('webinar_playback_url', $payload);
            $this->assertSame('https://zoom.example.test/rec/play/abc123', $payload['webinar_playback_url']);
            $this->assertArrayNotHasKey('playback_url', $payload);
        }
    }

    public function test_it_safely_no_ops_when_event_has_no_configured_actions(): void
    {
        Queue::fake();

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
    }


    private function configurePostEventMessagesAndScheduleProfile(): void
    {
        Config::set('messaging.email.definitions.transactional.webinar', [
            'post_attended' => [
                'key' => 'post_attended',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Thanks for attending',
                    'body' => 'Replay: {webinar_playback_url}',
                ],
            ],
            'post_missed' => [
                'key' => 'post_missed',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => EmailPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'subject' => 'Sorry we missed you',
                    'body' => 'Replay: {webinar_playback_url}',
                ],
            ],
        ]);

        Config::set('messaging.sms.definitions.transactional.webinar', [
            'post_attended' => [
                'key' => 'post_attended',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Thanks for attending. Replay: {webinar_playback_url}',
                ],
            ],
            'post_missed' => [
                'key' => 'post_missed',
                'dispatch_key' => 'webinar_ended',
                'payload_class' => SmsPayload::class,
                'queue' => 'notifications',
                'payload' => [
                    'message' => 'Sorry we missed you. Replay: {webinar_playback_url}',
                ],
            ],
        ]);

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'post_event_test_profile',
            'name' => 'Post-event test profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
        ]);

        foreach ([MessageChannel::Email->value, MessageChannel::Sms->value] as $channel) {
            foreach (['post_attended', 'post_missed'] as $messageType) {
                WebinarScheduleProfileItem::factory()->create([
                    'webinar_schedule_profile_id' => $profile->getKey(),
                    'key' => "{$channel}_{$messageType}",
                    'context_key' => $messageType,
                    'channel' => $channel,
                    'purpose' => MessagePurpose::Transactional->value,
                    'scope' => 'webinar',
                    'surface' => 'webinar_registrations',
                    'message_type' => $messageType,
                    'dispatch_key' => 'webinar_ended',
                    'message_template_key' => $messageType,
                    'timing' => 'immediate',
                    'schedule' => null,
                    'conditions' => [],
                    'is_enabled' => true,
                    'is_active' => true,
                ]);
            }
        }
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
            'phone' => '+15555550101',
        ]);

        $missedContact = Contact::factory()->create([
            'email' => 'missed@example.com',
            'phone' => '+15555550102',
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
