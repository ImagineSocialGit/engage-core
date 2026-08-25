<?php

namespace Tests\Feature\Messaging;

use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ConsentRevocationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_transactional_opt_out_requires_confirmation_and_revokes_transactional_purpose_boundary(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Transactional, 'webinar');
        $this->grantConsent($contact, MessagePurpose::Transactional, 'waitlist');

        $response = $this->get(
            $this->signedTransactionalOptOutUrl($contact, 'webinar')
        );

        $response
            ->assertOk()
            ->assertViewIs('messaging.transactional-opt-out-confirm')
            ->assertViewHas('confirmUrl');

        $this->assertDatabaseCount('consent_revocations', 0);

        $this->post($response->viewData('confirmUrl'))
            ->assertOk()
            ->assertViewIs('messaging.transactional-opt-out-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Transactional->value,
            'scope' => 'webinar',
        ]);

        $this->assertFalse(app(MessageEligibilityGate::class)->canSend(
            $contact,
            MessageChannel::Email,
            MessagePurpose::Transactional,
            'waitlist',
        ));
    }

    public function test_marketing_unsubscribe_requires_confirmation_before_revoking_channel_purpose_boundary(): void
    {
        $contact = $this->createContact();

        $this->grantConsent($contact, MessagePurpose::Marketing, 'webinar');
        $this->grantConsent($contact, MessagePurpose::Marketing, 'waitlist');

        $response = $this->get($this->signedUnsubscribeUrl($contact));

        $response
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirm')
            ->assertViewHas('confirmUrl');

        $this->assertDatabaseCount('consent_revocations', 0);

        $this->post($response->viewData('confirmUrl'))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->assertDatabaseHas('consent_revocations', [
            'contact_id' => $contact->id,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'channel_purpose',
        ]);

        $this->assertSame(1, ConsentRevocation::query()->count());
        $this->assertFalse(app(MessageEligibilityGate::class)->canSend(
            $contact,
            MessageChannel::Email,
            MessagePurpose::Marketing,
            'waitlist',
        ));
    }

    public function test_unsigned_marketing_unsubscribe_get_is_rejected(): void
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

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_unsigned_marketing_unsubscribe_post_is_rejected(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $this->post(route(
            'messaging.email.unsubscribe.store',
            ['contact' => $contact]
        ))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-invalid');

        $this->assertDatabaseCount('consent_revocations', 0);
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

        $this->assertDatabaseCount('consent_revocations', 0);
    }

    public function test_marketing_unsubscribe_post_is_idempotent(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $confirmUrl = $this->marketingConfirmationUrl($contact);

        $this->post($confirmUrl)
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $this->post($confirmUrl)
            ->assertViewIs('messaging.unsubscribe-already-confirmed');

        $this->assertSame(1, ConsentRevocation::query()->count());
    }

    public function test_gate_blocks_marketing_after_confirmed_unsubscribe(): void
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

        $this->post($this->marketingConfirmationUrl($contact));

        $this->assertFalse(
            $gate->canSend(
                $contact->refresh(),
                MessageChannel::Email,
                MessagePurpose::Marketing,
                'webinar',
            )
        );
    }

    public function test_marketing_unsubscribe_does_not_revoke_transactional_consent(): void
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

        $this->post($this->marketingConfirmationUrl($contact));

        $this->assertDatabaseMissing('consent_revocations', [
            'contact_id' => $contact->id,
            'purpose' => MessagePurpose::Transactional->value,
        ]);
    }

    public function test_marketing_unsubscribe_does_not_mutate_campaign_enrollments(): void
    {
        $contact = $this->createContact();

        $this->grantConsent(
            $contact,
            MessagePurpose::Marketing,
            'webinar'
        );

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_key' => 'webinar_attended',
            'start_context' => ['source' => 'unsubscribe-test'],
            'started_at' => now()->subDays(2),
            'meta' => ['marker' => 'unchanged'],
        ])->fresh();
        $updatedAt = $enrollment->updated_at?->copy();

        $this->post($this->marketingConfirmationUrl($contact))
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirmed');

        $enrollment->refresh();

        $this->assertEquals(['source' => 'unsubscribe-test'], $enrollment->start_context);
        $this->assertEquals(['marker' => 'unchanged'], $enrollment->meta);
        $this->assertTrue($enrollment->updated_at?->equalTo($updatedAt) ?? false);
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

    private function marketingConfirmationUrl(Contact $contact): string
    {
        $response = $this->get(
            $this->signedUnsubscribeUrl($contact)
        );

        $response
            ->assertOk()
            ->assertViewIs('messaging.unsubscribe-confirm');

        return $response->viewData('confirmUrl');
    }

    private function signedUnsubscribeUrl(Contact $contact): string
    {
        return URL::temporarySignedRoute(
            'messaging.email.unsubscribe',
            now()->addDays(7),
            [
                'contact' => $contact,
            ]
        );
    }

    private function signedTransactionalOptOutUrl(
        Contact $contact,
        string $scope
    ): string {
        return URL::temporarySignedRoute(
            'messaging.email.transactional-opt-out',
            now()->addDays(7),
            [
                'contact' => $contact,
                'scope' => $scope,
            ]
        );
    }
}