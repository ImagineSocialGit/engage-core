<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastRecipientChunkAction;
use App\Modules\Broadcasts\Jobs\ScheduleBroadcastChunkJob;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastBulkSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 18:00:00 UTC');
        config()->set('messaging.bulk_delivery.chunk_size', 2);
        config()->set('messaging.bulk_delivery.release_interval_seconds', 30);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_large_broadcast_snapshots_recipients_and_queues_only_the_first_chunk(): void
    {
        Contact::factory()->count(5)->create();

        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addHour(),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldNotReceive('handle');

        $scheduled = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $this->assertSame(Broadcast::STATUS_SCHEDULED, $scheduled->status);
        $this->assertSame(5, $scheduled->recipient_count);
        $this->assertSame(0, $scheduled->scheduled_count);
        $this->assertSame(
            BroadcastRecipient::STATUS_PENDING,
            BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->distinct()
                ->value('status'),
        );
        $this->assertSame(
            5,
            BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->count(),
        );
        $this->assertSame(true, data_get($scheduled->meta, 'scheduling.bulk.enabled'));
        $this->assertSame(2, data_get($scheduled->meta, 'scheduling.bulk.chunk_size'));
        $this->assertSame(30, data_get($scheduled->meta, 'scheduling.bulk.release_interval_seconds'));
        $this->assertSame('queued', data_get($scheduled->meta, 'scheduling.state'));

        Queue::assertPushed(
            ScheduleBroadcastChunkJob::class,
            fn (ScheduleBroadcastChunkJob $job): bool =>
                $job->broadcastId === $broadcast->getKey()
                && $job->queue === QueueContract::BULK_MESSAGES
                && $job->delay instanceof \DateTimeInterface
                && Carbon::instance($job->delay)->equalTo($scheduled->send_at),
        );
        Queue::assertPushed(ScheduleBroadcastChunkJob::class, 1);
    }

    public function test_bulk_chunks_release_bounded_recipient_sets_on_staggered_times(): void
    {
        Contact::factory()->count(5)->create();

        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addHour(),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldNotReceive('handle');

        $scheduled = app(ScheduleBroadcastAction::class)->handle($broadcast);

        $sendTimes = [];
        $queues = [];
        $messageMeta = [];
        $definitionMeta = [];

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->times(5)
            ->andReturnUsing(function (
                $recipient,
                $channel,
                $purpose,
                $scope,
                $dispatchKeys,
                $payload = [],
                $context = null,
                $triggeredAt = null,
                $anchor = null,
                $meta = null,
                $criteria = [],
                $definitions = [],
                $sendAt = null,
                $behaviorOwner = null,
                $behavior = [],
                $occurrenceKey = null,
            ) use (&$sendTimes, &$queues, &$messageMeta, &$definitionMeta): array {
                $broadcast = $context;
                $sendAt = Carbon::parse($sendAt);

                $sendTimes[] = $sendAt->toDateTimeString();
                $queues[] = $definitions[0]['queue'];
                $messageMeta[] = $meta;
                $definitionMeta[] = $definitions[0]['meta'] ?? [];

                return [
                    ScheduledMessage::factory()->create([
                        'recipient_type' => $recipient->getMorphClass(),
                        'recipient_id' => $recipient->getKey(),
                        'context_type' => $broadcast->getMorphClass(),
                        'context_id' => $broadcast->getKey(),
                        'channel' => 'email',
                        'purpose' => 'marketing',
                        'scope' => 'broadcast',
                        'message_type' => 'broadcast',
                        'payload_class' => EmailPayload::class,
                        'queue' => QueueContract::BULK_MESSAGES,
                        'send_at' => $sendAt,
                    ]),
                ];
            });

        $chunkAction = app(ScheduleBroadcastRecipientChunkAction::class);
        $first = $chunkAction->handle((int) $broadcast->getKey(), bulk: true);
        $second = $chunkAction->handle((int) $broadcast->getKey(), bulk: true);
        $third = $chunkAction->handle((int) $broadcast->getKey(), bulk: true);

        $this->assertSame(2, $first['processed_count']);
        $this->assertSame(2, $second['processed_count']);
        $this->assertSame(1, $third['processed_count']);
        $this->assertTrue($first['has_more']);
        $this->assertTrue($second['has_more']);
        $this->assertFalse($third['has_more']);

        $this->assertEquals([
            '2026-08-18 19:00:00',
            '2026-08-18 19:00:00',
            '2026-08-18 19:00:30',
            '2026-08-18 19:00:30',
            '2026-08-18 19:01:00',
        ], $sendTimes);
        $this->assertEquals([
            QueueContract::BULK_MESSAGES,
            QueueContract::BULK_MESSAGES,
            QueueContract::BULK_MESSAGES,
            QueueContract::BULK_MESSAGES,
            QueueContract::BULK_MESSAGES,
        ], $queues);

        foreach ($messageMeta as $meta) {
            $this->assertArrayNotHasKey('bulk_delivery', $meta);
        }

        foreach ($definitionMeta as $meta) {
            $this->assertArrayNotHasKey('bulk_delivery', $meta);
        }

        $scheduled->refresh();
        $this->assertSame(5, $scheduled->scheduled_count);
        $this->assertSame('complete', data_get($scheduled->meta, 'scheduling.state'));
        $this->assertSame(
            5,
            BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->where('status', BroadcastRecipient::STATUS_SCHEDULED)
                ->count(),
        );
    }

    public function test_chunk_job_releases_only_one_followup_chunk_after_the_snapshot_interval(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create([
            'meta' => [
                'scheduling' => [
                    'bulk' => [
                        'enabled' => true,
                        'queue' => QueueContract::BULK_MESSAGES,
                        'chunk_size' => 2,
                        'release_interval_seconds' => 30,
                    ],
                ],
            ],
        ]);

        $scheduleChunk = $this->mock(ScheduleBroadcastRecipientChunkAction::class);
        $scheduleChunk
            ->shouldReceive('handle')
            ->once()
            ->with((int) $broadcast->getKey(), true)
            ->andReturn([
                'processed_count' => 2,
                'scheduled_count' => 2,
                'skipped_count' => 0,
                'has_more' => true,
                'release_at' => now(),
            ]);

        (new ScheduleBroadcastChunkJob((int) $broadcast->getKey()))->handle(
            $scheduleChunk,
            app(BulkMessageDeliveryPolicy::class),
        );

        Queue::assertPushed(
            ScheduleBroadcastChunkJob::class,
            fn (ScheduleBroadcastChunkJob $job): bool =>
                $job->broadcastId === $broadcast->getKey()
                && $job->queue === QueueContract::BULK_MESSAGES
                && $job->delay instanceof \DateTimeInterface
                && Carbon::instance($job->delay)->equalTo(now()->addSeconds(30)),
        );
        Queue::assertPushed(ScheduleBroadcastChunkJob::class, 1);
    }

    public function test_cancelled_bulk_broadcast_leaves_delayed_chunk_jobs_as_no_ops(): void
    {
        Contact::factory()->count(3)->create();

        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addHour(),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldNotReceive('handle');

        app(ScheduleBroadcastAction::class)->handle($broadcast);
        app(CancelBroadcastAction::class)->handle($broadcast);

        $result = app(ScheduleBroadcastRecipientChunkAction::class)->handle(
            (int) $broadcast->getKey(),
            bulk: true,
        );

        $this->assertSame(0, $result['processed_count']);
        $this->assertFalse($result['has_more']);
        $this->assertSame(
            3,
            BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->where('status', BroadcastRecipient::STATUS_CANCELLED)
                ->count(),
        );
    }
}