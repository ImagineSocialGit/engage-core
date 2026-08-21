<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Jobs\ProcessDueMessageChainEnrollmentsJob;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Services\BulkMessageDeliveryPolicy;
use App\Support\Queues\QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MessageChainBulkDrainTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_scanner_uses_generic_bulk_chunk_limit_and_bulk_queue(): void
    {
        Queue::fake();
        config()->set('messaging.bulk_delivery.chunk_size', 2);

        $chain = MessageChain::query()->create([
            'key' => 'bulk-drain-test',
            'name' => 'Bulk drain test',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'bulk-drain-test'),
            'published_at' => now(),
        ]);

        foreach (range(1, 3) as $index) {
            $contact = Contact::factory()->create();

            MessageChainEnrollment::query()->create([
                'message_chain_version_id' => $version->getKey(),
                'recipient_type' => $contact->getMorphClass(),
                'recipient_id' => $contact->getKey(),
                'surface' => 'campaigns',
                'current_message_chain_step_id' => null,
                'next_action_at' => now()->subMinute(),
                'status' => MessageChainEnrollment::STATUS_ACTIVE,
                'dedupe_key' => 'bulk-drain-'.$index,
                'started_at' => now()->subMinutes(5),
            ]);
        }

        app(ProcessDueMessageChainEnrollmentsJob::class)->handle(
            app(BulkMessageDeliveryPolicy::class),
        );

        Queue::assertPushed(ProcessMessageChainEnrollmentJob::class, 2);
        Queue::assertPushedOn(QueueContract::BULK_MESSAGES, ProcessMessageChainEnrollmentJob::class);
    }
}