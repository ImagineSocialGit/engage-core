<?php

namespace Tests\Feature\Webinars;

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

    private function service(): ZoomWebinarService
    {
        Config::set('webinars.providers.zoom.base_url', 'https://api.zoom.test/v2');

        $auth = Mockery::mock(ZoomOAuthService::class);
        $auth->shouldReceive('getAccessToken')
            ->once()
            ->andReturn('test-token');

        return new ZoomWebinarService($auth);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}
