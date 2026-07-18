<?php

namespace Tests\Feature\Support\AutomationEvents;

use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AutomationEventOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_listener_failure_leaves_a_retryable_event_and_retry_reuses_its_identity(): void
    {
        $fail = true;
        $seenEventIds = [];

        Event::listen(AutomationEventRecorded::class, function (AutomationEventRecorded $recorded) use (&$fail, &$seenEventIds): void {
            $seenEventIds[] = $recorded->event->eventId;

            if ($fail) {
                throw new RuntimeException('Simulated automation listener outage.');
            }
        });

        $outbox = app(AutomationEventOutbox::class);
        $row = DB::transaction(fn (): AutomationEventOutboxEvent => $outbox->record(
            event: AutomationEventData::make('test.retryable'),
            idempotencyKey: 'test:retryable:1',
        ));

        $row->refresh();

        if ($row->attempts === 0) {
            $outbox->publish((int) $row->getKey());
            $row->refresh();
        }

        $this->assertSame(AutomationEventOutboxEvent::STATUS_PENDING, $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertSame('Simulated automation listener outage.', $row->last_error);

        $fail = false;
        $row->forceFill(['available_at' => now()])->save();

        $this->assertTrue($outbox->publish((int) $row->getKey()));

        $row->refresh();

        $this->assertSame(AutomationEventOutboxEvent::STATUS_PUBLISHED, $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertNotNull($row->published_at);
        $this->assertNotEmpty($seenEventIds);
        $this->assertCount(1, array_unique($seenEventIds));
        $this->assertSame($row->event_id, $seenEventIds[0]);
    }

    public function test_rolled_back_domain_transaction_does_not_leave_an_outbox_event(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        try {
            DB::transaction(function (): void {
                app(AutomationEventOutbox::class)->record(
                    event: AutomationEventData::make('test.rolled_back'),
                    idempotencyKey: 'test:rolled-back:1',
                );

                throw new RuntimeException('Rollback the owning domain change.');
            });

            $this->fail('The transaction should have rolled back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Rollback the owning domain change.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('automation_event_outbox_events', [
            'idempotency_key' => 'test:rolled-back:1',
        ]);
        Event::assertNotDispatched(AutomationEventRecorded::class);
    }

    public function test_idempotency_key_returns_one_durable_event(): void
    {
        Event::fake([AutomationEventRecorded::class]);

        $outbox = app(AutomationEventOutbox::class);
        $event = AutomationEventData::make('test.idempotent');

        $first = $outbox->record($event, 'test:idempotent:1');
        $second = $outbox->record($event, 'test:idempotent:1');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($first->event_id, $second->event_id);
        $this->assertDatabaseCount('automation_event_outbox_events', 1);
    }
}