<?php

namespace Tests\Feature\Webhooks;

use App\Models\Webinar;
use App\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZoomWebhookAttendanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_webinar_ended_webhook_reconciles_attendance(): void
    {

        config()->set('services.zoom.base_url', 'https://api.zoom.us/v2');
        config()->set('services.zoom.account_id', 'test-account-id');
        config()->set('services.zoom.client_id', 'test-client-id');
        config()->set('services.zoom.client_secret', 'test-client-secret');

        $webinar = Webinar::query()->create([
            'title' => 'Home Buyer Game Plan',
            'slug' => 'home-buyer-game-plan-1',
            'platform' => 'zoom',
            'external_id' => '987654321',
            'status' => 'completed',
            'starts_at' => Carbon::parse('2026-04-20 19:00:00', 'America/Chicago')->utc(),
            'ends_at' => Carbon::parse('2026-04-20 20:00:00', 'America/Chicago')->utc(),
            'timezone' => 'America/Chicago',
        ]);

        $attended = WebinarRegistration::query()->create([
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'first_name' => 'Jeff',
            'last_name' => 'Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '15555550101',
            'registered_at' => now(),
            'meta' => [
                'zoom' => [
                    'registrant_id' => 'reg_123',
                ],
            ],
        ]);

        $missed = WebinarRegistration::query()->create([
            'webinar_id' => $webinar->id,
            'webinar_slug' => $webinar->slug,
            'status' => 'registered',
            'source' => 'webinar_subdomain',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '15555550102',
            'registered_at' => now(),
            'meta' => [
                'zoom' => [
                    'registrant_id' => 'reg_999',
                ],
            ],
        ]);

        $this->mock(
            \App\Services\Zoom\ZoomWebinarService::class,
            function ($mock) {
                $mock->shouldReceive('listPastWebinarParticipants')
                    ->once()
                    ->with('987654321')
                    ->andReturn(collect([
                        [
                            'registrant_id' => 'reg_123',
                            'email' => 'jeff@example.com',
                            'join_time' => now(),
                            'leave_time' => now()->addMinutes(41),
                            'duration' => 41,
                            'raw' => [],
                        ],
                    ]));
            }
        );

        $response = $this->postJson('/webhooks/zoom', [
            'event' => 'webinar.ended',
            'payload' => [
                'object' => [
                    'id' => '987654321',
                ],
            ],
        ]);

        $response->assertNoContent();

        $attended->refresh();
        $missed->refresh();

        $this->assertNotNull($attended->attended_at);
        $this->assertSame('zoom', data_get($attended->meta, 'attendance.provider'));
        $this->assertSame(41, data_get($attended->meta, 'attendance.duration'));

        $this->assertNull($missed->attended_at);
        $this->assertSame('missed', data_get($missed->meta, 'attendance.status'));
    }
}