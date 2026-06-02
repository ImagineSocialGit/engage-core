<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Services\Messaging\MessageEligibilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailConsentRevocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_email_transactional_opt_out_revokes_transactional_email_consent(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Transactional);

        $this->get(URL::temporarySignedRoute(
            name: 'messaging.email.transactional-opt-out',
            expiration: now()->addDays(7),
            parameters: ['contact' => $contact],
        ))
            ->assertOk()
            ->assertViewIs('messaging.transactional-opt-out-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'message_consent_id' => $this->messageConsentId($contact, MessagePurpose::Transactional),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'reason' => ConsentRevocation::REASON_OPT_OUT,
            'source' => 'public_email_unsubscribe',
        ]);
    }

    public function test_signed_email_unsubscribe_revokes_marketing_email_consent(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Marketing);

        $this->get($this->signedUnsubscribeUrl($contact))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'message_consent_id' => $this->messageConsentId($contact, MessagePurpose::Marketing),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'public_email_unsubscribe',
        ]);
    }

    public function test_unsigned_email_unsubscribe_url_is_rejected(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Marketing);

        $this->get(route('messaging.email.unsubscribe', ['contact' => $contact]))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
        ]);
    }

    public function test_expired_email_unsubscribe_url_is_rejected(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Marketing);

        $url = URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->subMinute(),
            parameters: ['contact' => $contact],
        );

        $this->get($url)
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
        ]);
    }

    public function test_email_unsubscribe_is_idempotent(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Marketing);
        $url = $this->signedUnsubscribeUrl($contact);

        $this->get($url)->assertOk()->assertViewIs('messaging.unsubscribe-confirmed');
        $this->get($url)->assertOk()->assertViewIs('messaging.unsubscribe-already-confirmed');

        $this->assertSame(1, ConsentRevocation::query()->count());
    }

    public function test_email_marketing_eligibility_fails_after_unsubscribe(): void
    {
        $contact = $this->contactWithConsent(MessagePurpose::Marketing);

        $gate = app(MessageEligibilityGate::class);

        $this->assertTrue($gate->canSend($contact, MessageChannel::Email, MessagePurpose::Marketing));

        $this->get($this->signedUnsubscribeUrl($contact))->assertOk();

        $this->assertFalse($gate->canSend($contact->refresh(), MessageChannel::Email, MessagePurpose::Marketing));
    }

    public function test_email_unsubscribe_does_not_revoke_transactional_email_consent(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Marketing);
        $this->grantConsent($contact, MessagePurpose::Transactional);

        $this->get($this->signedUnsubscribeUrl($contact))->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
        ]);

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
        ]);

        $this->assertTrue(
            app(MessageEligibilityGate::class)->canSend(
                $contact->refresh(),
                MessageChannel::Email,
                MessagePurpose::Transactional,
            )
        );
    }

    private function contactWithConsent(MessagePurpose $purpose): Contact
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, $purpose);

        return $contact;
    }

    private function createContact(): Contact
    {
        return Contact::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Contact',
            'name' => 'Test Contact',
            'email' => 'test@example.com',
            'phone' => '+15555555555',
        ]);
    }

    private function grantConsent(Contact $contact, MessagePurpose $purpose): void
    {
        DB::table('message_consents')->insert([
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => $purpose->value,
            'consented_at' => now(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test',
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function messageConsentId(Contact $contact, MessagePurpose $purpose): int
    {
        return DB::table('message_consents')
            ->where('contact_id', $contact->id)
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', $purpose->value)
            ->value('id');
    }

    private function signedUnsubscribeUrl(Contact $contact): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->addDays(7),
            parameters: ['contact' => $contact],
        );
    }
}