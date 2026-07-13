<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarNurtureFirstAvailableVariantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Carbon::setTestNow('2026-07-13 12:00:00');

        $this->configureMessagingDefinitions();
        $this->configureChannelAvailability();
    }

    public function test_sms_is_preferred_when_sms_and_email_are_both_eligible(): void
    {
        $campaign = $this->createCampaign();
        $contact = $this->createContactWithConsent(email: true, sms: true);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $scheduledMessage = ScheduledMessage::query()->sole();

        $this->assertSame('sms', $scheduledMessage->channel);
        $this->assertSame('sms', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
        $this->assertTrue($scheduledMessage->send_at->equalTo(now()->addDays(7)));
    }

    public function test_email_is_used_when_sms_is_unavailable(): void
    {
        Config::set('messaging.channel_availability.sms.provider_enabled', false);

        $campaign = $this->createCampaign();
        $contact = $this->createContactWithConsent(email: true, sms: true);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $scheduledMessage = ScheduledMessage::query()->sole();

        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('email', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
        $this->assertTrue($scheduledMessage->send_at->equalTo(now()->addDays(7)));
    }

    public function test_first_available_never_schedules_both_variants(): void
    {
        $campaign = $this->createCampaign();
        $contact = $this->createContactWithConsent(email: true, sms: true);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $this->assertSame(
            ['sms'],
            ScheduledMessage::query()
                ->get()
                ->map(fn (ScheduledMessage $message): ?string => data_get($message->meta, 'campaign_step_variant_key'))
                ->all(),
        );
    }

    private function createCampaign(): Campaign
    {
        $campaign = Campaign::factory()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
        ]);

        $step = CampaignStep::factory()
            ->forCampaign($campaign)
            ->create([
                'step_number' => 1,
                'name' => 'Attended webinar follow-up',
                'variant_strategy' => 'first_available',
                'criteria' => [
                    'timing' => [
                        'type' => 'delay',
                        'days' => 7,
                    ],
                ],
            ]);

        CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'name' => 'SMS follow-up',
            'sort_order' => 10,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
        ]);

        CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email fallback',
            'sort_order' => 20,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
        ]);

        return $campaign->refresh();
    }

    private function createContactWithConsent(bool $email, bool $sms): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'email' => 'jeff@example.com',
            'phone' => '+15555550123',
        ]);

        if ($email) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        if ($sms) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => MessageChannel::Sms->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    private function configureMessagingDefinitions(): void
    {
        Config::set(
            'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
            [
                'dispatch_key' => 'campaign_step_due',
                'payload_class' => SmsPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'message' => 'Thanks for joining the webinar, {first_name}. Reply with your biggest question and we’ll help with the next step. Reply STOP to opt out.',
                ],
            ],
        );

        Config::set(
            'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            [
                'dispatch_key' => 'campaign_step_due',
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',
                'payload' => [
                    'subject' => 'Thanks again for joining',
                    'body' => 'Hi {first_name}, thanks again for joining. Reply if you still have questions or want help with your next step.',
                ],
            ],
        );
    }

    private function configureChannelAvailability(): void
    {
        Config::set('messaging.channel_availability.email', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => false,
            'surfaces' => [
                'campaigns' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar_nurture' => true,
            ],
        ]);

        Config::set('messaging.channel_availability.sms', [
            'runtime_supported' => true,
            'provider_enabled' => true,
            'requires_explicit_opt_in' => true,
            'surfaces' => [
                'campaigns' => true,
            ],
            'purpose_scopes' => [
                'marketing:webinar_nurture' => true,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
