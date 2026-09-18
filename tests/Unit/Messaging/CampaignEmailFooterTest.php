<?php

namespace Tests\Unit\Messaging;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\CampaignEmailFooter;
use Tests\TestCase;

class CampaignEmailFooterTest extends TestCase
{
    public function test_it_adds_structured_contact_details_only_to_the_target_campaign_email(): void
    {
        config()->set('messaging.campaign_email_contact_blocks', [
            'cold_lead_nurture' => [
                'phone' => '(407) 761-3797',
                'email' => 'stacey@slamdunkhomeloans.com',
                'schedule_url' => 'https://calendly.com/stacey-slamdunkhomeloans/consult',
                'schedule_label' => 'Schedule a consultation',
            ],
        ]);

        $message = $this->message('cold_lead_nurture');
        $payload = ['subject' => 'Edited subject', 'body' => 'Edited body'];
        $actual = (new CampaignEmailFooter)->apply($message, $payload);

        $this->assertSame($payload['subject'], $actual['subject']);
        $this->assertSame($payload['body'], $actual['body']);
        $this->assertSame([
            'phone' => '(407) 761-3797',
            'phone_url' => 'tel:+14077613797',
            'email' => 'stacey@slamdunkhomeloans.com',
            'email_url' => 'mailto:stacey@slamdunkhomeloans.com',
            'schedule_url' => 'https://calendly.com/stacey-slamdunkhomeloans/consult',
            'schedule_label' => 'Schedule a consultation',
        ], $actual['campaign_contact']);
        $this->assertArrayNotHasKey('footer', $actual);

        $custom = (new CampaignEmailFooter)->apply($message, $payload + [
            'campaign_contact' => ['phone' => 'Existing block'],
        ]);
        $this->assertSame(['phone' => 'Existing block'], $custom['campaign_contact']);

        $other = (new CampaignEmailFooter)->apply($this->message('past_client_nurture'), $payload);
        $this->assertArrayNotHasKey('campaign_contact', $other);

        $direct = $this->message('cold_lead_nurture');
        $direct->message_type = 'contact_direct_message';
        $this->assertArrayNotHasKey('campaign_contact', (new CampaignEmailFooter)->apply($direct, $payload));

        $sms = $this->message('cold_lead_nurture');
        $sms->channel = 'sms';
        $this->assertArrayNotHasKey('campaign_contact', (new CampaignEmailFooter)->apply($sms, $payload));
    }

    public function test_it_accepts_the_legacy_multiline_footer_config_as_a_compatibility_fallback(): void
    {
        config()->set('messaging.campaign_email_contact_blocks', []);
        config()->set('messaging.campaign_email_footers', [
            'cold_lead_nurture' => "Phone - (407) 761-3797\nEmail - stacey@slamdunkhomeloans.com\nSchedule - https://calendly.com/stacey-slamdunkhomeloans/consult",
        ]);

        $actual = (new CampaignEmailFooter)->apply(
            $this->message('cold_lead_nurture'),
            ['subject' => 'Subject', 'body' => 'Body'],
        );

        $this->assertSame('(407) 761-3797', data_get($actual, 'campaign_contact.phone'));
        $this->assertSame('stacey@slamdunkhomeloans.com', data_get($actual, 'campaign_contact.email'));
        $this->assertSame(
            'https://calendly.com/stacey-slamdunkhomeloans/consult',
            data_get($actual, 'campaign_contact.schedule_url'),
        );
    }

    private function message(string $campaignKey): ScheduledMessage
    {
        return new ScheduledMessage([
            'channel' => 'email',
            'purpose' => 'marketing',
            'message_type' => 'campaign_step',
            'meta' => ['campaign_key' => $campaignKey],
        ]);
    }
}