<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Services\MessageGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ConsentDomainActionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_retains_requested_scope_even_when_acknowledgement_domain_is_configured(): void
    {
        config()->set('messaging.consent.channel_purpose_domains.email.marketing', 'marketing');
        config()->set('messaging.consent_domains.marketing', [
            'topic' => 'marketing communications',
            'scopes' => [],
            'scope_prefixes' => [],
            'opt_in' => [],
        ]);

        $contact = Contact::factory()->create();

        $result = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_waitlist',
            source: 'test_import',
        );

        $this->assertTrue($result['created']);
        $this->assertSame('webinar_waitlist', $result['consent']->scope);
        $this->assertSame(
            'marketing',
            data_get($result['consent']->meta, 'consent.domain'),
        );
    }

    public function test_marketing_grant_authorizes_different_message_scope_on_same_channel_and_purpose(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'realtor_com_lead_nurture',
            source: 'test_import',
        );

        $this->assertTrue(app(MessageGate::class)->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'past_client_nurture',
        ));
    }

    public function test_permission_does_not_cross_channel_or_purpose_boundaries(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => '+14075550123',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'lead_nurture',
            source: 'test_import',
        );

        $gate = app(MessageGate::class);

        $this->assertTrue($gate->canSend($contact, 'email', 'marketing', 'other_scope'));
        $this->assertFalse($gate->canSend($contact, 'sms', 'marketing', 'other_scope'));
        $this->assertFalse($gate->canSend($contact, 'email', 'transactional', 'other_scope'));
    }

    public function test_revocation_blocks_every_scope_on_that_channel_and_purpose_only(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => '+14075550123',
        ]);
        $consentedAt = Carbon::parse('2026-08-19 12:00:00');

        $emailConsent = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'realtor_com_lead_nurture',
            consentedAt: $consentedAt,
            source: 'test_import',
        )['consent'];

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'realtor_com_lead_nurture',
            consentedAt: $consentedAt,
            source: 'test_import',
        );

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'transactional',
            scope: 'webinar',
            consentedAt: $consentedAt,
            source: 'test_import',
        );

        Carbon::setTestNow('2026-08-19 13:00:00');

        $result = app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'past_client_nurture',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        $this->assertTrue($result['created']);
        $this->assertCount(1, $result['revocations']);
        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'message_consent_id' => $emailConsent->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'past_client_nurture',
        ]);

        $gate = app(MessageGate::class);

        $this->assertFalse($gate->canSend($contact, 'email', 'marketing', 'hot_lead_nurture'));
        $this->assertFalse($gate->canSend($contact, 'email', 'marketing', 'future_scope'));
        $this->assertTrue($gate->canSend($contact, 'sms', 'marketing', 'future_scope'));
        $this->assertTrue($gate->canSend($contact, 'email', 'transactional', 'webinar'));

        Carbon::setTestNow();
    }

    public function test_historical_scope_specific_rows_resolve_through_channel_and_purpose_without_data_rewrite(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'legacy_webinar_domain',
            'consented_at' => '2026-08-19 12:00:00',
            'source' => 'legacy',
        ]);

        ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'legacy_campaign_domain',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'revoked_at' => '2026-08-19 13:00:00',
            'source' => 'legacy',
        ]);

        $this->assertFalse(app(MessageGate::class)->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'brand_new_scope',
        ));

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'fresh_capture_context',
            consentedAt: '2026-08-19 14:00:00',
            source: 'later_import',
        );

        $this->assertTrue(app(MessageGate::class)->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'brand_new_scope',
        ));
    }
}