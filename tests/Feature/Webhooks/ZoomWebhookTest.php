<?php

namespace Tests\Feature\Webhooks;

use App\Modules\Webinars\Actions\PostEvent\HandleWebinarProviderWebhookEventAction;
use App\Modules\Webinars\Jobs\PostEvent\ProcessWebinarProviderEventJob;
use App\Support\Webhooks\Models\WebhookInboxReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ZoomWebhookTest extends TestCase
{
    use RefreshDatabase;

    private ?string $reusedTimestamp = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'client.key' => 'test-client',
            'webinars.provider' => 'zoom',
            'services.zoom.webhook_secret' => 'test_zoom_webhook_secret',
            'services.zoom.max_timestamp_drift_seconds' => 300,
            'webinars.providers.zoom.webhook_events' => [
                'webinar.ended' => 'webinar.ended',
                'webinar.completed' => 'webinar.ended',
                'meeting.ended' => 'webinar.ended',
                'recording.completed' => 'webinar.recording_completed',
            ],
        ]);
    }

    public function test_it_handles_zoom_url_validation_without_creating_a_receipt(): void
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

        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_it_rejects_invalid_signatures_without_creating_a_receipt(): void
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
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_it_dispatches_generic_runner_and_completes_a_receipt(): void
    {
        Queue::fake();

        $webinarId = '123456789';

        $response = $this->signedZoomPost([
            'event' => 'webinar.started',
            'payload' => [
                'object' => [
                    'id' => $webinarId,
                    'uuid' => 'webinar-instance-uuid',
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job) use ($webinarId) {
            return $job->provider === 'zoom'
                && $job->externalWebinarId === $webinarId
                && $job->externalWebinarUuid === 'webinar-instance-uuid'
                && $job->providerEventType === 'webinar'
                && $job->event === 'webinar.started';
        });

        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'client_key' => 'test-client',
            'provider' => 'zoom',
            'event_type' => 'webinar.started',
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_it_ignores_supported_events_without_a_webinar_id_and_completes_receipt(): void
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
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider' => 'zoom',
            'event_type' => 'webinar.ended',
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
        ]);
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
                && $job->providerEventType === 'webinar'
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
                && $job->providerEventType === 'webinar'
                && $job->event === 'webinar.ended';
        });
    }

    public function test_it_dispatches_meeting_ended_with_meeting_identity(): void
    {
        Queue::fake();

        $meetingId = '223344556';

        $response = $this->signedZoomPost([
            'event' => 'meeting.ended',
            'payload' => [
                'object' => [
                    'id' => $meetingId,
                    'uuid' => 'meeting-instance-uuid',
                    'type' => 2,
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job) use ($meetingId) {
            return $job->provider === 'zoom'
                && $job->externalWebinarId === $meetingId
                && $job->externalWebinarUuid === 'meeting-instance-uuid'
                && $job->providerEventType === 'meeting'
                && $job->event === 'webinar.ended';
        });
    }

    public function test_recording_completed_uses_zoom_object_type_for_provider_identity(): void
    {
        Queue::fake();

        $this->signedZoomPost([
            'event' => 'recording.completed',
            'payload' => [
                'object' => [
                    'id' => '445566778',
                    'uuid' => 'webinar-recording-uuid',
                    'type' => 5,
                ],
            ],
        ])->assertNoContent();

        $this->signedZoomPost([
            'event' => 'recording.completed',
            'payload' => [
                'object' => [
                    'id' => '556677889',
                    'uuid' => 'meeting-recording-uuid',
                    'type' => 2,
                ],
            ],
        ])->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job): bool {
            return $job->externalWebinarId === '445566778'
                && $job->externalWebinarUuid === 'webinar-recording-uuid'
                && $job->providerEventType === 'webinar'
                && $job->event === 'webinar.recording_completed';
        });

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job): bool {
            return $job->externalWebinarId === '556677889'
                && $job->externalWebinarUuid === 'meeting-recording-uuid'
                && $job->providerEventType === 'meeting'
                && $job->event === 'webinar.recording_completed';
        });
    }

    public function test_recording_completed_without_a_known_type_preserves_uuid_for_safe_resolution(): void
    {
        Queue::fake();

        $response = $this->signedZoomPost([
            'event' => 'recording.completed',
            'payload' => [
                'object' => [
                    'id' => '667788990',
                    'uuid' => 'generic-recording-uuid',
                ],
            ],
        ]);

        $response->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, function (ProcessWebinarProviderEventJob $job): bool {
            return $job->externalWebinarId === '667788990'
                && $job->externalWebinarUuid === 'generic-recording-uuid'
                && $job->providerEventType === null
                && $job->event === 'webinar.recording_completed';
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

    public function test_it_rejects_requests_with_stale_timestamps_without_creating_a_receipt(): void
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

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
        $this->assertDatabaseCount('webhook_inbox_receipts', 0);
    }

    public function test_completed_replay_returns_existing_outcome_without_dispatching_twice(): void
    {
        Queue::fake();

        $payload = [
            'event' => 'webinar.started',
            'payload' => [
                'object' => [
                    'id' => '123456789',
                ],
            ],
        ];

        $this->signedZoomPost($payload)->assertNoContent();

        $this
            ->signedZoomPost($payload, reuseTimestamp: true)
            ->assertNoContent();

        Queue::assertPushed(ProcessWebinarProviderEventJob::class, 1);
        $this->assertDatabaseCount('webhook_inbox_receipts', 1);
        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider' => 'zoom',
            'status' => WebhookInboxReceipt::STATUS_COMPLETED,
            'attempts' => 1,
        ]);
    }

    public function test_failed_processing_is_recorded_and_same_signed_request_can_resume(): void
    {
        $calls = 0;
        $action = Mockery::mock(HandleWebinarProviderWebhookEventAction::class);
        $action->shouldReceive('execute')
            ->twice()
            ->andReturnUsing(function () use (&$calls): void {
                $calls++;

                if ($calls === 1) {
                    throw new RuntimeException('Simulated Zoom processing failure.');
                }
            });

        app()->instance(HandleWebinarProviderWebhookEventAction::class, $action);

        $payload = [
            'event' => 'webinar.ended',
            'payload' => [
                'object' => [
                    'id' => '123456789',
                ],
            ],
        ];

        $this->withoutExceptionHandling();

        try {
            $this->signedZoomPost($payload);

            $this->fail('Expected the simulated processing failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated Zoom processing failure.',
                $exception->getMessage(),
            );
        } finally {
            $this->withExceptionHandling();
        }

        $this->assertDatabaseHas('webhook_inbox_receipts', [
            'provider' => 'zoom',
            'status' => WebhookInboxReceipt::STATUS_RETRYABLE_FAILED,
            'attempts' => 1,
        ]);

        $this
            ->signedZoomPost($payload, reuseTimestamp: true)
            ->assertNoContent();

        $receipt = WebhookInboxReceipt::query()->sole();

        $this->assertSame(WebhookInboxReceipt::STATUS_COMPLETED, $receipt->status);
        $this->assertSame(2, $receipt->attempts);
        $this->assertNotNull($receipt->completed_at);
        $this->assertNull($receipt->failed_at);
        $this->assertNull($receipt->last_error);
    }

    private function signedZoomPost(
        array $payload,
        bool $reuseTimestamp = false
    ) {
        $timestamp = $reuseTimestamp && $this->reusedTimestamp
            ? $this->reusedTimestamp
            : (string) time();

        $this->reusedTimestamp = $timestamp;

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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