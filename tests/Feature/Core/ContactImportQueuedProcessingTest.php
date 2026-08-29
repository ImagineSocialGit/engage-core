<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Contracts\Contacts\ContactImportHandler;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Jobs\ProcessContactImportBatchChunkJob;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Models\ContactImportRun;
use App\Modules\Core\Services\Contacts\ContactImportBatchProcessor;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Support\ProjectState\ProjectStateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ContactImportQueuedProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        config()->set('contact_imports.processing.chunk_rows', 2);
        config()->set('client.key', 'test-client');
        config()->set('project_state.enforce_client_key', true);
    }

    public function test_import_request_queues_background_work_and_progresses_in_bounded_chunks(): void
    {
        $user = User::factory()->create();
        $csvPath = $this->preview(
            user: $user,
            contents: "Email,First Name\none@example.test,One\ntwo@example.test,Two\nthree@example.test,Three\n",
        );

        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                    'first_name' => 'First Name',
                ],
            ],
        );

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $run = ContactImportRun::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->firstOrFail();

        $response->assertRedirect(
            route('crm.contacts.import-batches.show', $batch),
        );
        $this->assertSame(ContactImportBatch::STATUS_PENDING, $batch->status);
        $this->assertSame(3, $batch->contact_count);
        $this->assertSame(3, $run->total_rows);
        $this->assertSame(0, $run->processed_rows);
        $this->assertSame(2, $run->next_row_number);
        $this->assertTrue(Storage::disk('local')->exists($csvPath));

        Queue::assertPushed(
            ProcessContactImportBatchChunkJob::class,
            fn (ProcessContactImportBatchChunkJob $job): bool =>
                $job->contactImportBatchId === $batch->getKey(),
        );

        $processor = app(ContactImportBatchProcessor::class);

        $this->assertFalse($processor->processNextChunk($batch->getKey()));

        $batch->refresh();
        $run->refresh();

        $this->assertSame(ContactImportBatch::STATUS_PROCESSING, $batch->status);
        $this->assertSame(2, $batch->successful_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame(2, $run->processed_rows);
        $this->assertSame(4, $run->next_row_number);
        $this->assertSame(2, ContactImportOccurrence::query()->count());

        $this->assertTrue($processor->processNextChunk($batch->getKey()));

        $run->refresh();

        $this->assertSame(ContactImportRun::STATUS_FINALIZING, $run->status);
        $this->assertSame(3, $run->processed_rows);

        $processor->finalizeBatch($batch->getKey());

        $batch->refresh();

        $this->assertSame(ContactImportBatch::STATUS_COMPLETED, $batch->status);
        $this->assertSame(3, $batch->successful_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame(3, Contact::query()->count());
        $this->assertSame(3, ContactImportOccurrence::query()->count());
        $this->assertSame(
            0,
            ContactImportRun::query()
                ->where('contact_import_batch_id', $batch->getKey())
                ->count(),
        );
        $this->assertFalse(Storage::disk('local')->exists($csvPath));

        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(3, ContactImportOccurrence::query()->count());
    }

    public function test_failed_chunk_rolls_back_and_retries_from_the_same_durable_checkpoint(): void
    {
        $user = User::factory()->create();
        $csvPath = $this->preview(
            user: $user,
            contents: "Email\none@example.test\ntwo@example.test\n",
        );

        $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                ],
            ],
        )->assertRedirect();

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();

        app(ContactImportRegistry::class)->registerHandler(
            FailingOnceContactImportHandler::class,
        );
        FailingOnceContactImportHandler::$shouldFail = true;

        $processor = app(ContactImportBatchProcessor::class);

        try {
            $processor->processNextChunk($batch->getKey());
            $this->fail('The test import handler should have failed the chunk.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Synthetic Contact import chunk failure.',
                $exception->getMessage(),
            );
        }

        $run = ContactImportRun::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->firstOrFail();

        $this->assertSame(0, $run->processed_rows);
        $this->assertSame(2, $run->next_row_number);
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, ContactImportOccurrence::query()->count());

        FailingOnceContactImportHandler::$shouldFail = false;

        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(2, Contact::query()->count());
        $this->assertSame(2, ContactImportOccurrence::query()->count());
    }

    public function test_project_state_export_is_blocked_while_an_environment_local_import_run_is_active(): void
    {
        $user = User::factory()->create();
        $csvPath = $this->preview(
            user: $user,
            contents: "Email\none@example.test\n",
        );

        $this->actingAs($user)->post(
            route('crm.contacts.import.process'),
            [
                'csv_path' => $csvPath,
                'mapping' => [
                    'email' => 'Email',
                ],
            ],
        )->assertRedirect();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contact_import_runs');

        app(ProjectStateManager::class)->export();
    }

    private function preview(User $user, string $contents): string
    {
        $response = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            [
                'csv' => UploadedFile::fake()->createWithContent(
                    'contacts.csv',
                    $contents,
                ),
            ],
        );

        $response->assertOk();

        $csvPath = $response->viewData('csvPath');

        $this->assertIsString($csvPath);
        $this->assertNotSame('', trim($csvPath));

        return $csvPath;
    }
}

final class FailingOnceContactImportHandler implements ContactImportHandler
{
    public static bool $shouldFail = false;

    public function handle(ContactImportContext $context): void
    {
        if (self::$shouldFail) {
            throw new RuntimeException(
                'Synthetic Contact import chunk failure.',
            );
        }
    }
}