<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\ConsentRevocation;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageSuppression;
use App\Modules\Messaging\Services\MessageEligibilityGate;
use App\Modules\Messaging\Services\MessageSuppressionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageEligibilityGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_email_suppression_blocks_otherwise_eligible_contact(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'scope' => 'webinar',
            'purpose' => MessagePurpose::Transactional->value,
            'consented_at' => now(),
            'source' => 'test',
        ]);

        app(MessageSuppressionService::class)->suppress(
            channel: MessageChannel::Email,
            destination: 'person@example.com',
            reason: MessageSuppression::REASON_BOUNCE,
            provider: MessageSuppression::PROVIDER_RESEND,
            sourceEventId: 'evt_bounce_1',
        );

        $this->assertFalse(
            app(MessageEligibilityGate::class)->canSend(
                $contact,
                MessageChannel::Email,
                MessagePurpose::Transactional,
                'webinar'
            )
        );
    }

    public function test_imported_email_permission_pass_allows_marketing_email_without_consent(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'imported@example.com',
            'source' => 'import',
        ]);

        $this->assertTrue(
            app(MessageEligibilityGate::class)->allows(
                contact: $contact,
                channel: MessageChannel::Email,
                purpose: MessagePurpose::Marketing,
                scope: 'broadcast',
                context: [
                    'consent_policy' => [
                        'imported_contact_permission_pass' => true,
                    ],
                ],
            )
        );
    }

    public function test_imported_email_permission_pass_does_not_apply_to_sms(): void
    {
        $contact = Contact::factory()->create([
            'phone' => '+15555550123',
            'source' => 'import',
        ]);

        $this->assertFalse(
            app(MessageEligibilityGate::class)->allows(
                contact: $contact,
                channel: MessageChannel::Sms,
                purpose: MessagePurpose::Marketing,
                scope: 'broadcast',
                context: [
                    'consent_policy' => [
                        'imported_contact_permission_pass' => true,
                    ],
                ],
            )
        );
    }

    public function test_imported_email_permission_pass_does_not_apply_to_non_imported_contacts(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'organic@example.com',
            'source' => 'webinar',
        ]);

        $this->assertFalse(
            app(MessageEligibilityGate::class)->allows(
                contact: $contact,
                channel: MessageChannel::Email,
                purpose: MessagePurpose::Marketing,
                scope: 'broadcast',
                context: [
                    'consent_policy' => [
                        'imported_contact_permission_pass' => true,
                    ],
                ],
            )
        );
    }

    public function test_imported_email_permission_pass_does_not_override_revocation(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'revoked@example.com',
            'source' => 'import',
        ]);

        ConsentRevocation::query()->create([
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'broadcast',
            'revoked_at' => now(),
            'source' => 'test',
        ]);

        $this->assertFalse(
            app(MessageEligibilityGate::class)->allows(
                contact: $contact,
                channel: MessageChannel::Email,
                purpose: MessagePurpose::Marketing,
                scope: 'broadcast',
                context: [
                    'consent_policy' => [
                        'imported_contact_permission_pass' => true,
                    ],
                ],
            )
        );
    }
}