<?php

namespace Tests\Feature\Broadcasts;

use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastRecipientChunkAction;
use App\Modules\Broadcasts\Listeners\MarkBroadcastRecipientSent;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Support\Queues\QueueContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BroadcastRecipientReadAmplificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-30 12:00:00 UTC');
        config()->set('messaging.bulk_delivery.chunk_size', 2);
        config()->set('messaging.bulk_delivery.release_interval_seconds', 30);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_terminal_completion_check_forces_the_broadcast_status_index(): void
    {
        $broadcast = Broadcast::factory()->scheduled()->create();
        $contact = Contact::factory()->create();

        BroadcastRecipient::factory()->scheduled()->create([
            'broadcast_id' => $broadcast->id,
            'contact_id' => $contact->id,
        ]);

        BroadcastRecipient::factory()->scheduled()->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $scheduledMessage = ScheduledMessage::factory()->sent()->create([
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->id,
            'context_type' => $broadcast->getMorphClass(),
            'context_id' => $broadcast->id,
        ]);

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'bindings' => $query->bindings,
            ];
        });

        app(MarkBroadcastRecipientSent::class)->handle(
            new ScheduledMessageSent($scheduledMessage),
        );

        $completionQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'broadcast_recipients')
                && str_contains($query['sql'], 'force index')
                && str_contains(
                    $query['sql'],
                    'broadcast_recipients_broadcast_id_status_index',
                )
                && in_array(BroadcastRecipient::STATUS_PENDING, $query['bindings'], true)
                && in_array(BroadcastRecipient::STATUS_SCHEDULED, $query['bindings'], true),
        ));

        $this->assertCount(1, $completionQueries);
        $this->assertSame(Broadcast::STATUS_SCHEDULED, $broadcast->fresh()->status);
    }

    public function test_bulk_chunk_progression_uses_locked_metadata_without_recipient_counts(): void
    {
        Contact::factory()->count(3)->create();

        $broadcast = Broadcast::factory()->withMessage()->create([
            'send_at' => now()->addHour(),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $this->mock(DispatchMessageAction::class)
            ->shouldNotReceive('handle');

        app(ScheduleBroadcastAction::class)->handle($broadcast);

        $sendTimes = [];

        $this->mock(DispatchMessageAction::class)
            ->shouldReceive('handle')
            ->times(3)
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
            ) use (&$sendTimes): array {
                $broadcast = $context;
                $sendAt = Carbon::parse($sendAt);
                $sendTimes[] = $sendAt->toDateTimeString();

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

        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = [
                'sql' => strtolower($query->sql),
                'bindings' => $query->bindings,
            ];
        });

        $chunkAction = app(ScheduleBroadcastRecipientChunkAction::class);
        $first = $chunkAction->handle((int) $broadcast->getKey(), bulk: true);
        $second = $chunkAction->handle((int) $broadcast->getKey(), bulk: true);

        $this->assertSame(2, $first['processed_count']);
        $this->assertTrue($first['has_more']);
        $this->assertSame(1, $second['processed_count']);
        $this->assertFalse($second['has_more']);
        $this->assertEquals([
            '2026-08-30 13:00:00',
            '2026-08-30 13:00:00',
            '2026-08-30 13:00:30',
        ], $sendTimes);

        $broadcast->refresh();

        $this->assertSame(1, data_get($broadcast->meta, 'scheduling.last_chunk_index'));

        $processedCountQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains(
                $query['sql'],
                'count(*) as aggregate',
            )
                && str_contains($query['sql'], 'from `broadcast_recipients`')
                && in_array(BroadcastRecipient::STATUS_PENDING, $query['bindings'], true),
        ));

        $this->assertCount(0, $processedCountQueries);
    }
}