<?php

namespace Tests\Feature\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\CampaignEmailFooter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEmailFooterRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_campaign_footer_from_real_message_chain_identity(): void
    {
        config()->set('messaging.campaign_email_contact_blocks', [
            'cold_lead_nurture' => [
                'phone' => '(407) 761-3797',
                'email' => 'stacey@slamdunkhomeloans.com',
                'schedule_url' => 'https://calendly.com/stacey-slamdunkhomeloans/consult',
                'schedule_label' => 'Schedule a consultation',
            ],
        ]);

        $message = $this->messageForChain(
            chainKey: 'campaign.cold_lead_nurture',
            surface: 'campaigns',
            messageType: 'cold_lead_nurture_step_1',
        );
        $payload = [
            'subject' => 'Prospect follow-up',
            'body' => 'Hi Jeff, thanks for connecting with us.',
        ];

        $actual = app(CampaignEmailFooter::class)->apply(
            $message->fresh(),
            $payload,
        );

        $this->assertSame($payload['subject'], $actual['subject']);
        $this->assertSame($payload['body'], $actual['body']);
        $this->assertSame('(407) 761-3797', data_get($actual, 'campaign_contact.phone'));
        $this->assertSame('tel:+14077613797', data_get($actual, 'campaign_contact.phone_url'));
        $this->assertSame(
            'https://calendly.com/stacey-slamdunkhomeloans/consult',
            data_get($actual, 'campaign_contact.schedule_url'),
        );
        $this->assertArrayNotHasKey('footer', $actual);
    }

    public function test_it_does_not_treat_a_non_campaign_surface_as_campaign_identity(): void
    {
        config()->set('messaging.campaign_email_contact_blocks', [
            'cold_lead_nurture' => [
                'email' => 'stacey@slamdunkhomeloans.com',
            ],
        ]);

        $message = $this->messageForChain(
            chainKey: 'campaign.cold_lead_nurture',
            surface: 'webinars',
            messageType: 'cold_lead_nurture_step_1',
        );

        $actual = app(CampaignEmailFooter::class)->apply(
            $message->fresh(),
            ['subject' => 'Subject', 'body' => 'Body'],
        );

        $this->assertArrayNotHasKey('campaign_contact', $actual);
    }

    private function messageForChain(
        string $chainKey,
        string $surface,
        string $messageType,
    ): ScheduledMessage {
        $contact = Contact::factory()->create();

        $chain = MessageChain::query()->create([
            'key' => $chainKey,
            'name' => 'Campaign footer fixture',
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'campaign_preset_bridge',
            'is_customized' => false,
        ]);

        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', $chainKey.'|'.$surface.'|'.$messageType),
            'published_at' => now(),
        ]);

        $chain->forceFill([
            'current_version_id' => $version->getKey(),
        ])->save();

        $enrollment = MessageChainEnrollment::query()->create([
            'message_chain_version_id' => $version->getKey(),
            'recipient_type' => $contact->getMorphClass(),
            'recipient_id' => $contact->getKey(),
            'context_type' => null,
            'context_id' => null,
            'origin_type' => null,
            'origin_id' => null,
            'surface' => $surface,
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'status' => MessageChainEnrollment::STATUS_ACTIVE,
            'dedupe_key' => 'campaign-footer-runtime:'.hash(
                'sha256',
                $chainKey.'|'.$surface.'|'.$messageType,
            ),
            'started_at' => now(),
        ]);

        return ScheduledMessage::factory()
            ->forRecipient($contact)
            ->create([
                'message_chain_enrollment_id' => $enrollment->getKey(),
                'channel' => 'email',
                'purpose' => 'marketing',
                'message_type' => $messageType,
                'meta' => [],
            ]);
    }
}