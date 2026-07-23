<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Services\WebinarStateCanonicalizer;
use Tests\TestCase;

class WebinarStateCanonicalizerTest extends TestCase
{
    public function test_legacy_registration_state_is_compacted_to_operational_fields(): void
    {
        $legacy = [
            'id' => 41,
            'status' => 'attended',
            'meta' => [
                'provider' => [
                    'name' => 'zoom',
                    'data' => [
                        'registrant_id' => 'registrant-41',
                        'join_url' => 'https://provider.example.test/join/41',
                        'raw' => [
                            'occurrence_id' => 'occurrence-7',
                            'email' => 'person@example.test',
                            'first_name' => 'Example',
                            'last_name' => 'Person',
                            'access_token' => 'secret-token',
                            'response' => str_repeat('provider-payload-', 700),
                        ],
                    ],
                ],
                'attendance' => [
                    'provider' => 'zoom',
                    'status' => 'attended',
                    'duration' => '1800',
                    'join_time' => '2026-07-23T14:00:00+00:00',
                    'leave_time' => '2026-07-23T14:30:00+00:00',
                    'recorded_at' => '2026-07-23T14:31:00+00:00',
                    'raw' => [
                        'id' => 'registrant-41',
                        'user_email' => 'person@example.test',
                        'user_name' => 'Example Person',
                        'download_url' => 'https://provider.example.test/private',
                    ],
                ],
                'provider_sync' => [
                    'status' => 'succeeded',
                    'provider' => 'zoom',
                    'attempts' => 1,
                    'succeeded_at' => '2026-07-23T13:59:00+00:00',
                    'raw_response' => [
                        'email' => 'person@example.test',
                        'payload' => str_repeat('provider-sync-payload-', 300),
                    ],
                ],
                'registration_finalization' => [
                    'status' => 'completed',
                ],
            ],
        ];

        $canonical = app(WebinarStateCanonicalizer::class)
            ->registration($legacy);

        $this->assertSame(41, $canonical['id']);
        $this->assertSame('attended', $canonical['status']);
        $this->assertSame([
            'key' => 'zoom',
            'registrant_id' => 'registrant-41',
            'join_url' => 'https://provider.example.test/join/41',
            'occurrence_id' => 'occurrence-7',
        ], $canonical['meta']['provider']);
        $this->assertSame([
            'provider' => 'zoom',
            'status' => 'attended',
            'duration' => 1800,
            'join_time' => '2026-07-23T14:00:00+00:00',
            'leave_time' => '2026-07-23T14:30:00+00:00',
            'recorded_at' => '2026-07-23T14:31:00+00:00',
            'provider_registrant_id' => 'registrant-41',
            'matched_by' => 'provider_registrant_id',
        ], $canonical['meta']['attendance']);
        $this->assertSame(
            ['status' => 'completed'],
            $canonical['meta']['registration_finalization'],
        );
        $this->assertSame([
            'status' => 'succeeded',
            'provider' => 'zoom',
            'attempts' => 1,
            'succeeded_at' => '2026-07-23T13:59:00+00:00',
        ], $canonical['meta']['provider_sync']);

        $this->assertCanonicalPayload(
            payload: $canonical['meta'],
            forbiddenKeys: [
                'raw',
                'email',
                'user_email',
                'first_name',
                'last_name',
                'access_token',
                'download_url',
                'raw_response',
                'payload',
            ],
            maximumBytes: 1024,
        );
        $this->assertLessThan(
            strlen((string) json_encode($legacy['meta'])) / 10,
            strlen((string) json_encode($canonical['meta'])),
        );
        $this->assertSame(
            $canonical,
            app(WebinarStateCanonicalizer::class)->registration($canonical),
        );
    }

    public function test_legacy_webinar_state_preserves_columns_and_compacts_provider_recording_meta(): void
    {
        $legacy = [
            'id' => 77,
            'external_id' => 'provider-event-77',
            'playback_url' => 'https://provider.example.test/play/77',
            'playback_passcode' => 'passcode-77',
            'meta' => [
                'provider' => [
                    'key' => 'zoom',
                    'data' => [
                        'raw' => [
                            'uuid' => 'provider-uuid-77',
                            'topic' => 'Duplicated provider title',
                            'join_url' => 'https://provider.example.test/host',
                        ],
                    ],
                    'zoom' => [
                        'recording' => [
                            'resolved_at' => '2026-07-23T15:00:00+00:00',
                            'raw' => [
                                'recording_files' => [
                                    [
                                        'play_url' => 'https://provider.example.test/private/play',
                                        'download_url' => 'https://provider.example.test/private/download',
                                        'download_access_token' => 'recording-secret',
                                    ],
                                ],
                                'payload' => str_repeat('recording-payload-', 700),
                            ],
                        ],
                    ],
                ],
                'normalized' => [
                    'post_event' => [
                        'attendance_ready' => true,
                    ],
                ],
                'automation_events' => [
                    'webinar_ended_recorded_at' => '2026-07-23T15:01:00+00:00',
                ],
            ],
        ];

        $canonical = app(WebinarStateCanonicalizer::class)
            ->webinar($legacy);

        $this->assertSame(77, $canonical['id']);
        $this->assertSame('provider-event-77', $canonical['external_id']);
        $this->assertSame(
            'https://provider.example.test/play/77',
            $canonical['playback_url'],
        );
        $this->assertSame('passcode-77', $canonical['playback_passcode']);
        $this->assertSame([
            'key' => 'zoom',
            'data' => [
                'zoom_uuid' => 'provider-uuid-77',
            ],
        ], $canonical['meta']['provider']);
        $this->assertTrue(
            $canonical['meta']['normalized']['post_event']['attendance_ready'],
        );
        $this->assertSame(
            '2026-07-23T15:00:00+00:00',
            $canonical['meta']['normalized']['post_event']['playback_resolved_at'],
        );
        $this->assertSame(
            '2026-07-23T15:01:00+00:00',
            $canonical['meta']['automation_events']['webinar_ended_recorded_at'],
        );

        $this->assertCanonicalPayload(
            payload: $canonical['meta'],
            forbiddenKeys: [
                'raw',
                'recording',
                'recording_files',
                'play_url',
                'download_url',
                'download_access_token',
                'payload',
            ],
            maximumBytes: 1024,
        );
        $this->assertLessThan(
            strlen((string) json_encode($legacy['meta'])) / 10,
            strlen((string) json_encode($canonical['meta'])),
        );
        $this->assertSame(
            $canonical,
            app(WebinarStateCanonicalizer::class)->webinar($canonical),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $forbiddenKeys
     */
    private function assertCanonicalPayload(
        array $payload,
        array $forbiddenKeys,
        int $maximumBytes,
    ): void {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertLessThanOrEqual($maximumBytes, strlen($encoded));
        $this->assertSame(
            [],
            array_values(array_intersect(
                $forbiddenKeys,
                $this->recursiveKeys($payload),
            )),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function recursiveKeys(array $payload): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $keys[] = strtolower($key);
            }

            if (is_array($value)) {
                array_push($keys, ...$this->recursiveKeys($value));
            }
        }

        return array_values(array_unique($keys));
    }
}