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
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConsentDomainActionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_importing_waitlist_scope_stores_webinar_consent_domain_without_broad_policy(): void
    {
        $contact = Contact::factory()->create();

        $result = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_waitlist',
            source: 'test_import',
        );

        $this->assertTrue($result['created']);
        $this->assertSame('webinar', $result['consent']->scope);
        $this->assertDatabaseHas('message_consents', [
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'source' => 'test_import',
        ]);
    }

    public function test_broad_marketing_policy_reuses_one_domain_across_campaign_lifecycle_scopes(): void
    {
        $this->enableBroadMarketingPolicy();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
            'phone' => '+14075550123',
        ]);

        $email = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'realtor_com_lead_nurture',
            consentedAt: Carbon::parse('2026-08-19 12:00:00'),
            source: 'test_import',
        );

        $sms = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'hot_lead_nurture',
            consentedAt: Carbon::parse('2026-08-19 12:00:00'),
            source: 'test_import',
        );

        $this->assertSame('marketing', $email['consent']->scope);
        $this->assertSame('marketing', $sms['consent']->scope);
        $this->assertSame(2, MessageConsent::query()
            ->where('contact_id', $contact->id)
            ->count());

        $gate = app(MessageGate::class);

        $this->assertTrue($gate->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'past_client_nurture',
        ));
        $this->assertTrue($gate->canSend(
            contact: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'past_client_nurture',
        ));
    }

    public function test_revoking_one_marketing_scope_revokes_all_marketing_on_that_channel_only(): void
    {
        $this->enableBroadMarketingPolicy();

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

        Carbon::setTestNow('2026-08-19 13:00:00');

        $result = app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'past_client_nurture',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        $this->assertTrue($result['created']);
        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'message_consent_id' => $emailConsent->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'marketing',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
        ]);

        $gate = app(MessageGate::class);

        $this->assertFalse($gate->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'hot_lead_nurture',
        ));
        $this->assertFalse($gate->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'past_client_nurture',
        ));
        $this->assertTrue($gate->canSend(
            contact: $contact,
            channel: 'sms',
            purpose: 'marketing',
            scope: 'past_client_nurture',
        ));

        Carbon::setTestNow();
    }

    public function test_later_marketing_consent_reactivates_the_channel_after_revocation(): void
    {
        $this->enableBroadMarketingPolicy();

        $contact = Contact::factory()->create([
            'email' => 'person@example.test',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'lead_nurture',
            consentedAt: '2026-08-19 12:00:00',
            source: 'test_import',
        );

        Carbon::setTestNow('2026-08-19 13:00:00');
        app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'hot_lead_nurture',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'past_client_nurture',
            consentedAt: '2026-08-19 14:00:00',
            source: 'test_regrant',
        );

        $this->assertTrue(app(MessageGate::class)->canSend(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'future_marketing_scope',
        ));

        Carbon::setTestNow();
    }

    private function enableBroadMarketingPolicy(): void
    {
        Config::set('messaging.consent_domains.marketing', [
            'topic' => 'marketing communications',
            'scopes' => [],
            'scope_prefixes' => [],
            'opt_in' => [],
        ]);
        Config::set('messaging.consent.channel_purpose_domains', [
            'email' => [
                'marketing' => 'marketing',
            ],
            'sms' => [
                'marketing' => 'marketing',
            ],
        ]);
    }
}