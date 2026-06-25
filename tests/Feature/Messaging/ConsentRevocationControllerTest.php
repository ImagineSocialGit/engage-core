<?php

namespace Tests\Feature\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\CampaignEnrollment;
use App\Models\ConsentRevocation;
use App\Models\Contact;
use App\Services\Messaging\MessageEligibilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ConsentRevocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_transactional_opt_out_revokes_only_requested_scope(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Transactional, 'webinar');
        $this->grantConsent($contact, MessagePurpose::Transactional, 'waitlist');

        $url = URL::temporarySignedRoute(
            'messaging.email.transactional-opt-out',
            now()->addDays(7),
            [
                'contact' => $contact,
                'scope' => 'webinar',
            ]
        );

        $this->get($url)
            ->assertOk()
            ->assertViewIs('messaging.transactional-opt-out-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'waitlist',
        ]);
    }

    public function test_marketing_unsubscribe_revokes_all_marketing_scopes(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Marketing, 'webinar');
        $this->grantConsent($contact, MessagePurpose::Marketing, 'waitlist');

        $this->get($this->signedUnsubscribeUrl($contact))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
        ]);

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'waitlist',
        ]);

        $this->assertSame(
            2,
            ConsentRevocation::query()->count()
        );
    }

    public function test_unsigned_marketing_unsubscribe_is_rejected(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $this->get(route(
            'messaging.email.unsubscribe',
            ['contact' => $contact]
        ))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseCount(
            'consent_revocations',
            0
        );
    }

    public function test_expired_marketing_unsubscribe_is_rejected(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $url = URL::temporarySignedRoute(
            'messaging.email.unsubscribe',
            now()->subMinute(),
            [
                'contact' => $contact,
            ]
        );

        $this->get($url)
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseCount(
            'consent_revocations',
            0
        );
    }

    public function test_marketing_unsubscribe_is_idempotent(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $url = $this->signedUnsubscribeUrl($contact);

        $this->get($url)
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->get($url)
            ->assertViewIs('messaging.unsubscribe-already-confirmed');

        $this->assertSame(
            1,
            ConsentRevocation::query()->count()
        );
    }

    public function test_gate_blocks_marketing_after_unsubscribe(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $gate = app(MessageEligibilityGate::class);

        $this->assertTrue(
            $gate->canSend(
                $contact,
                MessageChannel::Email,
                MessagePurpose::Marketing,
                'webinar',
            )
        );

        $this->get(
            $this->signedUnsubscribeUrl($contact)
        );

        $this->assertFalse(
            $gate->canSend(
                $contact->refresh(),
                MessageChannel::Email,
                MessagePurpose::Marketing,
                'webinar',
            )
        );
    }

    public function test_marketing_unsubscribe_does_not_revoke_transactional(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $this->grantConsent(
            $contact,
            MessagePurpose::Transactional,
            'webinar'
        );

        $this->get(
            $this->signedUnsubscribeUrl($contact)
        );

        $this->assertDatabaseMissing(
            'consent_revocations',
            [
                'contact_id' => $contact->id,
                'purpose' => MessagePurpose::Transactional->value,
            ]
        );
    }

    public function test_marketing_unsubscribe_does_not_mutate_campaign_enrollments(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Marketing, 'webinar');

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 2,
            'started_at' => now()->subDays(2),
        ]);

        $this->get($this->signedUnsubscribeUrl($contact))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertNull($enrollment->paused_at);
    }

    private function createContact(): Contact
    {
        return Contact::factory()->create();
    }

    private function grantConsent(
        Contact $contact,
        MessagePurpose $purpose,
        string $scope
    ): void {
        DB::table('message_consents')->insert([
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => $purpose->value,
            'scope' => $scope,
            'consented_at' => now(),
            'source' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function signedUnsubscribeUrl(
        Contact $contact
    ): string {
        return URL::temporarySignedRoute(
            'messaging.email.unsubscribe',
            now()->addDays(7),
            [
                'contact' => $contact,
            ]
        );
    }
}