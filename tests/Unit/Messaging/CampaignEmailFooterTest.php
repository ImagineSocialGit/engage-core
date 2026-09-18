<?php

namespace Tests\Unit\Messaging;

use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\CampaignEmailFooter;
use Tests\TestCase;

class CampaignEmailFooterTest extends TestCase
{
    public function test_it_adds_the_current_footer_only_to_the_target_campaign_email(): void
    {
        config()->set('messaging.campaign_email_footers', [
            'cold_lead_nurture' => "Phone - (407) 761-3797\nEmail - stacey@slamdunkhomeloans.com\nSchedule - https://calendly.com/stacey-slamdunkhomeloans/consult",
        ]);

        $message = $this->message('cold_lead_nurture');
        $payload = ['subject' => 'Edited subject', 'body' => 'Edited body'];
        $actual = (new CampaignEmailFooter)->apply($message, $payload);

        $this->assertSame($payload['subject'], $actual['subject']);
        $this->assertSame($payload['body'], $actual['body']);
        $this->assertSame(config('messaging.campaign_email_footers.cold_lead_nurture'), $actual['footer']);

        $custom = (new CampaignEmailFooter)->apply($message, $payload + ['footer' => 'Custom signature']);
        $this->assertSame('Custom signature', $custom['footer']);

        $other = (new CampaignEmailFooter)->apply($this->message('past_client_nurture'), $payload);
        $this->assertArrayNotHasKey('footer', $other);

        $direct = $this->message('cold_lead_nurture');
        $direct->message_type = 'contact_direct_message';
        $this->assertArrayNotHasKey('footer', (new CampaignEmailFooter)->apply($direct, $payload));

        $sms = $this->message('cold_lead_nurture');
        $sms->channel = 'sms';
        $this->assertArrayNotHasKey('footer', (new CampaignEmailFooter)->apply($sms, $payload));
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