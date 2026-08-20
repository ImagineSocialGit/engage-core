<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Data\Contacts\ContactImportPostProcessResult;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Import\MarketingPermissionContactImportPostProcessor;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPermissionContactImportPostProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_email_and_sms_marketing_permission_into_the_broad_domain(): void
    {
        $this->configureBroadMarketingConsent();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => '+15555550123',
        ]);
        $context = $this->context($contact);

        $result = app(MarketingPermissionContactImportPostProcessor::class)->handle($context, [
            'channels' => ['email', 'sms'],
            'scope' => 'lead_nurture',
        ]);

        $this->assertSame(ContactImportPostProcessResult::STATE_APPLIED, $result->state);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'marketing',
        ]);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'marketing',
        ]);
        $this->assertSame(2, MessageConsent::query()->where('contact_id', $contact->id)->count());
    }

    public function test_missing_sms_destination_does_not_block_email_marketing_permission(): void
    {
        $this->configureBroadMarketingConsent();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => null,
        ]);

        $result = app(MarketingPermissionContactImportPostProcessor::class)->handle(
            $this->context($contact),
            [
                'channels' => ['email', 'sms'],
                'scope' => 'lead_nurture',
            ],
        );

        $this->assertSame(ContactImportPostProcessResult::STATE_PARTIAL, $result->state);
        $this->assertSame('sms_destination_missing', $result->meta['channels']['sms']['reason_code']);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'marketing',
        ]);
        $this->assertDatabaseMissing('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'sms',
        ]);
    }

    public function test_reimport_does_not_reactivate_a_currently_revoked_marketing_channel(): void
    {
        $this->configureBroadMarketingConsent();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'lead_nurture',
            consentedAt: now()->subHour(),
            source: 'earlier_import',
        );
        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'lead_nurture',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        $consentCount = MessageConsent::query()->where('contact_id', $contact->id)->count();

        $result = app(MarketingPermissionContactImportPostProcessor::class)->handle(
            $this->context($contact),
            [
                'channels' => ['email'],
                'scope' => 'past_client_nurture',
            ],
        );

        $this->assertSame(ContactImportPostProcessResult::STATE_SKIPPED, $result->state);
        $this->assertSame('marketing_permission_revoked', $result->reasonCode);
        $this->assertSame(
            $consentCount,
            MessageConsent::query()->where('contact_id', $contact->id)->count(),
        );
    }

    private function configureBroadMarketingConsent(): void
    {
        config([
            'messaging.consent_domains.marketing' => [
                'topic' => 'marketing messages',
                'scopes' => [],
                'scope_prefixes' => [],
            ],
            'messaging.consent.channel_purpose_domains' => [
                'email' => ['marketing' => 'marketing'],
                'sms' => ['marketing' => 'marketing'],
            ],
        ]);
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
            'contact_import_batch_id' => $batch->id,
            'contact_id' => $contact->id,
            'row_number' => 2,
            'outcome' => ContactImportOccurrence::OUTCOME_CREATED,
            'identity_type' => 'email',
            'identity_value' => $contact->email,
            'row_fingerprint' => hash('sha256', $contact->email),
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