<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Import\ServiceRelationshipPermissionContactImportPostProcessor;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceRelationshipPermissionContactImportPostProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirmed_existing_relationship_imports_transactional_permission_without_marketing_permission(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);
        $processor = app(ServiceRelationshipPermissionContactImportPostProcessor::class);

        $config = $processor->withSubmittedInputs(
            config: $processor->operatorConfig(null),
            submitted: [
                'relationship_status' => 'confirmed',
                'channels' => ['email'],
                'attestation' => '1',
            ],
        );

        $result = $processor->handle($this->context($contact), $config);

        $this->assertSame(ContactImportPostProcessResult::STATE_APPLIED, $result->state);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->getKey(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'contact_import',
            'source' => 'contact_import',
        ]);
        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->getKey(),
            'purpose' => 'marketing',
        ]);
        $this->assertSame(
            'operator_attested_existing_relationship',
            data_get($result->meta, 'permission_evidence'),
        );
    }

    public function test_no_or_unsure_existing_relationship_does_not_create_service_permission(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);
        $processor = app(ServiceRelationshipPermissionContactImportPostProcessor::class);

        $config = $processor->withSubmittedInputs(
            config: $processor->operatorConfig(null),
            submitted: [
                'relationship_status' => 'not_confirmed',
            ],
        );

        $this->assertFalse($processor->shouldProcess($config));

        $result = $processor->handle($this->context($contact), $config);

        $this->assertSame(ContactImportPostProcessResult::STATE_SKIPPED, $result->state);
        $this->assertSame('service_relationship_not_confirmed', $result->reasonCode);
        $this->assertDatabaseCount('message_consents', 0);
    }

    public function test_existing_transactional_revocation_is_not_reactivated_by_a_later_import(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'contact_import',
            consentedAt: now()->subHour(),
            source: 'earlier_import',
        );
        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'contact_import',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        $consentCount = MessageConsent::query()
            ->where('contact_id', $contact->getKey())
            ->count();

        $processor = app(ServiceRelationshipPermissionContactImportPostProcessor::class);
        $config = $processor->withSubmittedInputs(
            config: $processor->operatorConfig(null),
            submitted: [
                'relationship_status' => 'confirmed',
                'channels' => ['email'],
                'attestation' => '1',
            ],
        );

        $result = $processor->handle($this->context($contact), $config);

        $this->assertSame(ContactImportPostProcessResult::STATE_SKIPPED, $result->state);
        $this->assertSame('service_relationship_permission_revoked', $result->reasonCode);
        $this->assertSame(
            $consentCount,
            MessageConsent::query()->where('contact_id', $contact->getKey())->count(),
        );
    }

    private function context(Contact $contact): ContactImportContext
    {
        $batch = ContactImportBatch::query()->create([
            'name' => 'Test import',
            'source' => 'test',
            'original_filename' => 'test.csv',
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => now(),
            'contact_count' => 0,
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
            row: ['Email' => $contact->email],
            mapping: ['email' => 'Email'],
            profileKey: 'test_profile',
        );
    }
}