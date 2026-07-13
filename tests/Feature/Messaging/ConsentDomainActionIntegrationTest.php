<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ImportMessageConsentAction;
use App\Modules\Messaging\Actions\RevokeMessageConsentAction;
use App\Modules\Messaging\Models\ConsentRevocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsentDomainActionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_importing_waitlist_scope_stores_webinar_consent_domain_without_side_effects(): void
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

    public function test_revoking_nurture_message_scope_revokes_shared_webinar_domain(): void
    {
        $contact = Contact::factory()->create();

        $consent = app(ImportMessageConsentAction::class)->handle(
            contact: $contact,
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_waitlist',
            source: 'test_import',
        )['consent'];

        $result = app(RevokeMessageConsentAction::class)->handle($contact, [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'test',
        ]);

        $this->assertTrue($result['created']);
        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'message_consent_id' => $consent->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
        ]);
    }
}
