<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Core\Models\Contact;
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
}