<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Actions\FinalizeWebinarRegistrationAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebinarAutomationOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registration_commits_its_automation_event_with_the_registration(): void
    {
        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->once();
        app()->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $webinar = Webinar::factory()->create(['external_id' => null]);

        $result = app(CreateWebinarRegistrationAction::class)->handle(
            validated: [
                'first_name' => 'Durable',
                'email' => 'durable-webinar@example.com',
            ],
            request: Request::create('/register', 'POST'),
            webinar: $webinar,
        );

        $this->assertTrue($result->wasCreated());
        $this->assertDatabaseHas('automation_event_outbox_events', [
            'event_key' => 'webinar.registered',
            'subject_type' => $result->registration->getMorphClass(),
            'subject_id' => (string) $result->registration->getKey(),
        ]);
    }

    public function test_webinar_event_families_persist_only_compact_allowlisted_envelopes(): void
    {
        $occurredAt = now()->startOfSecond();
        $series = WebinarSeries::factory()->create([
            'slug' => 'compact-series',
            'status' => 'active',
            'meta' => [
                'raw' => str_repeat('series-payload', 200),
            ],
        ]);
        $webinar = Webinar::factory()
            ->for($series)
            ->create([
                'slug' => 'compact-occurrence',
                'playback_url' => 'https://provider.example.test/recording',
                'playback_passcode' => 'secret-passcode',
                'playback_token' => 'secret-token',
                'meta' => [
                    'recording_response' => str_repeat('recording-payload', 200),
                ],
                'provider_settings' => [
                    'host_secret' => 'not-for-automation',
                ],
            ]);
        $registration = WebinarRegistration::factory()
            ->for($webinar)
            ->create([
                'webinar_slug' => $webinar->slug,
                'status' => 'attended',
                'attended_at' => $occurredAt,
                'meta' => [
                    'provider' => [
                        'join_url' => 'https://provider.example.test/join',
                        'raw' => str_repeat('provider-payload', 200),
                    ],
                ],
            ]);

        $payload = [
            'attendance' => [
                'provider' => 'zoom',
                'status' => 'attended',
                'duration' => 1800,
                'join_time' => $occurredAt->toISOString(),
                'leave_time' => $occurredAt->copy()->addMinutes(30)->toISOString(),
                'participant' => [
                    'email' => 'participant@example.test',
                ],
                'raw' => str_repeat('participant-payload', 200),
            ],
            'cancellation' => [
                'source' => 'public_link',
                'resolved_from_registration_id' => $registration->getKey(),
                'canonical_registration_id' => $registration->getKey(),
                'traversed_registration_ids' => [
                    $registration->getKey(),
                    (string) $registration->getKey(),
                ],
                'raw' => str_repeat('cancellation-payload', 200),
            ],
            'provider' => [
                'key' => 'zoom',
                'start_url' => 'https://provider.example.test/host',
            ],
            'post_event' => [
                'event' => 'recording.completed',
                'recording_files' => [
                    ['download_url' => 'https://provider.example.test/download'],
                ],
            ],
            'contact' => [
                'email' => 'contact@example.test',
                'phone' => '+15555550100',
            ],
            'raw' => str_repeat('unbounded-payload', 500),
        ];
        $meta = [
            'source' => 'generic_test',
            'contact' => [
                'email' => 'contact@example.test',
            ],
            'raw' => str_repeat('unbounded-meta', 500),
        ];
        $emit = app(EmitWebinarAutomationEventAction::class);

        foreach ([
            'webinar.registered',
            'webinar.cancelled',
            'webinar.attended',
            'webinar.missed',
        ] as $eventKey) {
            $emit->forRegistration(
                eventKey: $eventKey,
                registration: $registration,
                occurredAt: $occurredAt,
                payload: $payload,
                meta: $meta,
            );
        }

        foreach ([
            'webinar.ended',
            'webinar.replay_available',
        ] as $eventKey) {
            $emit->forWebinar(
                eventKey: $eventKey,
                webinar: $webinar,
                occurredAt: $occurredAt,
                payload: $payload,
                meta: $meta,
            );
        }

        $events = AutomationEventOutboxEvent::query()
            ->whereIn('event_key', [
                'webinar.registered',
                'webinar.cancelled',
                'webinar.attended',
                'webinar.missed',
                'webinar.ended',
                'webinar.replay_available',
            ])
            ->get()
            ->keyBy('event_key');

        $this->assertCount(6, $events);

        $baseRegistrationPayload = [
            'webinar_registration' => [
                'id' => $registration->getKey(),
                'webinar_id' => $webinar->getKey(),
                'status' => 'attended',
                'webinar_slug' => $webinar->slug,
                'source' => $registration->source,
                'registered_at' => $registration->registered_at?->toISOString(),
                'attended_at' => $occurredAt->toISOString(),
            ],
            'webinar' => [
                'id' => $webinar->getKey(),
                'webinar_series_id' => $series->getKey(),
                'slug' => $webinar->slug,
                'starts_at' => $webinar->starts_at?->toISOString(),
                'ends_at' => $webinar->ends_at?->toISOString(),
                'playback_available' => true,
            ],
            'webinar_series' => [
                'id' => $series->getKey(),
                'slug' => $series->slug,
                'status' => $series->status,
            ],
        ];
        $baseWebinarPayload = [
            'webinar' => $baseRegistrationPayload['webinar'],
            'webinar_series' => $baseRegistrationPayload['webinar_series'],
        ];

        $this->assertEquals(
            $baseRegistrationPayload,
            $events['webinar.registered']->payload,
        );
        $this->assertEquals(
            array_merge($baseRegistrationPayload, [
                'cancellation' => [
                    'source' => 'public_link',
                    'resolved_from_registration_id' => $registration->getKey(),
                    'canonical_registration_id' => $registration->getKey(),
                    'traversed_registration_ids' => [$registration->getKey()],
                ],
            ]),
            $events['webinar.cancelled']->payload,
        );

        $expectedAttendance = [
            'attendance' => [
                'provider' => 'zoom',
                'status' => 'attended',
                'duration' => 1800,
                'join_time' => $occurredAt->toISOString(),
                'leave_time' => $occurredAt->copy()->addMinutes(30)->toISOString(),
            ],
        ];

        $this->assertEquals(
            array_merge($baseRegistrationPayload, $expectedAttendance),
            $events['webinar.attended']->payload,
        );
        $this->assertEquals(
            array_merge($baseRegistrationPayload, $expectedAttendance),
            $events['webinar.missed']->payload,
        );

        $expectedPostEvent = [
            'provider' => [
                'key' => 'zoom',
            ],
            'post_event' => [
                'event' => 'recording.completed',
            ],
        ];

        $this->assertEquals(
            array_merge($baseWebinarPayload, $expectedPostEvent),
            $events['webinar.ended']->payload,
        );
        $this->assertEquals(
            array_merge($baseWebinarPayload, $expectedPostEvent),
            $events['webinar.replay_available']->payload,
        );

        foreach ($events as $event) {
            $this->assertEquals([
                'source_module' => 'webinars',
                ...($event->subject_type === $registration->getMorphClass()
                    ? [
                        'webinar_registration_id' => $registration->getKey(),
                        'webinar_id' => $webinar->getKey(),
                        'webinar_slug' => $webinar->slug,
                    ]
                    : [
                        'webinar_id' => $webinar->getKey(),
                        'webinar_slug' => $webinar->slug,
                    ]),
                'source' => 'generic_test',
            ], $event->meta);

            $encodedPayload = json_encode(
                $event->payload,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            $encodedMeta = json_encode(
                $event->meta,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

            $this->assertLessThanOrEqual(1400, strlen($encodedPayload));
            $this->assertLessThanOrEqual(400, strlen($encodedMeta));
            $this->assertNoForbiddenKeys($event->payload);
            $this->assertNoForbiddenKeys($event->meta);
        }
    }

    public function test_registration_rolls_back_if_its_outbox_record_cannot_be_written(): void
    {
        $emit = Mockery::mock(EmitWebinarAutomationEventAction::class);
        $emit->shouldReceive('forRegistration')
            ->once()
            ->andThrow(new RuntimeException('Simulated outbox storage failure.'));
        app()->instance(EmitWebinarAutomationEventAction::class, $emit);

        $finalize = Mockery::mock(FinalizeWebinarRegistrationAction::class);
        $finalize->shouldReceive('handle')->never();
        app()->instance(FinalizeWebinarRegistrationAction::class, $finalize);

        $webinar = Webinar::factory()->create(['external_id' => null]);

        try {
            app(CreateWebinarRegistrationAction::class)->handle(
                validated: [
                    'first_name' => 'Rollback',
                    'email' => 'rollback-webinar@example.com',
                ],
                request: Request::create('/register', 'POST'),
                webinar: $webinar,
            );

            $this->fail('The registration transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated outbox storage failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('contacts', [
            'email' => 'rollback-webinar@example.com',
        ]);
        $this->assertDatabaseCount('webinar_registrations', 0);
        $this->assertDatabaseCount('automation_event_outbox_events', 0);
    }

    /**
     * @param array<string|int, mixed> $value
     */
    private function assertNoForbiddenKeys(array $value): void
    {
        $forbidden = [
            'contact',
            'download_token',
            'download_url',
            'email',
            'join_url',
            'meta',
            'participant',
            'phone',
            'playback',
            'playback_passcode',
            'playback_token',
            'playback_url',
            'provider_settings',
            'raw',
            'recording_files',
            'registration_url',
            'start_url',
        ];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $this->assertNotContains($key, $forbidden);
            }

            if (is_array($item)) {
                $this->assertNoForbiddenKeys($item);
            }
        }
    }
}