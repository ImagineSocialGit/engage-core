<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\Webinars\PostEvent\ProcessWebinarProviderEventJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ZoomWebhookTest extends TestCase
{
    private ?string $reusedTimestamp = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webinars.provider' => 'zoom',
            'services.zoom.webhook_secret' => 'test_zoom_webhook_secret',

            'webinars.providers.zoom.webhook_events' => [
                'webinar.ended' => 'webinar.ended',
                'webinar.completed' => 'webinar.ended',
                'recording.completed' => 'webinar.recording_completed',
            ],
        ]);
    }

    public function test_it_handles_zoom_url_validation(): void
    {
        $plainToken = 'plain-token-from-zoom';

        $response = $this->postJson(route('webhooks.webinar', ['provider' => 'zoom']), [
            'event' => 'endpoint.url_validation',
            'payload' => [
                'plainToken' => $plainToken,
            ],
        ]);

        $response->assertOk();

        $response->assertExactJson([
            'plainToken' => $plainToken,
            'encryptedToken' => hash_hmac(
                'sha256',
                $plainToken,
                config('services.zoom.webhook_secret')
            ),
        ]);
    }

    public function test_it_rejects_invalid_signatures(): void
    {
        $response = $this
            ->withHeaders([
                'x-zm-request-timestamp' => (string) time(),
                'x-zm-signature' => 'v0=invalid-signature',
            ])
            ->postJson(route('webhooks.webinar', ['provider' => 'zoom']), [
                'event' => 'webinar.ended',
                'payload' => [
                    'object' => [
                        'id' => '123456789',
                    ],
                ],
            ]);

        $response->assertUnauthorized();
    }

    public function test_it_dispatches_generic_runner_for_signed_events_with_a_webinar_id(): void
    {
        Queue::fake();

        $webinarId = '123456789';

        $response = $this->signedZoomPost([
            'event' => 'webinar.started',
            'payload' => [
                'object' => [
                    'id' => $webinarId,
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job) use ($webinarId) {
            return $job->provider === 'zoom'
                && $job->externalWebinarId === $webinarId
                && $job->event === 'webinar.started';
        });
    }

    public function test_it_ignores_supported_events_without_a_webinar_id(): void
    {
        Queue::fake();

        $response = $this->signedZoomPost([
            'event' => 'webinar.ended',
            'payload' => [
                'object' => [],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertNotPushed(ProcessWebinarProviderEventJob::class);
    }

    public function test_it_dispatches_finalize_job_for_webinar_ended_events(): void
    {
        Queue::fake();

        $webinarId = '123456789';

        $response = $this->signedZoomPost([
            'event' => 'webinar.ended',
            'payload' => [
                'object' => [
                    'id' => $webinarId,
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job) use ($webinarId) {
            return $job->provider === 'zoom'
                && $job->externalWebinarId === $webinarId
                && $job->event === 'webinar.ended';
        });
    }

    public function test_it_dispatches_finalize_job_for_webinar_completed_events(): void
    {
        Queue::fake();

        $webinarId = '987654321';

        $response = $this->signedZoomPost([
            'event' => 'webinar.completed',
            'payload' => [
                'object' => [
                    'id' => $webinarId,
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job) use ($webinarId) {
            return $job->provider === 'zoom'
                && $job->externalWebinarId === $webinarId
                && $job->event === 'webinar.ended';
        });
    }

    public function test_it_returns_not_found_for_unsupported_webinar_providers(): void
    {
        config([
            'webinars.provider' => 'unsupported',
        ]);

        $response = $this->postJson(route('webhooks.webinar', ['provider' => 'unsupported']), [
            'event' => 'endpoint.url_validation',
            'payload' => [
                'plainToken' => 'plain-token-from-zoom',
            ],
        ]);

        $response->assertNotFound();
    }

    public function test_it_rejects_requests_with_stale_timestamps(): void
    {
        $timestamp = (string) (time() - 1000);

        $payload = [
            'event' => 'webinar.ended',
            'payload' => [
                'object' => [
                    'id' => '123456789',
                ],
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $signature = 'v0='.hash_hmac(
            'sha256',
            'v0:'.$timestamp.':'.$body,
            config('services.zoom.webhook_secret')
        );

        $response = $this->call(
            method: 'POST',
            uri: route('webhooks.webinar', ['provider' => 'zoom']),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_ZM_SIGNATURE' => $signature,
            ],
            content: $body
        );

        $response->assertUnauthorized();
    }

    public function test_it_rejects_replayed_requests(): void
    {
        $payload = [
            'event' => 'webinar.started',
            'payload' => [
                'object' => [
                    'id' => '123456789',
                ],
            ],
        ];

        $firstResponse = $this->signedZoomPost($payload);

        $firstResponse->assertNoContent();

        $secondResponse = $this->signedZoomPost(
            $payload,
            reuseTimestamp: true
        );

        $secondResponse->assertUnauthorized();
    }

    private function signedZoomPost(
        array $payload,
        bool $reuseTimestamp = false
    ) {
        $timestamp = $reuseTimestamp && $this->reusedTimestamp
            ? $this->reusedTimestamp
            : (string) time();

        $this->reusedTimestamp = $timestamp;

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $signature = 'v0='.hash_hmac(
            'sha256',
            'v0:'.$timestamp.':'.$body,
            config('services.zoom.webhook_secret')
        );

        return $this->call(
            method: 'POST',
            uri: route('webhooks.webinar', ['provider' => 'zoom']),
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_ZM_REQUEST_TIMESTAMP' => $timestamp,
                'HTTP_X_ZM_SIGNATURE' => $signature,
            ],
            content: $body
        );
    }
}