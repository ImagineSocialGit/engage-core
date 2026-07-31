<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Actions\RecoverStaleScheduledMessageClaimsAction;
use App\Modules\Messaging\Contracts\Email\EmailMessage;
use App\Modules\Messaging\Contracts\Email\EmailProvider;
use App\Modules\Messaging\Data\Delivery\MessageSendResult;
use App\Modules\Messaging\Jobs\RecoverStaleScheduledMessageClaimsJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Modules\Messaging\Services\Email\EmailMessagingService;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryLeaseManager;
use App\Modules\Messaging\Services\ScheduledMessageDeliveryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ScheduledMessageDeliveryLeaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-18 18:00:00');

        config([
            'messaging.delivery.claim_lease_seconds' => 300,
            'messaging.delivery.stale_recovery_batch_size' => 100,
            'messaging.delivery.provider_idempotency.email.resend' => [
                'enabled' => true,
                'safe_retry_window_seconds' => 82800,
            ],
            'messaging.email.provider' => 'resend',
            'sms.provider' => 'telnyx',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_claim_persists_attempt_owned_fenced_lease(): void
    {
        $message = ScheduledMessage::factory()->create([
            'meta' => ['source' => 'lease_test'],
        ]);

        $attempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $attempt,
        );
        $this->assertSame(
            ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
            $attempt->status,
        );
        $this->assertNotNull($attempt->claim_token);
        $this->assertSame(
            now()->addMinutes(5)->toISOString(),
            $attempt->lease_expires_at?->toISOString(),
        );

        $message->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENDING, $message->status);
        $this->assertNotNull($message->provider_idempotency_key);
        $this->assertSame(1, $message->send_attempts);
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);

        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'scheduled_message_id' => $message->getKey(),
            'claim_token' => $attempt->claim_token,
            'attempt_number' => 1,
            'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
        ]);

        $this->assertNull(
            app(ClaimScheduledMessageForSendingAction::class)
                ->handle($message),
        );
    }

    public function test_expired_pre_submission_claim_requeues_with_stable_idempotency(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $firstAttempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $firstAttempt,
        );

        $firstToken = $firstAttempt->claim_token;
        $idempotencyKey = $message->refresh()->provider_idempotency_key;

        Carbon::setTestNow(now()->addMinutes(6));

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)
            ->handle();

        $this->assertCount(1, $result['requeued']);
        $this->assertCount(0, $result['failed']);

        $message->refresh();
        $firstAttempt->refresh();

        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->status);
        $this->assertSame(
            $idempotencyKey,
            $message->provider_idempotency_key,
        );
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);
        $this->assertSame(
            ScheduledMessageDeliveryAttempt::STATUS_RECOVERED,
            $firstAttempt->status,
        );
        $this->assertSame(
            'stale_claim_recovered',
            $firstAttempt->reason_code,
        );

        $secondAttempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $secondAttempt,
        );
        $this->assertNotSame(
            $firstToken,
            $secondAttempt->claim_token,
        );

        $message->refresh();

        $this->assertSame(
            $idempotencyKey,
            $message->provider_idempotency_key,
        );
        $this->assertSame(2, $message->send_attempts);
    }

    public function test_ambiguous_non_idempotent_submission_fails_instead_of_resending(): void
    {
        $message = ScheduledMessage::factory()->sms()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $attempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $attempt,
        );
        $this->assertTrue(
            app(ScheduledMessageDeliveryLeaseManager::class)
                ->beginProviderSubmission(
                    $attempt,
                    '+15555550123',
                ),
        );

        Carbon::setTestNow(now()->addMinutes(6));

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)
            ->handle();

        $this->assertCount(0, $result['requeued']);
        $this->assertCount(1, $result['failed']);

        $message->refresh();
        $attempt->refresh();

        $this->assertSame(ScheduledMessage::STATUS_FAILED, $message->status);
        $this->assertStringContainsString(
            'automatic retry was blocked',
            (string) $message->failure_reason,
        );
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);
        $this->assertSame(
            ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            $attempt->status,
        );
        $this->assertSame(
            'stale_provider_submission_outcome_unknown',
            $attempt->reason_code,
        );
        $this->assertSame('+15555550123', $attempt->destination);
    }

    public function test_expired_provider_idempotency_window_blocks_ambiguous_retry(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $attempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $attempt,
        );
        $this->assertTrue(
            app(ScheduledMessageDeliveryLeaseManager::class)
                ->beginProviderSubmission(
                    $attempt,
                    'fixture@example.test',
                ),
        );

        Carbon::setTestNow(now()->addDay());

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)
            ->handle();

        $this->assertCount(0, $result['requeued']);
        $this->assertCount(1, $result['failed']);

        $message->refresh();

        $this->assertSame(
            ScheduledMessage::STATUS_FAILED,
            $message->status,
        );
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);
    }

    public function test_expired_worker_cannot_overwrite_a_later_attempt_outcome(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $oldAttempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $oldAttempt,
        );

        Carbon::setTestNow(now()->addMinutes(6));
        app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

        $newAttempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        $this->assertInstanceOf(
            ScheduledMessageDeliveryAttempt::class,
            $newAttempt,
        );

        $manager = app(ScheduledMessageDeliveryLeaseManager::class);
        $result = MessageSendResult::sent(
            provider: 'test',
            providerMessageId: 'provider-message-1',
        );

        $this->assertNull($manager->complete(
            claimedAttempt: $oldAttempt,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        ));

        $completed = $manager->complete(
            claimedAttempt: $newAttempt,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        );

        $this->assertInstanceOf(ScheduledMessage::class, $completed);

        $message->refresh();
        $newAttempt->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $message->status);
        $this->assertSame('test', $message->provider);
        $this->assertSame(
            'provider-message-1',
            $message->provider_message_id,
        );
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);

        $this->assertSame(
            ScheduledMessageDeliveryAttempt::STATUS_SENT,
            $newAttempt->status,
        );
        $this->assertSame('test', $newAttempt->provider);
        $this->assertSame(
            'provider-message-1',
            $newAttempt->provider_message_id,
        );
    }

    public function test_recovery_job_redispatches_recovered_pending_message(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'queue' => 'emails',
        ]);
        app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);

        Carbon::setTestNow(now()->addMinutes(6));

        (new RecoverStaleScheduledMessageClaimsJob())->handle(
            recoverStaleClaims:
                app(RecoverStaleScheduledMessageClaimsAction::class),
            deliveryPolicy:
                app(ScheduledMessageDeliveryPolicy::class),
        );

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool =>
                $job->scheduledMessageId === $message->getKey(),
        );
    }

    public function test_email_service_passes_stable_provider_idempotency_key(): void
    {
        LeaseTestEmailProvider::$idempotencyKey = null;

        config([
            'messaging.email.provider' => 'lease_test',
            'messaging.email.providers.lease_test.provider' =>
                LeaseTestEmailProvider::class,
        ]);

        $result = app(EmailMessagingService::class)->send(
            new LeaseTestEmailMessage('delivery-key-123'),
        );

        $this->assertTrue($result->isSent());
        $this->assertSame(
            'delivery-key-123',
            LeaseTestEmailProvider::$idempotencyKey,
        );
    }
}

class LeaseTestEmailProvider implements EmailProvider
{
    public static ?string $idempotencyKey = null;

    public function provider(): string
    {
        return 'lease_test';
    }

    public function send(
        EmailMessage $message,
        ?string $idempotencyKey = null,
    ): MessageSendResult {
        self::$idempotencyKey = $idempotencyKey;

        return MessageSendResult::sent(provider: $this->provider());
    }
}

class LeaseTestEmailMessage implements EmailMessage
{
    public readonly array $meta;

    public function __construct(string $idempotencyKey)
    {
        $this->meta = [
            'delivery' => [
                'provider_idempotency_key' => $idempotencyKey,
            ],
        ];
    }

    public static function fromArray(array $payload): self
    {
        return new self((string) data_get(
            $payload,
            'meta.delivery.provider_idempotency_key',
        ));
    }

    public function to(): string
    {
        return 'test@example.com';
    }

    public function mailable(): Mailable
    {
        return new class extends Mailable {};
    }

    public function devPayload(): array
    {
        return ['meta' => $this->meta];
    }
}