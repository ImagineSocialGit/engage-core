<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Jobs\FinalizeContactImportBatchJob;
use App\Modules\Core\Jobs\ProcessContactImportBatchChunkJob;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Models\ContactImportRun;
use App\Modules\Core\Services\Contacts\ContactImportBatchProcessor;
use App\Modules\Core\Services\Contacts\RecoverStalledContactImports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ContactImportRedisRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        config()->set('client.key', 'test-client');
        config()->set('contact_imports.storage.disk', 'local');
        config()->set('contact_imports.processing.chunk_rows', 1);
        config()->set('contact_imports.recovery.stale_after_seconds', 300);
        config()->set('contact_imports.recovery.redispatch_after_seconds', 300);
        config()->set('contact_imports.recovery.max_per_run', 2);
    }

    public function test_lost_initial_job_is_reconstructed_only_after_checkpoint_stalls(): void
    {
        $batch = $this->createImport("Email\none@example.test\n");
        Queue::fake(); // Redis lost the original job.

        $recovery = app(RecoverStalledContactImports::class);
        $this->assertSame(0, $recovery->recover());

        $this->ageRun($batch);
        $this->assertSame(1, $recovery->recover());
        $this->assertSame(0, $recovery->recover());

        Queue::assertPushed(
            ProcessContactImportBatchChunkJob::class,
            fn (ProcessContactImportBatchChunkJob $job): bool =>
                $job->contactImportBatchId === $batch->getKey(),
        );
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class, 1);
        Queue::assertNotPushed(FinalizeContactImportBatchJob::class);

        $this->assertSame(ContactImportRun::STATUS_PENDING, $batch->run()->firstOrFail()->status);
        $this->assertNotNull($batch->run()->firstOrFail()->recovery_dispatched_at);
    }

    public function test_lost_successor_can_resume_checkpoint_without_replaying_processed_rows(): void
    {
        $batch = $this->createImport("Email\none@example.test\ntwo@example.test\nthree@example.test\n");
        $processor = app(ContactImportBatchProcessor::class);

        $this->assertFalse($processor->processNextChunk($batch->getKey()));
        $this->assertSame(1, ContactImportOccurrence::query()->count());
        Queue::fake(); // A successor did not survive the Redis loss.

        $this->ageRun($batch);
        $this->assertSame(1, app(RecoverStalledContactImports::class)->recover());
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class, 1);

        $this->assertFalse($processor->processNextChunk($batch->getKey()));
        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(ContactImportBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(3, ContactImportOccurrence::query()->count());
        $this->assertSame(3, $batch->fresh()->successful_count);
        $this->assertNull($batch->run()->first());
    }

    public function test_lost_finalizer_is_recovered_without_repeating_finalization(): void
    {
        $batch = $this->createImport("Email\none@example.test\n");
        $processor = app(ContactImportBatchProcessor::class);

        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $this->assertSame(ContactImportRun::STATUS_FINALIZING, $batch->run()->firstOrFail()->status);
        Queue::fake(); // The finalizer never reached a worker.

        $this->ageRun($batch);
        $recovery = app(RecoverStalledContactImports::class);
        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(FinalizeContactImportBatchJob::class, 1);
        Queue::assertNotPushed(ProcessContactImportBatchChunkJob::class);

        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(ContactImportBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(0, $recovery->recover());
        $this->assertSame(1, ContactImportOccurrence::query()->count());
    }

    public function test_failed_and_completed_batches_are_never_redispatched(): void
    {
        $failed = $this->createImport("Email\nfailed@example.test\n");
        $completed = $this->createImport("Email\ncompleted@example.test\n");

        $failed->forceFill(['status' => ContactImportBatch::STATUS_FAILED])->save();
        $completed->forceFill(['status' => ContactImportBatch::STATUS_COMPLETED])->save();
        $this->ageRun($failed);
        $this->ageRun($completed);
        Queue::fake();

        $this->assertSame(0, app(RecoverStalledContactImports::class)->recover());
        Queue::assertNothingPushed();
    }

    public function test_recovery_is_bounded_and_a_claim_can_be_retried_after_expiring(): void
    {
        $first = $this->createImport("Email\nfirst@example.test\n");
        $second = $this->createImport("Email\nsecond@example.test\n");
        $third = $this->createImport("Email\nthird@example.test\n");
        foreach ([$first, $second, $third] as $batch) {
            $this->ageRun($batch);
        }
        Queue::fake();

        $recovery = app(RecoverStalledContactImports::class);
        $this->assertSame(2, $recovery->recover());
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class, 2);

        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class, 3);

        $this->assertSame(0, $recovery->recover());
        DB::table('contact_import_runs')
            ->where('contact_import_batch_id', $first->getKey())
            ->update(['recovery_dispatched_at' => now()->subMinutes(10)]);

        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(ProcessContactImportBatchChunkJob::class, 4);
    }

    public function test_expired_finalization_claim_pushes_another_job_after_a_lost_dispatch(): void
    {
        $batch = $this->createImport("Email\none@example.test\n");
        $this->assertTrue(app(ContactImportBatchProcessor::class)->processNextChunk($batch->getKey()));
        $this->assertSame(
            ContactImportRun::STATUS_FINALIZING,
            $batch->run()->firstOrFail()->status,
        );

        $this->ageRun($batch);
        Queue::fake(); // A previously dispatched finalizer has gone missing.

        $recovery = app(RecoverStalledContactImports::class);
        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(FinalizeContactImportBatchJob::class, 1);

        $this->assertSame(0, $recovery->recover());
        DB::table('contact_import_runs')
            ->where('contact_import_batch_id', $batch->getKey())
            ->update(['recovery_dispatched_at' => now()->subMinutes(10)]);

        $this->assertSame(1, $recovery->recover());
        Queue::assertPushed(FinalizeContactImportBatchJob::class, 2);
        Queue::assertNotPushed(ProcessContactImportBatchChunkJob::class);
    }

    private function ageRun(ContactImportBatch $batch): void
    {
        DB::table('contact_import_runs')
            ->where('contact_import_batch_id', $batch->getKey())
            ->update(['updated_at' => now()->subMinutes(10)]);
    }

    private function createImport(string $contents): ContactImportBatch
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            ['csv' => UploadedFile::fake()->createWithContent('contacts.csv', $contents)],
        );
        $preview->assertOk();
        $path = $preview->viewData('csvPath');
        $this->assertIsString($path);

        $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $path,
                'mapping' => ['email' => 'Email'],
            ],
        )->assertRedirect();

        return ContactImportBatch::query()->latest('id')->firstOrFail();
    }
}