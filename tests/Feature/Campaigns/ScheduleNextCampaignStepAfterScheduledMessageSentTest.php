<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleCampaignStepMessagesAction;
use App\Modules\Campaigns\Listeners\ScheduleNextCampaignStepAfterScheduledMessageSent;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Events\ScheduledMessageSent;
use App\Modules\Messaging\Events\ScheduledMessageSkipped;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleNextCampaignStepAfterScheduledMessageSentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_next_campaign_step_variant_when_sent_message_has_campaign_metadata(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();
        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();
        $stepTwo = $campaign->steps()->where('step_number', 2)->firstOrFail();
        $variantTwo = $stepTwo->variants()->where('key', 'email')->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => now(),
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'webinar_attended_step_1',
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $stepOne->id,
                'campaign_step' => 1,
                'campaign_step_waits_for_all_scheduled_variants' => false,
            ],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(new ScheduledMessageSent($sentMessage));

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertNotNull($enrollment->last_scheduled_message_id);
        $this->assertNotSame($sentMessage->id, $enrollment->last_scheduled_message_id);

        $nextMessage = ScheduledMessage::query()->whereKey($enrollment->last_scheduled_message_id)->firstOrFail();

        $this->assertSame('webinar_attended_step_2', $nextMessage->message_type);
        $this->assertSame('email', $nextMessage->channel);
        $this->assertSame('marketing', $nextMessage->purpose);
        $this->assertSame('webinar', $nextMessage->scope);
        $this->assertSame('marketing', $nextMessage->queue);
        $this->assertSame(['campaign_step_due'], $nextMessage->dispatch_keys);
        $this->assertSame('messaging.email.definitions.marketing.webinar.campaigns.webinar_attended.steps.2.variants.email', $nextMessage->definition_config_path);
        $this->assertSame($enrollment->id, $nextMessage->meta['campaign_enrollment_id']);
        $this->assertSame($campaign->id, $nextMessage->meta['campaign_id']);
        $this->assertSame('webinar_attended', $nextMessage->meta['campaign_key']);
        $this->assertSame(2, $nextMessage->meta['campaign_step']);
        $this->assertSame($stepTwo->id, $nextMessage->meta['campaign_step_id']);
        $this->assertSame($variantTwo->id, $nextMessage->meta['campaign_step_variant_id']);
        $this->assertSame('email', $nextMessage->meta['campaign_step_variant_key']);
        $this->assertSame($sentMessage->id, $nextMessage->meta['previous_scheduled_message_id']);
    }

    public function test_it_waits_for_all_scheduled_variants_and_advances_when_the_last_sibling_is_skipped(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();
        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();
        $stepTwo = $campaign->steps()->where('step_number', 2)->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => now(),
        ]);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'webinar_attended_step_1',
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $stepOne->id,
                'campaign_step' => 1,
                'campaign_step_waits_for_all_scheduled_variants' => true,
            ],
        ]);

        $pendingSibling = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'webinar_attended_step_1',
            'status' => ScheduledMessage::STATUS_PENDING,
            'send_at' => now()->addMinute(),
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $stepOne->id,
                'campaign_step' => 1,
                'campaign_step_waits_for_all_scheduled_variants' => true,
            ],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(new ScheduledMessageSent($sentMessage));

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame($stepOne->id, $enrollment->current_campaign_step_id);
        $this->assertNull($enrollment->last_scheduled_message_id);
        $this->assertDatabaseCount('scheduled_messages', 2);

        $pendingSibling->forceFill([
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'Message conditions no longer pass.',
        ])->save();

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(new ScheduledMessageSkipped($pendingSibling));

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertNotNull($enrollment->last_scheduled_message_id);

        $nextMessage = ScheduledMessage::query()->whereKey($enrollment->last_scheduled_message_id)->firstOrFail();

        $this->assertSame('webinar_attended_step_2', $nextMessage->message_type);
        $this->assertSame($pendingSibling->id, $nextMessage->meta['previous_scheduled_message_id']);
    }

    public function test_it_re_evaluates_dependency_aware_current_step_after_sms_is_sent_before_advancing(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureDependencyAwareCampaignMessagingDefinitions();
        $this->configureDependencyAwareCampaignChannelAvailability();

        $campaign = $this->createDependencyAwareSingleStepCampaign();
        $step = $campaign->steps()->with('variants')->firstOrFail();

        $contact = Contact::factory()->create([
            'email' => 'person@example.com',
            'phone' => '+15555550123',
        ]);

        foreach (['email', 'sms'] as $channel) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => $channel,
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        $enrollment = CampaignEnrollment::query()->create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $step->id,
            'started_at' => now(),
            'meta' => [],
        ]);

        app(ScheduleCampaignStepMessagesAction::class)->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $smsMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame('sms', $smsMessage->channel);

        $smsMessage->forceFill([
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(
            new ScheduledMessageSent($smsMessage),
        );

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame($step->id, $enrollment->current_campaign_step_id);
        $this->assertDatabaseCount('scheduled_messages', 2);

        $emailMessage = ScheduledMessage::query()
            ->where('channel', 'email')
            ->firstOrFail();

        $this->assertSame(ScheduledMessage::STATUS_PENDING, $emailMessage->status);
        $this->assertSame($enrollment->id, data_get($emailMessage->meta, 'campaign_enrollment_id'));
        $this->assertSame($step->id, data_get($emailMessage->meta, 'campaign_step_id'));
        $this->assertSame('email', data_get($emailMessage->meta, 'campaign_step_variant_key'));
        $this->assertSame($smsMessage->id, data_get($emailMessage->meta, 'previous_scheduled_message_id'));
    }

    public function test_it_does_not_advance_again_when_terminal_message_event_belongs_to_previous_campaign_step(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();
        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();
        $stepTwo = $campaign->steps()->where('step_number', 2)->firstOrFail();

        $existingStepTwoMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'webinar_attended_step_2',
            'status' => ScheduledMessage::STATUS_PENDING,
            'send_at' => now()->addMinutes(720),
            'meta' => [
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $stepTwo->id,
                'campaign_step' => 2,
            ],
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 2,
            'current_campaign_step_id' => $stepTwo->id,
            'last_scheduled_message_id' => $existingStepTwoMessage->id,
            'started_at' => now(),
        ]);

        $oldStepOneMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'message_type' => 'webinar_attended_step_1',
            'status' => ScheduledMessage::STATUS_SKIPPED,
            'skipped_at' => now(),
            'skip_reason' => 'Message conditions no longer pass.',
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => 'webinar_attended',
                'campaign_step_id' => $stepOne->id,
                'campaign_step' => 1,
                'campaign_step_waits_for_all_scheduled_variants' => true,
            ],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(
            new ScheduledMessageSkipped($oldStepOneMessage),
        );

        $enrollment->refresh();

        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertSame($existingStepTwoMessage->id, $enrollment->last_scheduled_message_id);
        $this->assertDatabaseCount('scheduled_messages', 2);
    }

    public function test_it_does_nothing_when_sent_message_has_no_campaign_metadata(): void
    {
        Queue::fake();

        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        $sentMessage = ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'message_type' => 'confirmation',
            'status' => ScheduledMessage::STATUS_SENT,
            'sent_at' => now(),
            'meta' => [],
        ]);

        app(ScheduleNextCampaignStepAfterScheduledMessageSent::class)->handle(new ScheduledMessageSent($sentMessage));

        $this->assertDatabaseCount('campaign_enrollments', 0);
        $this->assertDatabaseCount('scheduled_messages', 1);
    }

    private function createCampaignWithSteps(): Campaign
    {
        $campaign = Campaign::create([
            'key' => 'webinar_attended',
            'name' => 'Webinar Attended',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->defineCampaignStepVariantMessageTemplate(1, 'First message');
        $this->createStepWithEmailVariant($campaign, 1);

        $this->defineCampaignStepVariantMessageTemplate(2, 'Second message');
        $this->createStepWithEmailVariant($campaign, 2);

        return $campaign->refresh();
    }

    private function createStepWithEmailVariant(Campaign $campaign, int $stepNumber): CampaignStep
    {
        $step = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => $stepNumber,
            'name' => 'Step '.$stepNumber,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'variant_strategy' => 'first_available',
            'is_active' => true,
            'criteria' => ['timing' => ['type' => 'delay', 'minutes' => 720]],
            'meta' => ['type' => 'message'],
        ]);

        CampaignStepVariant::create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'source_config_path' => "messaging.email.definitions.marketing.webinar.campaigns.{$campaign->key}.steps.{$stepNumber}.variants.email",
            'meta' => [],
        ]);

        return $step;
    }

    private function defineCampaignStepVariantMessageTemplate(int $stepNumber, string $body): void
    {
        config()->set("messaging.email.definitions.marketing.webinar.campaigns.webinar_attended.steps.{$stepNumber}.variants.email", [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'to' => '{email}',
                'subject' => 'Step '.$stepNumber,
                'body' => $body,
            ],
        ]);
    }

    private function createDependencyAwareSingleStepCampaign(): Campaign
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
            'name' => 'Attended webinar follow-up',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => 'dependency_aware',
            'is_active' => true,
            'criteria' => [
                'timing' => [
                    'type' => 'delay',
                    'days' => 7,
                ],
            ],
            'meta' => [
                'type' => 'message',
            ],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'name' => 'SMS follow-up',
            'sort_order' => 10,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
            'meta' => [],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => 20,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [
                'requires_variant_states' => [
                    'sms' => ['sent', 'unavailable'],
                ],
            ],
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            'meta' => [],
        ]);

        return $campaign->refresh();
    }

    private function configureDependencyAwareCampaignMessagingDefinitions(): void
    {
        Config::set('messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Thanks for joining',
                'body' => 'Hi {first_name}, thanks for joining.',
            ],
        ]);

        Config::set('messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'message' => 'Hi {first_name}, thanks for joining.',
            ],
        ]);
    }

    private function configureDependencyAwareCampaignChannelAvailability(): void
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
