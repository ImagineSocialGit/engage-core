<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ClaimOverduePendingScheduledMessagesForRecoveryAction;
use App\Modules\Messaging\Actions\ClaimScheduledMessageForSendingAction;
use App\Modules\Messaging\Jobs\RecoverOverduePendingScheduledMessagesJob;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Models\ScheduledMessageDeliveryAttempt;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class OverduePendingScheduledMessageRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 18:00:00');

        config([
            'app.env' => 'local',
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'horizon.defaults.supervisor-1.connection' => 'redis',
            'horizon.environments.local.supervisor-1.queue' => QueueContract::QUEUES,
            'messaging.delivery.pending_message_overdue_grace_seconds' => 300,
            'messaging.delivery.pending_recovery_batch_size' => 100,
            'messaging.delivery.pending_recovery_retry_seconds' => 300,
            'messaging.email.provider' => 'resend',
            'messaging.delivery.provider_idempotency.email.resend' => [
                'enabled' => true,
                'safe_retry_window_seconds' => 82800,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_redis_queue_loss_is_repaired_from_the_pending_database_row(): void
    {
        Queue::fake();

        // No original queue job survives; only the database record remains.
        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
        ]);

        $this->recover();

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId === $message->getKey()
                && $job->queue === 'emails',
        );
        $this->assertNotNull($message->fresh()->pending_recovery_claimed_at);

        // A second sweeper cannot queue the same row during its durable lease.
        $this->recover();
        Queue::assertPushed(SendScheduledMessageJob::class, 1);

        // Only one delivery worker can acquire the provider submission claim.
        $firstAttempt = app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message);
        $this->assertInstanceOf(ScheduledMessageDeliveryAttempt::class, $firstAttempt);
        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message));
        $this->recover();
        Queue::assertPushed(SendScheduledMessageJob::class, 1);
    }

    public function test_a_lost_redispatch_can_be_repaired_after_its_durable_claim_expires(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
        ]);

        $this->recover();
        Queue::assertPushed(SendScheduledMessageJob::class, 1);

        // Simulate a Redis flush before either queued job reaches a worker.
        Carbon::setTestNow(now()->addMinutes(6));
        $this->recover();

        Queue::assertPushed(SendScheduledMessageJob::class, 2);
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->fresh()->status);
    }

    public function test_recovery_is_bounded_and_skips_ineligible_or_live_claimed_messages(): void
    {
        Queue::fake();
        config(['messaging.delivery.pending_recovery_batch_size' => 1]);

        $oldest = ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(11),
        ]);
        $second = ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(10),
        ]);
        ScheduledMessage::factory()->create([
            'send_at' => now()->addMinutes(10),
        ]);
        ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(10),
            'operational_state' => ScheduledMessage::OPERATIONAL_HELD,
        ]);
        ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(10),
            'operational_state' => ScheduledMessage::OPERATIONAL_CANCELLED,
        ]);
        ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(10),
            'status' => ScheduledMessage::STATUS_SENT,
        ]);

        $this->recover();
        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId === $oldest->getKey(),
        );
        Queue::assertPushed(SendScheduledMessageJob::class, 1);

        $this->recover();
        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId === $second->getKey(),
        );
        Queue::assertPushed(SendScheduledMessageJob::class, 2);

        $live = ScheduledMessage::factory()->create([
            'send_at' => now()->subMinutes(10),
        ]);
        $this->activeAttempt($live);
        $this->recover();
        Queue::assertPushed(SendScheduledMessageJob::class, 2);
    }

    public function test_recovery_never_dispatches_to_an_invalid_stored_queue(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'queue' => 'obsolete_queue',
            'send_at' => now()->subMinutes(10),
        ]);
        Log::spy();

        $this->recover();

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->fresh()->status);
        $this->assertNotNull($message->fresh()->pending_recovery_claimed_at);
        Log::shouldHaveReceived('critical')->once()->with(
            'Overdue pending ScheduledMessage could not be redispatched.',
            \Mockery::on(fn (array $context): bool =>
                ($context['scheduled_message_id'] ?? null) === $message->getKey()
                && ($context['queue'] ?? null) === 'obsolete_queue'),
        );
    }

    public function test_prior_provider_submission_inside_idempotency_window_can_be_retried(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
            'provider_idempotency_key' => 'recovery-safe-idempotency-key',
        ]);
        $this->releasedProviderAttempt($message, now()->subMinutes(10));

        $this->recover();

        Queue::assertPushed(
            SendScheduledMessageJob::class,
            fn (SendScheduledMessageJob $job): bool => $job->scheduledMessageId === $message->getKey(),
        );
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->fresh()->status);
    }

    public function test_expired_provider_idempotency_does_not_cause_an_unsafe_automatic_resend(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subDay(),
        ]);
        $originalAttempt = $this->releasedProviderAttempt($message, now()->subDay());

        Log::spy();
        $this->recover();

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $message->fresh()->status);
        $this->assertSame(
            ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
            $originalAttempt->fresh()->status,
        );
        $terminalAttempt = $message->latestDeliveryAttempt()->firstOrFail();
        $this->assertSame(ScheduledMessageDeliveryAttempt::STATUS_FAILED, $terminalAttempt->status);
        $this->assertSame('pending_recovery_provider_outcome_unknown', $terminalAttempt->reason_code);
        $event = $message->terminalOutboxEvent()->firstOrFail();
        $this->assertSame((int) $terminalAttempt->getKey(), (int) $event->delivery_attempt_id);
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $event->event_type);

        $this->recover();
        Queue::assertNotPushed(SendScheduledMessageJob::class);
    }

    public function test_delayed_delivery_job_cannot_claim_after_provider_window_expires(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
            'provider_idempotency_key' => 'recovery-delayed-idempotency-key',
        ]);
        $this->releasedProviderAttempt($message, now()->subMinutes(10));

        $this->recover();
        Queue::assertPushed(SendScheduledMessageJob::class, 1);

        Carbon::setTestNow(now()->addDay());

        $this->assertNull(app(ClaimScheduledMessageForSendingAction::class)
            ->handle($message));
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->fresh()->status);

        $this->recover();
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $message->fresh()->status);
    }

    public function test_prior_provider_submission_without_stable_key_cannot_be_redispatched(): void
    {
        Queue::fake();

        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
            'provider_idempotency_key' => null,
        ]);
        $this->releasedProviderAttempt($message, now()->subMinutes(10));

        $this->recover();

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        $this->assertSame(ScheduledMessage::STATUS_FAILED, $message->fresh()->status);
    }

    public function test_testing_surface_message_is_not_recovered_by_background_workers(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create();
        $chain = MessageChain::query()->create([
            'key' => 'test.pending.recovery.isolation',
            'name' => 'Pending Recovery Isolation',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'content_hash' => hash('sha256', 'pending-recovery-isolation'),
            'published_at' => now()->subHour(),
        ]);
        $chain->forceFill(['current_version_id' => $version->getKey()])->save();

        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => $contact->getMorphClass(),
            'context_id' => $contact->getKey(),
            'origin_type' => $contact->getMorphClass(),
            'origin_id' => $contact->getKey(),
            'surface' => 'testing:fixture',
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'testing:pending-recovery:'.$contact->getKey(),
            'started_at' => now()->subHour(),
            'next_action_at' => now()->subMinutes(30),
        ]);

        $message = ScheduledMessage::factory()->forContact($contact)->create([
            'message_chain_enrollment_id' => $enrollment->getKey(),
            'send_at' => now()->subMinutes(10),
        ]);

        $this->recover();

        Queue::assertNotPushed(SendScheduledMessageJob::class);
        $this->assertNull($message->fresh()->pending_recovery_claimed_at);
    }

    public function test_redispatch_can_write_to_a_real_queue_backend_without_queue_fake(): void
    {
        config(['queue.default' => 'database']);
        $message = ScheduledMessage::factory()->email()->create([
            'send_at' => now()->subMinutes(10),
        ]);

        $this->recover();

        $this->assertSame(1, Queue::connection('database')->size('emails'));
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $message->fresh()->status);
    }

    private function recover(): void
    {
        app(RecoverOverduePendingScheduledMessagesJob::class)->handle(
            app(ClaimOverduePendingScheduledMessagesForRecoveryAction::class),
            app(QueueContract::class),
        );
    }

    private function activeAttempt(ScheduledMessage $message): void
    {
        $message->deliveryAttempts()->create([
            'attempt_number' => 1,
            'claim_token' => (string) Str::uuid(),
            'status' => ScheduledMessageDeliveryAttempt::STATUS_CLAIMED,
            'claimed_at' => now()->subMinutes(5),
            'lease_expires_at' => now()->addMinutes(5),
        ]);
    }

    private function releasedProviderAttempt(
        ScheduledMessage $message,
        Carbon $startedAt,
    ): ScheduledMessageDeliveryAttempt {
        return $message->deliveryAttempts()->create([
            'attempt_number' => 1,
            'claim_token' => (string) Str::uuid(),
            'status' => ScheduledMessageDeliveryAttempt::STATUS_RELEASED,
            'claimed_at' => $startedAt->copy(),
            'lease_expires_at' => $startedAt->copy()->addMinute(),
            'provider_submission_started_at' => $startedAt->copy(),
            'completed_at' => $startedAt->copy()->addMinute(),
            'reason_code' => 'message_delivery_retryable_exception',
        ]);
    }
}