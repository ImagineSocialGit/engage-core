<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\RecoverInterruptedBroadcastChunksAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastRecipientChunkAction;
use App\Modules\Broadcasts\Jobs\RecoverInterruptedBroadcastChunksJob;
use App\Modules\Broadcasts\Jobs\ScheduleBroadcastChunkJob;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Services\BroadcastChunkReleaseSchedule;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class InterruptedBroadcastChunkRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-23 12:00:00 UTC');
        config()->set('messaging.bulk_delivery.chunk_size', 2);
        config()->set('messaging.bulk_delivery.release_interval_seconds', 30);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_lost_initial_redis_job_is_reclaimed_from_snapshot_and_duplicate_jobs_cannot_accelerate_chunks(): void
    {
        $broadcast = $this->scheduleBroadcast(5);
        $pinnedVersion = (int) $broadcast->message_template_version_id;

        Queue::assertPushed(ScheduleBroadcastChunkJob::class, 1);

        // Simulate Redis being cleared while the original first chunk is queued.
        Queue::fake();
        Carbon::setTestNow($broadcast->send_at->copy()->addSeconds(91));

        $recovery = app(RecoverInterruptedBroadcastChunksAction::class);
        $this->assertSame([
            [
                'broadcast_id' => (int) $broadcast->getKey(),
                'queue' => QueueContract::BULK_MESSAGES,
            ],
        ], $recovery->handle());
        $this->assertSame([], $recovery->handle());

        $this->fakeScheduledMessageCreation(5);
        $this->runChunkJob($broadcast);
        $this->assertSame(2, (int) $broadcast->fresh()->scheduled_count);
        $this->assertPendingCount($broadcast, 3);
        Queue::assertPushed(ScheduleBroadcastChunkJob::class, 1);

        // A stale copy of the recovered job is safe: the next release has
        // not arrived, so no second chunk is scheduled or enqueued.
        $this->runChunkJob($broadcast);
        $this->assertSame(2, (int) $broadcast->fresh()->scheduled_count);
        $this->assertPendingCount($broadcast, 3);
        Queue::assertPushed(ScheduleBroadcastChunkJob::class, 1);

        Carbon::setTestNow(now()->addSeconds(29));
        $this->runChunkJob($broadcast);
        $this->assertSame(2, (int) $broadcast->fresh()->scheduled_count);

        Carbon::setTestNow(now()->addSecond());
        $this->runChunkJob($broadcast);
        $this->assertSame(4, (int) $broadcast->fresh()->scheduled_count);
        $this->assertPendingCount($broadcast, 1);
        $this->assertSame(
            $pinnedVersion,
            (int) $broadcast->fresh()->message_template_version_id,
        );

        Carbon::setTestNow(now()->addSeconds(30));
        $this->runChunkJob($broadcast);
        $this->assertSame(5, (int) $broadcast->fresh()->scheduled_count);
        $this->assertPendingCount($broadcast, 0);
        $this->assertSame('complete', data_get($broadcast->fresh()->meta, 'scheduling.state'));
        $this->assertSame([], $recovery->handle());
    }

    public function test_lost_successor_is_requeued_only_after_its_original_release_and_grace(): void
    {
        $broadcast = $this->scheduleBroadcast(3);
        $this->fakeScheduledMessageCreation(2);

        Carbon::setTestNow($broadcast->send_at);
        $this->runChunkJob($broadcast);
        $this->assertSame(2, (int) $broadcast->fresh()->scheduled_count);

        Queue::fake();
        $recovery = app(RecoverInterruptedBroadcastChunksAction::class);

        Carbon::setTestNow(now()->addSeconds(119));
        $this->assertSame([], $recovery->handle());

        Carbon::setTestNow(now()->addSecond());

        // The recovery job uses real database claims, not a mocked queue-only
        // assertion. A lost successor is reconstructed on its pinned queue.
        app(RecoverInterruptedBroadcastChunksJob::class)->handle($recovery);
        $this->assertSame(
            1,
            (int) data_get($broadcast->fresh()->meta, 'scheduling.recovery.claimed_chunk_index'),
        );
        Queue::assertPushed(
            ScheduleBroadcastChunkJob::class,
            fn (ScheduleBroadcastChunkJob $job): bool =>
                $job->broadcastId === $broadcast->getKey()
                && $job->queue === QueueContract::BULK_MESSAGES,
        );
    }

    public function test_durable_recovery_claim_expires_if_the_reconstructed_job_is_also_lost(): void
    {
        $broadcast = $this->scheduleBroadcast(3);
        Carbon::setTestNow($broadcast->send_at->copy()->addSeconds(91));
        $recovery = app(RecoverInterruptedBroadcastChunksAction::class);

        $this->assertCount(1, $recovery->handle());
        $this->assertSame([], $recovery->handle());

        Carbon::setTestNow(now()->addSeconds(181));
        $this->assertCount(1, $recovery->handle());
        $this->assertPendingCount($broadcast, 3);
    }

    public function test_cancellation_and_operator_stalled_state_block_recovery_and_leftover_jobs(): void
    {
        $cancelled = $this->scheduleBroadcast(3);
        $stalled = $this->scheduleBroadcast(3);

        $stalled->forceFill([
            'meta' => array_replace_recursive($stalled->meta ?? [], [
                'scheduling' => ['state' => 'stalled'],
            ]),
        ])->save();

        app(CancelBroadcastAction::class)->handle($cancelled);
        Carbon::setTestNow($cancelled->send_at->copy()->addMinutes(10));

        $this->assertSame([], app(RecoverInterruptedBroadcastChunksAction::class)->handle());
        $this->mock(DispatchMessageAction::class)->shouldNotReceive('handle');
        $this->runChunkJob($cancelled);
        $this->runChunkJob($stalled);
        $this->assertSame(0, (int) $stalled->fresh()->scheduled_count);
        $this->assertSame(Broadcast::STATUS_CANCELLED, $cancelled->fresh()->status);
    }

    public function test_non_bulk_broadcasts_are_never_reconstructed(): void
    {
        Contact::factory()->create();
        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addMinutes(10),
            'recipient_filter' => ['type' => 'all'],
        ]);

        app(ScheduleBroadcastAction::class)->handle($broadcast);
        Carbon::setTestNow($broadcast->fresh()->send_at->copy()->addMinutes(10));

        $this->assertSame([], app(RecoverInterruptedBroadcastChunksAction::class)->handle());
    }

    public function test_disabled_module_does_not_recover_or_execute_existing_bulk_jobs(): void
    {
        $broadcast = $this->scheduleBroadcast(3);
        Carbon::setTestNow($broadcast->send_at->copy()->addMinutes(5));
        config()->set('modules.enabled', array_values(array_diff(
            config('modules.enabled', []),
            ['broadcasts'],
        )));

        $this->mock(DispatchMessageAction::class)->shouldNotReceive('handle');
        $this->assertSame([], app(RecoverInterruptedBroadcastChunksAction::class)->handle());
        $this->runChunkJob($broadcast);
        $this->assertSame(0, (int) $broadcast->fresh()->scheduled_count);
        $this->assertPendingCount($broadcast, 3);
    }

    private function scheduleBroadcast(int $contactCount): Broadcast
    {
        Contact::factory()->count($contactCount)->create();

        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addMinutes(10),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $this->mock(DispatchMessageAction::class)->shouldNotReceive('handle');

        return app(ScheduleBroadcastAction::class)->handle($broadcast);
    }

    private function runChunkJob(Broadcast $broadcast): void
    {
        (new ScheduleBroadcastChunkJob((int) $broadcast->getKey()))->handle(
            app(ScheduleBroadcastRecipientChunkAction::class),
            app(BroadcastChunkReleaseSchedule::class),
        );
    }

    private function assertPendingCount(Broadcast $broadcast, int $count): void
    {
        $this->assertSame($count, BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->getKey())
            ->where('status', BroadcastRecipient::STATUS_PENDING)
            ->count());
    }

    private function fakeScheduledMessageCreation(int $count): void
    {
        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->times($count)
            ->andReturnUsing(function (...$arguments): array {
                $contact = $arguments['recipient'] ?? $arguments[0];
                $broadcast = $arguments['context'] ?? $arguments[6];
                $sendAt = $arguments['sendAt'] ?? $arguments[12] ?? null;

                return [ScheduledMessage::factory()->create([
                    'recipient_type' => $contact->getMorphClass(),
                    'recipient_id' => $contact->getKey(),
                    'context_type' => $broadcast->getMorphClass(),
                    'context_id' => $broadcast->getKey(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'broadcast',
                    'message_type' => 'broadcast',
                    'payload_class' => EmailPayload::class,
                    'queue' => QueueContract::BULK_MESSAGES,
                    'send_at' => $sendAt,
                ])];
            });
    }
}