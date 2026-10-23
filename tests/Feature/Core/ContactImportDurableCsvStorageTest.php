<?php

namespace Tests\Feature\Core;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Models\ContactImportRun;
use App\Modules\Core\Services\Contacts\ContactImportBatchProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ContactImportDurableCsvStorageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('contact_imports');
        Storage::fake('local');
        Queue::fake();

        config()->set('contact_imports.storage.disk', 'contact_imports');
        config()->set('contact_imports.processing.chunk_rows', 1);
        config()->set('client.key', 'test-client');
    }

    public function test_import_survives_lost_local_files_and_uses_its_durable_csv_checkpoint(): void
    {
        $user = User::factory()->create();

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            ['csv' => UploadedFile::fake()->createWithContent(
                'contacts.csv',
                "Email,First Name\none@example.test,One\ntwo@example.test,Two\n",
            )],
        );

        $preview->assertOk();
        $path = $preview->viewData('csvPath');
        $this->assertIsString($path);
        $this->assertTrue(Storage::disk('contact_imports')->exists($path));
        $this->assertFalse(Storage::disk('local')->exists($path));

        // Preview can be reopened without a locally persisted source.
        $this->actingAs($user)->get(route('crm.contacts.import.review', [
            'csv_path' => $path,
        ]))->assertOk();

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $path,
            'mapping' => [
                'email' => 'Email',
                'first_name' => 'First Name',
            ],
        ])->assertRedirect();

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $run = ContactImportRun::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->firstOrFail();

        $this->assertSame('contact_imports', $run->csv_disk);
        $this->assertSame($path, $run->csv_path);

        $processor = app(ContactImportBatchProcessor::class);
        $this->assertFalse($processor->processNextChunk($batch->getKey()));
        $this->assertSame(1, $run->fresh()->processed_rows);

        // Simulate an app host replacement: local storage is empty and every
        // chunk must reconstruct a seekable stream from remote source bytes.
        Storage::disk('local')->deleteDirectory('imports');
        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(ContactImportBatch::STATUS_COMPLETED, $batch->fresh()->status);
        $this->assertSame(2, Contact::query()->count());
        $this->assertSame(2, ContactImportOccurrence::query()->count());
        $this->assertFalse(Storage::disk('contact_imports')->exists($path));
    }

    public function test_run_keeps_its_original_source_disk_when_new_import_configuration_changes(): void
    {
        $user = User::factory()->create();
        config()->set('contact_imports.storage.disk', 'local');

        $preview = $this->actingAs($user)->post(
            route('crm.contacts.import.preview'),
            ['csv' => UploadedFile::fake()->createWithContent(
                'legacy.csv',
                "Email\nlegacy@example.test\n",
            )],
        );

        $preview->assertOk();
        $path = $preview->viewData('csvPath');

        $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $path,
            'mapping' => ['email' => 'Email'],
        ])->assertRedirect();

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $run = ContactImportRun::query()
            ->where('contact_import_batch_id', $batch->getKey())
            ->firstOrFail();

        $this->assertSame('local', $run->csv_disk);

        config()->set('contact_imports.storage.disk', 'contact_imports');

        $processor = app(ContactImportBatchProcessor::class);
        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $processor->finalizeBatch($batch->getKey());

        $this->assertSame(1, Contact::query()->count());
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}