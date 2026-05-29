<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\ConsentRevocation;
use App\Models\Lead;
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
        $lead = $this->leadWithConsent(MessagePurpose::Transactional);

        $this->get(URL::temporarySignedRoute(
            name: 'messaging.email.transactional-opt-out',
            expiration: now()->addDays(7),
            parameters: ['lead' => $lead],
        ))
            ->assertOk()
            ->assertViewIs('messaging.transactional-opt-out-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'message_consent_id' => $this->messageConsentId($lead, MessagePurpose::Transactional),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'reason' => ConsentRevocation::REASON_OPT_OUT,
            'source' => 'public_email_unsubscribe',
        ]);
    }

    public function test_signed_email_unsubscribe_revokes_marketing_email_consent(): void
    {
        $lead = $this->leadWithConsent(MessagePurpose::Marketing);

        $this->get($this->signedUnsubscribeUrl($lead))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'message_consent_id' => $this->messageConsentId($lead, MessagePurpose::Marketing),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
            'source' => 'public_email_unsubscribe',
        ]);
    }

    public function test_unsigned_email_unsubscribe_url_is_rejected(): void
    {
        $lead = $this->leadWithConsent(MessagePurpose::Marketing);

        $this->get(route('messaging.email.unsubscribe', ['lead' => $lead]))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseMissing('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
        ]);
    }

    public function test_expired_email_unsubscribe_url_is_rejected(): void
    {
        $lead = $this->leadWithConsent(MessagePurpose::Marketing);

        $url = URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->subMinute(),
            parameters: ['lead' => $lead],
        );

        $this->get($url)
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseMissing('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
        ]);
    }

    public function test_email_unsubscribe_is_idempotent(): void
    {
        $lead = $this->leadWithConsent(MessagePurpose::Marketing);
        $url = $this->signedUnsubscribeUrl($lead);

        $this->get($url)->assertOk()->assertViewIs('messaging.unsubscribe-confirmed');
        $this->get($url)->assertOk()->assertViewIs('messaging.unsubscribe-already-confirmed');

        $this->assertSame(1, ConsentRevocation::query()->count());
    }

    public function test_email_marketing_eligibility_fails_after_unsubscribe(): void
    {
        $lead = $this->leadWithConsent(MessagePurpose::Marketing);

        $gate = app(MessageEligibilityGate::class);

        $this->assertTrue($gate->canSend($lead, MessageChannel::Email, MessagePurpose::Marketing));

        $this->get($this->signedUnsubscribeUrl($lead))->assertOk();

        $this->assertFalse($gate->canSend($lead->refresh(), MessageChannel::Email, MessagePurpose::Marketing));
    }

    public function test_email_unsubscribe_does_not_revoke_transactional_email_consent(): void
    {
        $lead = $this->createLead();

        $this->grantConsent($lead, MessagePurpose::Marketing);
        $this->grantConsent($lead, MessagePurpose::Transactional);

        $this->get($this->signedUnsubscribeUrl($lead))->assertOk();

        $this->assertDatabaseHas('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'reason' => ConsentRevocation::REASON_UNSUBSCRIBE,
        ]);

        $this->assertDatabaseMissing('consent_revocations', [
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
        ]);

        $this->assertTrue(
            app(MessageEligibilityGate::class)->canSend(
                $lead->refresh(),
                MessageChannel::Email,
                MessagePurpose::Transactional,
            )
        );
    }

    private function leadWithConsent(MessagePurpose $purpose): Lead
    {
        $lead = $this->createLead();

        $this->grantConsent($lead, $purpose);

        return $lead;
    }

    private function createLead(): Lead
    {
        return Lead::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'name' => 'Test Lead',
            'email' => 'test@example.com',
            'phone' => '+15555555555',
        ]);
    }

    private function grantConsent(Lead $lead, MessagePurpose $purpose): void
    {
        DB::table('message_consents')->insert([
            'recipient_type' => $lead->getMorphClass(),
            'recipient_id' => $lead->id,
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

    private function messageConsentId(Lead $lead, MessagePurpose $purpose): int
    {
        return DB::table('message_consents')
            ->where('recipient_type', $lead->getMorphClass())
            ->where('recipient_id', $lead->id)
            ->where('channel', MessageChannel::Email->value)
            ->where('purpose', $purpose->value)
            ->value('id');
    }

    private function signedUnsubscribeUrl(Lead $lead): string
    {
        return URL::temporarySignedRoute(
            name: 'messaging.email.unsubscribe',
            expiration: now()->addDays(7),
            parameters: ['lead' => $lead],
        );
    }
}