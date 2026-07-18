<?php

namespace Tests\Feature\Webinars;

use App\Integrations\Webinars\Zoom\Mappers\ZoomAttendanceMapper;
use App\Integrations\Webinars\Zoom\ZoomOAuthService;
use App\Integrations\Webinars\Zoom\ZoomWebinarService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ZoomWebinarServiceSnapshotTest extends TestCase
{
    public function test_empty_exact_title_result_is_non_authoritative(): void
    {
        Http::fake([
            '*' => Http::response([
                'webinars' => [],
                'next_page_token' => '',
            ]),
        ]);

        $snapshot = $this->service()->listWebinarsByTitle('Home Buyer Game Plan');

        $this->assertFalse($snapshot->authoritative);
        $this->assertSame('no_exact_title_matches', $snapshot->reason);
        $this->assertCount(0, $snapshot);
    }

    public function test_complete_matching_result_is_authoritative(): void
    {
        Http::fake([
            '*' => Http::response([
                'webinars' => [
                    [
                        'id' => 'zoom-1001',
                        'uuid' => 'uuid-1001',
                        'topic' => 'Home Buyer Game Plan',
                        'start_time' => '2026-05-01T19:00:00Z',
                        'duration' => 60,
                        'timezone' => 'America/Chicago',
                        'join_url' => 'https://example.test/join',
                    ],
                    [
                        'id' => 'zoom-other',
                        'uuid' => 'uuid-other',
                        'topic' => 'Another Webinar',
                        'start_time' => '2026-05-02T19:00:00Z',
                        'duration' => 60,
                        'timezone' => 'America/Chicago',
                    ],
                ],
                'next_page_token' => '',
            ]),
        ]);

        $snapshot = $this->service()->listWebinarsByTitle('Home Buyer Game Plan');

        $this->assertTrue($snapshot->authoritative);
        $this->assertNull($snapshot->reason);
        $this->assertCount(1, $snapshot);
        $this->assertSame('zoom-1001', $snapshot->webinars[0]->externalId);
        $this->assertSame(
            ['zoom_uuid' => 'uuid-1001'],
            $snapshot->webinars[0]->meta,
        );
    }

    public function test_malformed_provider_payload_is_non_authoritative(): void
    {
        Http::fake([
            '*' => Http::response([
                'unexpected' => [],
            ]),
        ]);

        $snapshot = $this->service()->listWebinarsByTitle('Home Buyer Game Plan');

        $this->assertFalse($snapshot->authoritative);
        $this->assertSame('invalid_provider_payload', $snapshot->reason);
        $this->assertCount(0, $snapshot);
    }

    public function test_empty_participant_results_are_non_authoritative_and_are_not_cached(): void
    {
        Http::fakeSequence()
            ->push([
                'participants' => [],
                'next_page_token' => '',
            ])
            ->push([
                'participants' => [],
                'next_page_token' => '',
            ]);

        $service = $this->service(accessTokenCalls: 2);

        $firstSnapshot = $service->listPastWebinarParticipants('zoom-1001');
        $secondSnapshot = $service->listPastWebinarParticipants('zoom-1001');

        $this->assertFalse($firstSnapshot->authoritative);
        $this->assertSame('no_participant_records', $firstSnapshot->reason);
        $this->assertCount(0, $firstSnapshot);
        $this->assertFalse($secondSnapshot->authoritative);
        $this->assertSame('no_participant_records', $secondSnapshot->reason);
        Http::assertSentCount(2);
    }

    public function test_complete_participant_result_is_authoritative_and_mapped(): void
    {
        Http::fake([
            '*' => Http::response([
                'participants' => [
                    [
                        'registrant_id' => 'registrant-1',
                        'user_email' => 'PERSON@EXAMPLE.COM',
                        'join_time' => '2026-05-01T19:00:00Z',
                        'leave_time' => '2026-05-01T20:00:00Z',
                        'duration' => 3600,
                    ],
                ],
                'next_page_token' => '',
            ]),
        ]);

        $snapshot = $this->service()->listPastWebinarParticipants('zoom-1001');

        $this->assertTrue($snapshot->authoritative);
        $this->assertNull($snapshot->reason);
        $this->assertCount(1, $snapshot);
        $this->assertSame('registrant-1', $snapshot->records[0]->registrantId);
        $this->assertSame('person@example.com', $snapshot->records[0]->email);
        $this->assertSame(3600, $snapshot->records[0]->duration);
    }

    public function test_malformed_participant_payload_is_non_authoritative(): void
    {
        Http::fake([
            '*' => Http::response([
                'unexpected' => [],
            ]),
        ]);

        $snapshot = $this->service()->listPastWebinarParticipants('zoom-1001');

        $this->assertFalse($snapshot->authoritative);
        $this->assertSame('invalid_provider_payload', $snapshot->reason);
        $this->assertCount(0, $snapshot);
    }

    public function test_invalid_participant_pagination_keeps_valid_positive_evidence_non_authoritative(): void
    {
        Http::fake([
            '*' => Http::response([
                'participants' => [
                    [
                        'registrant_id' => 'registrant-1',
                        'user_email' => 'person@example.com',
                    ],
                ],
                'next_page_token' => ['invalid'],
            ]),
        ]);

        $snapshot = $this->service()->listPastWebinarParticipants('zoom-1001');

        $this->assertFalse($snapshot->authoritative);
        $this->assertSame('invalid_provider_pagination_token', $snapshot->reason);
        $this->assertCount(1, $snapshot);
        $this->assertSame('registrant-1', $snapshot->records[0]->registrantId);
    }

    public function test_cancelling_an_already_absent_registrant_is_idempotent(): void
    {
        Http::fake([
            '*' => Http::response([], 404),
        ]);

        $this->service()->cancelRegistrant(
            webinarId: 'zoom-1001',
            registrantId: 'registrant-1',
        );

        Http::assertSent(function ($request): bool {
            return $request->method() === 'DELETE'
                && $request->url() === 'https://api.zoom.test/v2/webinars/zoom-1001/registrants/registrant-1';
        });
    }

    private function service(int $accessTokenCalls = 1): ZoomWebinarService
    {
        Config::set('webinars.providers.zoom.base_url', 'https://api.zoom.test/v2');

        $auth = Mockery::mock(ZoomOAuthService::class);
        $auth->shouldReceive('getAccessToken')
            ->times($accessTokenCalls)
            ->andReturn('test-token');

        return new ZoomWebinarService(
            auth: $auth,
            attendanceMapper: new ZoomAttendanceMapper(),
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
