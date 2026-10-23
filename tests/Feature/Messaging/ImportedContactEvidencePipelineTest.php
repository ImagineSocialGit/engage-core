<?php

namespace Tests\Feature\Messaging;

use App\Models\User;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Services\Contacts\ContactImportBatchProcessor;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageSuppression;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportedContactEvidencePipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_default_suppresses_every_row_of_a_known_cleaned_export_including_rows_missing_cleanup_time(): void
    {
        Storage::fake('local');
        Queue::fake();

        config()->set('contact_imports.profiles.external_cleaned_audience', [
            'label' => 'Cleaned email audience',
            'filename_contains' => ['cleaned_email_audience_export'],
            'defaults' => [
                'external_email_status' => 'cleaned',
            ],
        ]);

        $user = User::factory()->create();
        $preview = $this->actingAs($user)->post(route('crm.contacts.import.preview'), [
            'csv' => UploadedFile::fake()->createWithContent(
                'cleaned_email_audience_export.csv',
                implode("\n", [
                    'Email Address,TIMEZONE,CC,REGION,OPTIN_TIME,CONFIRM_TIME,CLEAN_TIME,CLEAN_CAMPAIGN_ID',
                    'first@example.test,America/Chicago,US,TN,2014-08-30 06:43:37,2014-08-30 06:32:46,2019-03-25 11:02:02,evt001',
                    'second@example.test,615/629,US,TN,2011-07-06 10:36:36,2011-07-06 10:36:36,,',
                ]),
            ),
        ]);

        $preview->assertOk();
        $csvPath = $preview->viewData('csvPath');
        $this->assertIsString($csvPath);
        $this->assertSame('external_cleaned_audience', $preview->viewData('importProfile')?->key);

        $process = $this->actingAs($user)->post(route('crm.contacts.import.process'), [
            'csv_path' => $csvPath,
            'mapping' => [
                'email' => 'Email Address',
                'timezone' => 'TIMEZONE',
                'country_code' => 'CC',
                'region_code' => 'REGION',
                'external_signup_at' => 'OPTIN_TIME',
                'external_confirm_at' => 'CONFIRM_TIME',
                'email_cleaned_at' => 'CLEAN_TIME',
                'email_clean_campaign_id' => 'CLEAN_CAMPAIGN_ID',
            ],
        ]);

        $batch = ContactImportBatch::query()->latest('id')->firstOrFail();
        $process->assertRedirect(route('crm.contacts.import-batches.show', $batch));

        $processor = app(ContactImportBatchProcessor::class);
        $this->assertTrue($processor->processNextChunk($batch->getKey()));
        $processor->finalizeBatch($batch->getKey());

        $first = Contact::query()->where('email', 'first@example.test')->firstOrFail();
        $second = Contact::query()->where('email', 'second@example.test')->firstOrFail();

        $this->assertSame('America/Chicago', $first->timezone);
        $this->assertSame('US', $first->importOccurrences()->firstOrFail()->meta['import_evidence']['evidence']['country_code_raw']);
        $this->assertSame('TN', $first->importOccurrences()->firstOrFail()->meta['import_evidence']['evidence']['region_code_raw']);
        $this->assertNull($second->timezone);
        $this->assertSame(2, MessageSuppression::query()->active()->count());
        $this->assertSame(0, MessageConsent::query()->count());
        $this->assertSame('615/629', $second->importOccurrences()->firstOrFail()->meta['import_evidence']['rejected']['timezone']);
    }
}