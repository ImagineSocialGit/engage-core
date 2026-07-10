<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Actions\SyncCampaignPresetsAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
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

class CampaignStepVariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_preset_sync_creates_campaign_step_variants_without_copy_payloads(): void
    {
        $this->configurePresetWithVariants();

        $result = app(SyncCampaignPresetsAction::class)->handle('test_client');

        $this->assertSame(1, $result->campaignsCreated);
        $this->assertSame(1, $result->stepsCreated);
        $this->assertSame(2, $result->variantsCreated);

        $campaign = Campaign::query()->where('key', 'webinar_attended_nurture')->firstOrFail();
        $step = $campaign->steps()->firstOrFail();

        $this->assertSame('send_all_eligible', $step->variant_strategy);
        $this->assertDatabaseHas('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ]);
        $this->assertDatabaseHas('campaign_step_variants', [
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ]);

        foreach (CampaignStepVariant::query()->get() as $variant) {
            $this->assertNull(data_get($variant->meta, 'payload'));
            $this->assertNull(data_get($variant->meta, 'subject'));
            $this->assertNull(data_get($variant->meta, 'body'));
            $this->assertNull(data_get($variant->meta, 'message'));
        }
    }

    public function test_first_available_strategy_schedules_only_the_first_eligible_variant(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithVariants('first_available');
        $contact = $this->contactWithConsent(email: true, sms: true);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertDatabaseCount('scheduled_messages', 1);

        $scheduledMessage = ScheduledMessage::query()->firstOrFail();
        $emailVariant = $campaign->steps()->firstOrFail()->variants()->where('key', 'email')->firstOrFail();

        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('webinar_attended_nurture_step_1', $scheduledMessage->message_type);
        $this->assertSame($emailVariant->getMorphClass(), $scheduledMessage->behavior_owner_type);
        $this->assertSame($emailVariant->getKey(), $scheduledMessage->behavior_owner_id);
        $this->assertTrue($scheduledMessage->behaviorOwner->is($emailVariant));
        $this->assertSame($emailVariant->id, data_get($scheduledMessage->meta, 'campaign_step_variant_id'));
        $this->assertSame('email', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
        $this->assertSame('first_available', data_get($scheduledMessage->meta, 'campaign_variant_strategy'));
    }

    public function test_send_all_eligible_strategy_schedules_each_eligible_variant(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithVariants('send_all_eligible');
        $contact = $this->contactWithConsent(email: true, sms: true);

        $enrollment = app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertDatabaseCount('scheduled_messages', 2);

        $channels = ScheduledMessage::query()->orderBy('channel')->pluck('channel')->all();

        $this->assertSame(['email', 'sms'], $channels);
        $this->assertSame(
            ['email', 'sms'],
            collect(data_get($enrollment->fresh()->meta, 'last_campaign_step_variant_attempts', []))
                ->where('result', 'scheduled')
                ->pluck('variant_key')
                ->values()
                ->all(),
        );
    }

    public function test_campaign_generated_scheduled_payloads_stay_compact_and_variant_identity_lives_in_meta(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithVariants('first_available');
        $contact = $this->contactWithConsent(email: true, sms: false);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
            payload: [
                'caller_supplied_token' => 'small-value',
            ],
        );

        $scheduledMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertArrayNotHasKey('campaign', $scheduledMessage->payload);
        $this->assertArrayNotHasKey('campaign_step', $scheduledMessage->payload);
        $this->assertArrayNotHasKey('campaign_step_variant', $scheduledMessage->payload);
        $this->assertArrayNotHasKey('enrollment', $scheduledMessage->payload);

        $this->assertSame($campaign->id, data_get($scheduledMessage->meta, 'campaign_id'));
        $this->assertSame('email', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
        $this->assertNotNull(data_get($scheduledMessage->meta, 'campaign_step_variant_id'));
    }

    private function configurePresetWithVariants(): void
    {
        Config::set('presets.packages.test_client.groups.campaigns', ['webinar_default']);
        Config::set('presets.campaigns.groups.webinar_default', ['webinar_attended_nurture']);
        Config::set('presets.campaigns.definitions.webinar_attended_nurture', [
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'description' => 'Follow-up sequence for webinar attendees.',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => 'active',
            'is_active' => true,
            'steps' => [[
                'step_number' => 1,
                'name' => 'Initial follow-up',
                'dispatch_key' => 'campaign_step_due',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'variant_strategy' => 'send_all_eligible',
                'criteria' => [
                    'timing' => [
                        'type' => 'delay',
                        'hours' => 2,
                    ],
                ],
                'variants' => [
                    [
                        'key' => 'email',
                        'name' => 'Email follow-up',
                        'sort_order' => 0,
                        'channel' => 'email',
                        'purpose' => 'marketing',
                        'scope' => 'webinar_nurture',
                    ],
                    [
                        'key' => 'sms',
                        'name' => 'SMS follow-up',
                        'sort_order' => 1,
                        'channel' => 'sms',
                        'purpose' => 'marketing',
                        'scope' => 'webinar_nurture',
                    ],
                ],
            ]],
        ]);
    }

    private function configureCampaignMessagingDefinitions(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Thanks for joining',
                'body' => 'Hi {first_name}, thanks for joining.',
            ],
        ]);

        Config::set('messaging.sms.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'message' => 'Hi {first_name}, thanks for joining.',
            ],
        ]);
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

    private function createCampaignWithVariants(string $strategy): Campaign
    {
        $campaign = Campaign::query()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $step = CampaignStep::query()->create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'Initial follow-up',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => $strategy,
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'minutes' => 30,
                ],
            ],
            'meta' => [
                'type' => 'message',
            ],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'name' => 'SMS follow-up',
            'sort_order' => 1,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        return $campaign->refresh();
    }

    private function contactWithConsent(bool $email, bool $sms): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'name' => 'Jeff Yarnall',
            'email' => 'jeff@example.com',
            'phone' => '+15555550123',
        ]);

        if ($email) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar_nurture',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        if ($sms) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => MessageChannel::Sms->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'webinar_nurture',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }
}
