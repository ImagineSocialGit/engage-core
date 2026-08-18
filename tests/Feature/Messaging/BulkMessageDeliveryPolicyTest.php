<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use App\Support\Queues\QueueContract;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BulkMessageDeliveryPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_bulk_policy_uses_configurable_bounded_chunks_and_release_intervals(): void
    {
        config()->set('messaging.bulk_delivery.chunk_size', 25);
        config()->set('messaging.bulk_delivery.release_interval_seconds', 20);
        Carbon::setTestNow('2026-08-18 18:00:00 UTC');

        $policy = app(BulkMessageDeliveryPolicy::class);

        $this->assertSame(25, $policy->chunkSize());
        $this->assertSame(20, $policy->releaseIntervalSeconds());
        $this->assertFalse($policy->shouldChunk(25));
        $this->assertTrue($policy->shouldChunk(26));
        $this->assertSame(QueueContract::BULK_MESSAGES, $policy->queue());
        $this->assertSame(
            '2026-08-18 19:00:40',
            $policy->releaseAt(
                '2026-08-18 19:00:00 UTC',
                2,
            )->toDateTimeString(),
        );
    }

    public function test_horizon_isolates_bulk_messages_from_the_primary_supervisor(): void
    {
        $primaryQueues = config('horizon.environments.local.supervisor-1.queue', []);
        $bulkQueues = config('horizon.environments.local.supervisor-bulk.queue', []);

        $this->assertIsArray($primaryQueues);
        $this->assertIsArray($bulkQueues);
        $this->assertNotContains(QueueContract::BULK_MESSAGES, $primaryQueues);
        $this->assertSame([QueueContract::BULK_MESSAGES], $bulkQueues);
        $this->assertContains(QueueContract::BULK_MESSAGES, QueueContract::QUEUES);
        $this->assertArrayHasKey(
            QueueContract::BULK_MESSAGES,
            config('reference.keys.queues', []),
        );
    }
}