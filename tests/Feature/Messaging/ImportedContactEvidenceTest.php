<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Import\ContactImportEvidenceHandler;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Services\Contacts\ContactImportHeaderMapper;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Import\ImportEmailSuppressionContactImportHandler;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportedContactEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_mapping_suggests_common_external_headers_without_a_custom_importer(): void
    {
        $mapping = app(ContactImportHeaderMapper::class)->suggest([
            'Email Address', 'TIMEZONE', 'CC', 'REGION', 'OPTIN_TIME',
            'CONFIRM_TIME', 'LEID', 'EUID', 'CLEAN_TIME', 'CLEAN_CAMPAIGN_ID',
        ]);

        $this->assertSame('TIMEZONE', $mapping['timezone'] ?? null);
        $this->assertSame('CC', $mapping['country_code'] ?? null);
        $this->assertSame('REGION', $mapping['region_code'] ?? null);
        $this->assertSame('OPTIN_TIME', $mapping['external_signup_at'] ?? null);
        $this->assertSame('CONFIRM_TIME', $mapping['external_confirm_at'] ?? null);
        $this->assertSame('LEID', $mapping['external_id'] ?? null);
        $this->assertSame('EUID', $mapping['external_secondary_id'] ?? null);
        $this->assertSame('CLEAN_TIME', $mapping['email_cleaned_at'] ?? null);
        $this->assertSame('CLEAN_CAMPAIGN_ID', $mapping['email_clean_campaign_id'] ?? null);
    }

    public function test_imported_timezone_and_geography_evidence_are_preserved_without_granting_consent(): void
    {
        $contact = Contact::factory()->create(['email' => 'recipient@example.test']);
        $context = $this->context($contact, [
            'TIMEZONE' => 'America/Chicago',
            'CC' => 'us',
            'REGION' => 'tn',
            'OPTIN_TIME' => '2014-08-30 06:43:37',
            'CONFIRM_TIME' => '2014-08-30 06:32:46',
            'LEID' => '123456',
            'EUID' => 'abc123',
        ], [
            'timezone' => 'TIMEZONE',
            'country_code' => 'CC',
            'region_code' => 'REGION',
            'external_signup_at' => 'OPTIN_TIME',
            'external_confirm_at' => 'CONFIRM_TIME',
            'external_id' => 'LEID',
            'external_secondary_id' => 'EUID',
        ]);

        app(ContactImportEvidenceHandler::class)->handle($context);

        $contact->refresh();
        $this->assertSame('America/Chicago', $contact->timezone);
        $this->assertSame('import', $contact->timezone_source);
        $evidence = $context->occurrence->fresh()->meta['import_evidence']['evidence'];
        $this->assertSame('2014-08-30 06:43:37', $evidence['signup_time_raw']);
        $this->assertSame('2014-08-30 06:32:46', $evidence['confirmation_time_raw']);
        $this->assertSame('123456', $evidence['external_id']);
        $this->assertSame('abc123', $evidence['external_secondary_id']);
        $this->assertSame('us', $evidence['country_code_raw']);
        $this->assertSame('tn', $evidence['region_code_raw']);
        $this->assertSame(0, MessageConsent::query()->count());
    }

    public function test_invalid_timezone_does_not_overwrite_an_existing_valid_timezone(): void
    {
        $contact = Contact::factory()->create([
            'timezone' => 'America/New_York',
            'timezone_source' => 'operator',
        ]);
        $context = $this->context($contact, ['TIMEZONE' => '615/629'], ['timezone' => 'TIMEZONE']);

        app(ContactImportEvidenceHandler::class)->handle($context);

        $this->assertSame('America/New_York', $contact->fresh()->timezone);
        $this->assertSame('operator', $contact->fresh()->timezone_source);
        $this->assertSame('615/629', $context->occurrence->fresh()->meta['import_evidence']['rejected']['timezone']);
    }

    public function test_external_cleaned_status_suppresses_email_but_not_sms_or_contact_status(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'recipient@example.test',
            'phone' => '+15551234567',
        ]);
        $context = $this->context($contact, [
            'Clean Date' => '',
            'Campaign' => 'Tour',
        ], [
            'email_cleaned_at' => 'Clean Date',
            'email_clean_campaign_title' => 'Campaign',
        ], ['external_email_status' => 'cleaned']);

        $handler = app(ImportEmailSuppressionContactImportHandler::class);
        $handler->handle($context);
        $handler->handle($context);

        $this->assertTrue(app(MessageSuppressionService::class)->isSuppressed(MessageChannel::Email, 'recipient@example.test'));
        $this->assertFalse(app(MessageSuppressionService::class)->isSuppressed(MessageChannel::Sms, '+15551234567'));
        $this->assertSame(1, MessageSuppression::query()->count());
        $this->assertSame(0, MessageConsent::query()->count());
        $this->assertSame('bounce', $context->occurrence->fresh()->meta['external_email_delivery']['suppression_reason']);
        $this->assertTrue($contact->fresh()->exists);
    }

    public function test_explicit_clean_time_suppresses_even_without_a_dataset_default(): void
    {
        $contact = Contact::factory()->create();
        $context = $this->context(
            $contact,
            ['CLEAN_TIME' => '2019-03-25 11:02:02'],
            ['email_cleaned_at' => 'CLEAN_TIME'],
        );

        app(ImportEmailSuppressionContactImportHandler::class)->handle($context);

        $this->assertTrue(app(MessageSuppressionService::class)->isSuppressed(
            MessageChannel::Email,
            $contact->email,
        ));
        $this->assertSame('2019-03-25 11:02:02',
            $context->occurrence->fresh()->meta['external_email_delivery']['cleaned_at_raw']);
    }

    public function test_blank_or_unrecognized_external_email_evidence_does_not_suppress(): void
    {
        $contact = Contact::factory()->create();
        $context = $this->context($contact, ['Status' => 'html'], ['external_email_status' => 'Status']);
        app(ImportEmailSuppressionContactImportHandler::class)->handle($context);
        $this->assertSame(0, MessageSuppression::query()->count());
    }

    /** @param array<string, string> $row
     *  @param array<string, string> $mapping
     *  @param array<string, string> $defaults
     */
    private function context(Contact $contact, array $row, array $mapping, array $defaults = []): ContactImportContext
    {
        $batch = ContactImportBatch::query()->create([
            'name' => 'Evidence import',
            'source' => 'external',
            'original_filename' => 'external.csv',
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now(),
            'contact_count' => 1,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [],
        ]);
        $occurrence = ContactImportOccurrence::query()->create([
            'contact_import_batch_id' => $batch->getKey(),
            'contact_id' => $contact->getKey(),
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', (string) $contact->email),
            'meta' => [],
        ]);

        return new ContactImportContext(
            contact: $contact,
            batch: $batch,
            occurrence: $occurrence,
            row: $row,
            mapping: $mapping,
            defaults: $defaults,
            profileKey: 'external_cleaned_audience',
        );
    }
}