<?php

namespace Tests\Feature\Webinars\PostEvent;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\SyncMessageTemplatePresetsAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ScheduledMessagePayloadResolver;
use App\Modules\Webinars\Actions\PostEvent\DispatchPostWebinarFollowUpsAction;
use App\Modules\Webinars\Actions\PostEvent\RecordWebinarProviderAttendanceAction;
use App\Modules\Webinars\Actions\PostEvent\ResolveWebinarPlaybackAction;
use App\Modules\Webinars\Actions\SyncWebinarScheduleProfileChainsAction;
use App\Modules\Webinars\Contracts\WebinarProvider;
use App\Modules\Webinars\Data\ProviderAttendanceSnapshot;
use App\Modules\Webinars\Data\ProviderRecordingData;
use App\Modules\Webinars\Data\WebinarAttendanceRecord;
use App\Modules\Webinars\Enums\WebinarProviderEventType;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarProviderEventJob;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Models\WebinarScheduleProfileItem;
use App\Modules\Webinars\Services\WebinarProviderManager;
use App\Modules\Webinars\Support\WebinarPlaybackLinkGenerator;
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
        $this->assertSame(
            'provider_registrant_id',
            data_get($attendedRegistration->meta, 'attendance.matched_by'),
        );
        $this->assertNull(data_get($attendedRegistration->meta, 'attendance.raw'));

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
                    raw: [
                        'recording_id' => 'recording-1',
                        'download_access_token' => 'recording-secret',
                        'download_url' => 'https://zoom.example.test/private/download',
                        'recording_files' => str_repeat('recording-payload-', 100),
                    ],
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
        $this->assertNull(data_get($webinar->meta, 'provider.zoom.recording'));
        $this->assertLessThanOrEqual(
            2048,
            strlen(json_encode($webinar->meta, JSON_THROW_ON_ERROR)),
        );
        $this->assertStringNotContainsString(
            'recording-secret',
            json_encode($webinar->meta, JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'recording-payload',
            json_encode($webinar->meta, JSON_THROW_ON_ERROR),
        );

        $attendedRegistration->refresh();
        $missedRegistration->refresh();

        $this->assertSame('attended', $attendedRegistration->status);
        $this->assertNotNull($attendedRegistration->attended_at);
        $this->assertSame('attended', data_get($attendedRegistration->meta, 'attendance.status'));

        $this->assertSame('missed', $missedRegistration->status);
        $this->assertNull($missedRegistration->attended_at);
        $this->assertSame('missed', data_get($missedRegistration->meta, 'attendance.status'));
    }

    public function test_it_enrolls_transactional_follow_up_chains_for_configured_channels_with_canonical_playback_context(): void
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

        [
            $webinar,
            $attendedRegistration,
            $missedRegistration,
            $attendanceRecord,
        ] = $this->makeWebinarWithRegistrations();

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
                    raw: [
                        'recording_id' => 'recording-1',
                        'download_access_token' => 'recording-secret',
                    ],
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

        $expectedPlaybackUrl = app(WebinarPlaybackLinkGenerator::class)
            ->forWebinar($webinar);

        $messages = ScheduledMessage::query()
            ->with([
                'messageChainEnrollment',
                'messageTemplateVersion',
            ])
            ->orderBy('id')
            ->get();

        $this->assertSame(2, MessageChainEnrollment::query()->count());
        $this->assertCount(4, $messages);
        $this->assertEqualsCanonicalizing([
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
            MessageChannel::Email->value,
            MessageChannel::Sms->value,
        ], $messages->pluck('channel')->all());
        $this->assertEqualsCanonicalizing([
            'post_attended',
            'post_attended',
            'post_missed',
            'post_missed',
        ], $messages->pluck('message_type')->all());

        foreach ($messages as $message) {
            $payload = app(ScheduledMessagePayloadResolver::class)->resolve($message);

            $this->assertSame(
                $expectedPlaybackUrl,
                $payload->tokens['webinar_playback_url'] ?? null,
            );
            $this->assertArrayNotHasKey('playback_url', $payload->tokens);
            $this->assertNotNull($message->message_chain_enrollment_id);
            $this->assertNotNull($message->message_chain_step_variant_id);
            $this->assertNotNull($message->message_template_version_id);
        }

        $attendedRegistration->refresh();
        $missedRegistration->refresh();

        $this->assertSame(
            'scheduled',
            data_get($attendedRegistration->meta, 'post_event_follow_up.status'),
        );
        $this->assertSame(
            'attended',
            data_get($attendedRegistration->meta, 'post_event_follow_up.outcome'),
        );
        $this->assertSame(
            'scheduled',
            data_get($missedRegistration->meta, 'post_event_follow_up.status'),
        );
        $this->assertSame(
            'missed',
            data_get($missedRegistration->meta, 'post_event_follow_up.outcome'),
        );
    }


    public function test_it_routes_a_colliding_external_id_to_the_typed_meeting_adapter(): void
    {
        Queue::fake();

        Config::set('webinars.post_event.events', [
            'webinar.ended' => [
                RecordWebinarProviderAttendanceAction::class,
            ],
        ]);

        Config::set('webinars.post_event.attendance.enabled', true);

        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'external_id' => '123456789',
            'meta' => [],
        ]);

        $meeting = Webinar::factory()->meeting()->create([
            'platform' => 'zoom',
            'external_id' => '123456789',
            'meta' => [],
        ]);

        $provider = $this->mock(WebinarProvider::class, function (MockInterface $mock) use ($meeting): void {
            $mock->shouldReceive('key')
                ->zeroOrMoreTimes()
                ->andReturn('zoom');

            $mock->shouldReceive('listAttendanceRecords')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar): bool => $passedWebinar->is($meeting))
                ->andReturn(ProviderAttendanceSnapshot::authoritative([]));

            $mock->shouldNotReceive('getRecording');
        });

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider, $meeting): void {
            $mock->shouldReceive('forWebinar')
                ->once()
                ->withArgs(fn (Webinar $passedWebinar): bool => $passedWebinar->is($meeting))
                ->andReturn($provider);
        });

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.ended',
            'providerEventType' => WebinarProviderEventType::Meeting->value,
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $this->assertNull(data_get(
            $webinar->fresh()->meta,
            'normalized.post_event.attendance_recorded_at',
        ));
        $this->assertNotNull(data_get(
            $meeting->fresh()->meta,
            'normalized.post_event.attendance_recorded_at',
        ));
    }

    public function test_it_safely_no_ops_for_an_untyped_colliding_provider_event(): void
    {
        Queue::fake();

        Config::set('webinars.post_event.events', [
            'webinar.recording_completed' => [
                ResolveWebinarPlaybackAction::class,
            ],
        ]);

        $webinar = Webinar::factory()->create([
            'platform' => 'zoom',
            'provider_event_type' => WebinarProviderEventType::Webinar->value,
            'external_id' => '123456789',
            'meta' => [],
        ]);

        $meeting = Webinar::factory()->meeting()->create([
            'platform' => 'zoom',
            'external_id' => '123456789',
            'meta' => [],
        ]);

        $this->mock(WebinarProviderManager::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forWebinar');
        });

        app(ProcessWebinarProviderEventJob::class, [
            'provider' => 'zoom',
            'externalWebinarId' => '123456789',
            'event' => 'webinar.recording_completed',
        ])->handle(
            webinarProviderManager: app(WebinarProviderManager::class),
        );

        $this->assertNull(data_get(
            $webinar->fresh()->meta,
            'normalized.post_event.playback_resolved_at',
        ));
        $this->assertNull(data_get(
            $meeting->fresh()->meta,
            'normalized.post_event.playback_resolved_at',
        ));
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
        foreach ([MessageChannel::Email->value, MessageChannel::Sms->value] as $channel) {
            Config::set("messaging.channel_availability.{$channel}", [
                'runtime_supported' => true,
                'provider_enabled' => true,
                'requires_explicit_opt_in' => $channel === MessageChannel::Sms->value,
                'surfaces' => [
                    'webinar_registrations' => true,
                ],
                'purpose_scopes' => [
                    'transactional:webinar' => true,
                ],
            ]);
        }

        Config::set('messaging.email.definitions.transactional.webinar', [
            'default' => [
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
            ],
        ]);

        Config::set('messaging.sms.definitions.transactional.webinar', [
            'default' => [
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
            ],
        ]);

        $profile = WebinarScheduleProfile::factory()->create([
            'key' => 'post_event_test_profile',
            'name' => 'Post-event test profile',
            'status' => WebinarScheduleProfile::STATUS_ACTIVE,
            'is_default' => true,
            'is_active' => true,
            'message_template_set_key' => 'default',
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

        app(SyncMessageTemplatePresetsAction::class)->handle(force: true);
        app(SyncWebinarScheduleProfileChainsAction::class)->handle(
            profile: $profile,
            force: true,
        );
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

        foreach ([$attendedContact, $missedContact] as $contact) {
            foreach ([MessageChannel::Email->value, MessageChannel::Sms->value] as $channel) {
                MessageConsent::query()->create([
                    'contact_id' => $contact->getKey(),
                    'channel' => $channel,
                    'purpose' => MessagePurpose::Transactional->value,
                    'scope' => 'webinar',
                    'consented_at' => now()->subMinute(),
                    'source' => 'test',
                ]);
            }
        }

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
            raw: [
                'provider_record' => true,
                'email' => 'person@example.com',
                'access_token' => 'attendance-secret',
                'participant' => str_repeat('participant-payload-', 100),
            ],
        );

        return [$webinar, $attendedRegistration, $missedRegistration, $attendanceRecord];
    }

    private function mockProviderManager(WebinarProvider $provider, bool $shouldResolve = true): void
    {
        $this->mock(WebinarProviderManager::class, function (MockInterface $mock) use ($provider, $shouldResolve): void {
            if (! $shouldResolve) {
                $mock->shouldNotReceive('forWebinar');

                return;
            }

            $mock->shouldReceive('forWebinar')
                ->once()
                ->withArgs(fn (Webinar $webinar): bool =>
                    $webinar->providerKey() === 'zoom'
                    && $webinar->providerEventTypeKey() === WebinarProviderEventType::Webinar->value
                )
                ->andReturn($provider);
        });
    }
}