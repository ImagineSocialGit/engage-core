<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Jobs\EmitContactImportAutomationEventsJob;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Services\Contacts\RecoverStalledContactImportAutomationEvents;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class ContactImportAutomationEventRedisRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Event::fake([AutomationEventRecorded::class]);

        config()->set('contact_imports.automation_events_recovery.stale_after_seconds', 300);
        config()->set('contact_imports.automation_events_recovery.redispatch_after_seconds', 300);
        config()->set('contact_imports.automation_events_recovery.max_per_run', 2);
    }

    public function test_lost_first_emitter_is_recovered_and_duplicate_execution_does_not_repeat_events(): void
    {
        $batch = $this->completedBatchWithOccurrences(2);
        $recovery = app(RecoverStalledContactImportAutomationEvents::class);

        $this->assertSame(0, $recovery->recover());
        $this->ageBatch($batch);

        $this->assertSame(1, $recovery->recover());
        $this->assertSame(0, $recovery->recover());
        Queue::assertPushed(EmitContactImportAutomationEventsJob::class, 1);

        $job = new EmitContactImportAutomationEventsJob($batch->getKey());
        $job->handle(app(AutomationEventOutbox::class));

        $batch->refresh();
        $this->assertNotNull($batch->automation_events_completed_at);
        $lastOccurrenceId = (int) ContactImportOccurrence::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->max('id');
        $this->assertSame($lastOccurrenceId, (int) $batch->automation_events_cursor);
        $this->assertSame(2, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());

        $job->handle(app(AutomationEventOutbox::class));
        $this->ageBatch($batch);

        $this->assertSame(0, $recovery->recover());
        $this->assertSame(2, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());
    }

    public function test_lost_continuation_resumes_after_durable_cursor_even_with_stale_job_offset(): void
    {
        // Ensure this batch does not start at occurrence ID 1. A cursor is
        // an occurrence primary key, not a count of emitted events.
        $this->completedBatchWithOccurrences(2, null);
        $batch = $this->completedBatchWithOccurrences(251);

        (new EmitContactImportAutomationEventsJob($batch->getKey()))
            ->handle(app(AutomationEventOutbox::class));

        $batch->refresh();
        $twoHundredFiftiethOccurrenceId = (int) ContactImportOccurrence::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->orderBy('id')
            ->skip(249)
            ->firstOrFail()
            ->getKey();
        $this->assertSame($twoHundredFiftiethOccurrenceId, (int) $batch->automation_events_cursor);
        $this->assertNull($batch->automation_events_completed_at);
        $this->assertSame(250, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());
        Queue::assertPushed(EmitContactImportAutomationEventsJob::class, 1);

        // Redis discarded the queued next chunk; only MySQL state survives.
        Queue::fake();
        $this->ageBatch($batch);
        $this->assertSame(1, app(RecoverStalledContactImportAutomationEvents::class)->recover());
        Queue::assertPushed(EmitContactImportAutomationEventsJob::class, 1);

        // A stale job's caller-supplied offset must never skip row 251.
        (new EmitContactImportAutomationEventsJob($batch->getKey(), 999999))
            ->handle(app(AutomationEventOutbox::class));

        $batch->refresh();
        $lastOccurrenceId = (int) ContactImportOccurrence::query()
            ->where('contact_import_batch_id', $batch->getKey())->max('id');
        $this->assertSame($lastOccurrenceId, (int) $batch->automation_events_cursor);
        $this->assertNotNull($batch->automation_events_completed_at);
        $this->assertSame(251, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());

        (new EmitContactImportAutomationEventsJob($batch->getKey(), 250))
            ->handle(app(AutomationEventOutbox::class));
        $this->assertSame(251, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());
    }

    public function test_recovery_is_bounded_and_claim_retries_without_new_progress(): void
    {
        $first = $this->completedBatchWithOccurrences(1);
        $second = $this->completedBatchWithOccurrences(1);
        $third = $this->completedBatchWithOccurrences(1);

        foreach ([$first, $second, $third] as $batch) {
            $this->ageBatch($batch);
        }

        $recovery = app(RecoverStalledContactImportAutomationEvents::class);
        $this->assertSame(2, $recovery->recover());
        $this->assertSame(1, $recovery->recover());
        $this->assertSame(0, $recovery->recover());
        Queue::assertPushed(EmitContactImportAutomationEventsJob::class, 3);

        DB::table('contact_import_batches')
            ->where('id', $first->getKey())
            ->update([
                'updated_at' => now()->subMinutes(10),
                'automation_events_recovery_dispatched_at' => now()->subMinutes(10),
            ]);

        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(EmitContactImportAutomationEventsJob::class, 4);
    }

    public function test_old_completed_imports_and_noncompleted_imports_are_not_implicitly_replayed(): void
    {
        $legacy = $this->completedBatchWithOccurrences(1, null);
        $processing = $this->completedBatchWithOccurrences(1);
        $processing->forceFill(['status' => ContactImportBatch::STATUS_PROCESSING])->save();
        $this->ageBatch($legacy);
        $this->ageBatch($processing);

        $this->assertSame(0, app(RecoverStalledContactImportAutomationEvents::class)->recover());
        Queue::assertNothingPushed();

        // A previously queued legacy job still works after deployment.
        (new EmitContactImportAutomationEventsJob($legacy->getKey()))
            ->handle(app(AutomationEventOutbox::class));

        $this->assertSame(1, AutomationEventOutboxEvent::query()->where('event_key', 'contact.imported')->count());
        $this->assertNull($legacy->fresh()->automation_events_cursor);
    }

    public function test_failed_outbox_chunk_rolls_back_both_events_and_the_cursor(): void
    {
        $batch = $this->completedBatchWithOccurrences(2);

        $failingOutbox = new class extends AutomationEventOutbox {
            private int $records = 0;

            public function record(
                AutomationEventData $event,
                ?string $idempotencyKey = null,
            ): AutomationEventOutboxEvent {
                $recorded = parent::record($event, $idempotencyKey);

                if (++$this->records === 2) {
                    throw new RuntimeException('Injected outbox failure');
                }

                return $recorded;
            }
        };

        try {
            (new EmitContactImportAutomationEventsJob($batch->getKey()))
                ->handle($failingOutbox);
            $this->fail('The second event should abort the chunk transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected outbox failure', $exception->getMessage());
        }

        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
        $this->assertSame(0, (int) $batch->fresh()->automation_events_cursor);
        $this->assertNull($batch->fresh()->automation_events_completed_at);

        (new EmitContactImportAutomationEventsJob($batch->getKey()))
            ->handle(app(AutomationEventOutbox::class));
        $this->assertSame(2, AutomationEventOutboxEvent::query()->count());
        $this->assertNotNull($batch->fresh()->automation_events_completed_at);
    }

    public function test_empty_completed_imports_finish_reconciliation_without_events(): void
    {
        $batch = $this->completedBatchWithOccurrences(0);

        (new EmitContactImportAutomationEventsJob($batch->getKey()))
            ->handle(app(AutomationEventOutbox::class));

        $this->assertNotNull($batch->fresh()->automation_events_completed_at);
        $this->assertSame(0, AutomationEventOutboxEvent::query()->count());
    }

    private function ageBatch(ContactImportBatch $batch): void
    {
        DB::table('contact_import_batches')
            ->where('id', $batch->getKey())
            ->update(['updated_at' => now()->subMinutes(10)]);
    }

    private function completedBatchWithOccurrences(int $count, ?int $cursor = 0): ContactImportBatch
    {
        $batch = ContactImportBatch::factory()->create([
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'automation_events_cursor' => $cursor,
            'automation_events_completed_at' => null,
            'automation_events_recovery_dispatched_at' => null,
        ]);

        if ($count === 0) {
            return $batch;
        }

        $contact = Contact::factory()->create();
        $rows = [];

        for ($number = 1; $number <= $count; $number++) {
            $rows[] = [
                'contact_import_batch_id' => $batch->getKey(),
                'contact_id' => $contact->getKey(),
                'row_number' => $number + 1,
                'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
                'identity_type' => 'email',
                'identity_value' => $contact->email,
                'row_fingerprint' => hash('sha256', $contact->email.':'.$number),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        ContactImportOccurrence::query()->insert($rows);

        return $batch;
    }
}