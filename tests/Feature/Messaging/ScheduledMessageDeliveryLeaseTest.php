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

    public function test_claim_persists_a_fenced_lease_and_independent_attempt(): void
    {
        $message = ScheduledMessage::factory()->create([
            'meta' => ['source' => 'lease_test'],
        ]);

        $claimed = app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        $this->assertInstanceOf(ScheduledMessage::class, $claimed);
        $this->assertSame(ScheduledMessage::STATUS_SENDING, $claimed->status);
        $this->assertNotNull($claimed->claim_token);
        $this->assertSame(
            now()->addMinutes(5)->toISOString(),
            $claimed->claim_expires_at?->toISOString(),
        );
        $this->assertNotNull($claimed->provider_idempotency_key);
        $this->assertSame(1, $claimed->send_attempts);
        $this->assertEquals([
            'source' => 'lease_test',
        ], $claimed->meta);

        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'scheduled_message_id' => $message->getKey(),
            'claim_token' => $claimed->claim_token,
            'provider_idempotency_key' => $claimed->provider_idempotency_key,
            'attempt_number' => 1,
            'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
        ]);

        $this->assertNull(
            app(ClaimScheduledMessageForSendingAction::class)->handle($message),
        );
    }

    public function test_expired_pre_submission_claim_is_requeued_with_stable_idempotency(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $firstClaim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);
        $firstToken = $firstClaim?->claim_token;
        $idempotencyKey = $firstClaim?->provider_idempotency_key;

        Carbon::setTestNow(now()->addMinutes(6));

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

        $this->assertCount(1, $result['requeued']);
        $this->assertCount(0, $result['failed']);

        $message->refresh();

        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->status);
        $this->assertNull($message->claim_token);
        $this->assertNotNull($message->recovered_at);
        $this->assertSame($idempotencyKey, $message->provider_idempotency_key);
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);

        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'claim_token' => $firstToken,
            'status' => ScheduledMessageDeliveryAttempt::STATUS_RECOVERED,
            'reason_code' => 'stale_claim_recovered',
        ]);

        $secondClaim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        $this->assertInstanceOf(ScheduledMessage::class, $secondClaim);
        $this->assertNotSame($firstToken, $secondClaim->claim_token);
        $this->assertSame($idempotencyKey, $secondClaim->provider_idempotency_key);
        $this->assertSame(2, $secondClaim->send_attempts);
    }

    public function test_ambiguous_non_idempotent_submission_fails_instead_of_resending(): void
    {
        $message = ScheduledMessage::factory()->sms()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $claim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        $this->assertTrue(
            app(ScheduledMessageDeliveryLeaseManager::class)
                ->beginProviderSubmission($claim),
        );

        Carbon::setTestNow(now()->addMinutes(6));

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

        $this->assertCount(0, $result['requeued']);
        $this->assertCount(1, $result['failed']);

        $message->refresh();

        $this->assertSame(ScheduledMessage::STATUS_FAILED, $message->status);
        $this->assertStringContainsString(
            'automatic retry was blocked',
            (string) $message->failure_reason,
        );
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);
        $this->assertDatabaseHas('scheduled_message_delivery_attempts', [
            'scheduled_message_id' => $message->getKey(),
            'claim_token' => $claim?->claim_token,
            'status' => ScheduledMessageDeliveryAttempt::STATUS_FAILED,
            'reason_code' => 'stale_provider_submission_outcome_unknown',
        ]);
    }

    public function test_expired_provider_idempotency_window_blocks_ambiguous_retry(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $claim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        $this->assertTrue(
            app(ScheduledMessageDeliveryLeaseManager::class)
                ->beginProviderSubmission($claim),
        );

        Carbon::setTestNow(now()->addDay());

        $result = app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

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

    public function test_expired_worker_cannot_overwrite_a_later_claim_outcome(): void
    {
        $message = ScheduledMessage::factory()->email()->create([
            'meta' => ['source' => 'lease_test'],
        ]);
        $oldClaim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        Carbon::setTestNow(now()->addMinutes(6));
        app(RecoverStaleScheduledMessageClaimsAction::class)->handle();

        $newClaim = app(ClaimScheduledMessageForSendingAction::class)->handle($message);
        $manager = app(ScheduledMessageDeliveryLeaseManager::class);
        $result = MessageSendResult::sent(
            provider: 'test',
            providerMessageId: 'provider-message-1',
            meta: [
                'provider_request_id' => 'provider-request-1',
            ],
        );

        $this->assertNull($manager->complete(
            claimedMessage: $oldClaim,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        ));

        $completed = $manager->complete(
            claimedMessage: $newClaim,
            status: ScheduledMessage::STATUS_SENT,
            result: $result,
        );

        $this->assertInstanceOf(ScheduledMessage::class, $completed);

        $message->refresh();

        $this->assertSame(ScheduledMessage::STATUS_SENT, $message->status);
        $this->assertSame('test', $message->provider);
        $this->assertSame('provider-message-1', $message->provider_message_id);
        $this->assertEquals([
            'source' => 'lease_test',
        ], $message->meta);

        $sentAttempt = ScheduledMessageDeliveryAttempt::query()
            ->where('scheduled_message_id', $message->getKey())
            ->where('status', ScheduledMessageDeliveryAttempt::STATUS_SENT)
            ->sole();

        $this->assertSame('test', $sentAttempt->provider);
        $this->assertSame(
            'provider-message-1',
            $sentAttempt->provider_message_id,
        );
        $this->assertEquals([
            'provider_request_id' => 'provider-request-1',
        ], $sentAttempt->meta);
    }

    public function test_recovery_job_redispatches_recovered_pending_message(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'queue' => 'emails',
        ]);
        app(ClaimScheduledMessageForSendingAction::class)->handle($message);

        Carbon::setTestNow(now()->addMinutes(6));

        (new RecoverStaleScheduledMessageClaimsJob())->handle(
            recoverStaleClaims: app(RecoverStaleScheduledMessageClaimsAction::class),
            deliveryPolicy: app(ScheduledMessageDeliveryPolicy::class),
        );

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId
                === $message->getKey(),
        );
    }

    public function test_email_service_passes_stable_provider_idempotency_key(): void
    {
        LeaseTestEmailProvider::$idempotencyKey = null;

        config([
            'messaging.email.provider' => 'lease_test',
            'messaging.email.providers.lease_test.provider' => LeaseTestEmailProvider::class,
        ]);

        $result = app(EmailMessagingService::class)->send(
            new LeaseTestEmailMessage('delivery-key-123'),
        );

        $this->assertTrue($result->isSent());
        $this->assertSame('delivery-key-123', LeaseTestEmailProvider::$idempotencyKey);
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