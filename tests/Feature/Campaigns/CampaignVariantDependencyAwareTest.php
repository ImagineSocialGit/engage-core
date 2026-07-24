<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\EnrollContactInCampaignAction;
use App\Modules\Campaigns\Actions\ScheduleCampaignStepMessagesAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignVariantDependencyAwareTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependency_aware_can_use_a_sibling_variant_scheduled_earlier_in_the_same_pass(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 0,
            smsSortOrder: 1,
            smsDependencyRules: [
                'requires_variant_states' => [
                    'email' => ['scheduled'],
                ],
            ],
        );

        $contact = $this->contactWithConsent(email: true, sms: true);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 2);

        $this->assertSame(
            ['email', 'sms'],
            ScheduledMessage::query()
                ->orderBy('id')
                ->get()
                ->map(fn (ScheduledMessage $message): ?string => data_get($message->meta, 'campaign_step_variant_key'))
                ->all(),
        );
    }

    public function test_dependency_aware_can_use_an_existing_sibling_scheduled_message_for_the_same_enrollment_and_step(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 1,
            smsSortOrder: 0,
            smsDependencyRules: [
                'requires_variant_states' => [
                    'email' => ['scheduled'],
                ],
            ],
        );
        $step = $campaign->steps()->firstOrFail();
        $contact = $this->contactWithConsent(email: false, sms: true);
        $enrollment = $this->createEnrollment($campaign, $step, $contact);

        $existingEmailMessage = $this->existingVariantMessage(
            contact: $contact,
            enrollment: $enrollment,
            step: $step,
            variantKey: 'email',
            status: ScheduledMessage::STATUS_PENDING,
        );

        $scheduledMessage = app(ScheduleCampaignStepMessagesAction::class)->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
        );

        $this->assertInstanceOf(ScheduledMessage::class, $scheduledMessage);
        $this->assertSame('sms', $scheduledMessage->channel);
        $this->assertSame('sms', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
        $this->assertDatabaseCount('scheduled_messages', 2);
        $this->assertDatabaseHas('scheduled_messages', [
            'id' => $existingEmailMessage->id,
            'status' => ScheduledMessage::STATUS_PENDING,
        ]);
    }

    public function test_dependency_aware_does_not_match_required_variants_from_a_different_enrollment(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 1,
            smsSortOrder: 0,
            smsDependencyRules: [
                'requires_variant_states' => [
                    'email' => ['scheduled'],
                ],
            ],
        );
        $step = $campaign->steps()->firstOrFail();
        $contact = $this->contactWithConsent(email: false, sms: true);
        $enrollment = $this->createEnrollment($campaign, $step, $contact);
        $otherEnrollment = $this->createEnrollment($campaign, $step, $contact);

        $this->existingVariantMessage(
            contact: $contact,
            enrollment: $otherEnrollment,
            step: $step,
            variantKey: 'email',
            status: ScheduledMessage::STATUS_PENDING,
        );

        $scheduledMessage = app(ScheduleCampaignStepMessagesAction::class)->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
        );

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 1);

        $attempts = data_get($enrollment->fresh()->meta, 'last_campaign_step_variant_attempts', []);
        $smsAttempt = collect($attempts)->firstWhere('variant_key', 'sms');

        $this->assertSame('not_scheduled', $smsAttempt['result'] ?? null);
        $this->assertSame('campaign_variant_dependency_unsatisfied', $smsAttempt['reason'] ?? null);
        $this->assertEquals([
            [
                'variant_key' => 'email',
                'states' => ['scheduled'],
            ],
        ], $smsAttempt['dependency_unsatisfied'] ?? null);
    }

    public function test_dependency_aware_does_not_match_required_variants_from_a_different_campaign_step(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 1,
            smsSortOrder: 0,
            smsDependencyRules: [
                'requires_variant_states' => [
                    'email' => ['scheduled'],
                ],
            ],
        );
        $step = $campaign->steps()->firstOrFail();
        $otherStep = $this->createStep($campaign, stepNumber: 2, variantStrategy: 'first_available');
        $contact = $this->contactWithConsent(email: false, sms: true);
        $enrollment = $this->createEnrollment($campaign, $step, $contact);

        $this->existingVariantMessage(
            contact: $contact,
            enrollment: $enrollment,
            step: $otherStep,
            variantKey: 'email',
            status: ScheduledMessage::STATUS_PENDING,
        );

        $scheduledMessage = app(ScheduleCampaignStepMessagesAction::class)->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
        );

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 1);

        $smsAttempt = collect(data_get($enrollment->fresh()->meta, 'last_campaign_step_variant_attempts', []))
            ->firstWhere('variant_key', 'sms');

        $this->assertSame('campaign_variant_dependency_unsatisfied', $smsAttempt['reason'] ?? null);
    }

    public function test_dependency_aware_can_treat_required_sibling_channel_unavailable_as_an_explicit_state(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();
        Config::set('messaging.channel_availability.sms.surfaces.campaigns', false);

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 1,
            smsSortOrder: 0,
            smsDependencyRules: [],
            emailDependencyRules: [
                'requires_variant_states' => [
                    'sms' => ['sent', 'unavailable'],
                ],
            ],
        );

        $contact = $this->contactWithConsent(email: true, sms: false);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $contact,
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 1);

        $message = ScheduledMessage::query()->firstOrFail();

        $this->assertSame('email', $message->channel);
        $this->assertSame('email', data_get($message->meta, 'campaign_step_variant_key'));
    }

    public function test_dependency_aware_supports_explicit_terminal_dependency_state(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureCampaignMessagingDefinitions();
        $this->configureChannelAvailability();

        $campaign = $this->createCampaignWithDependencyAwareVariants(
            emailSortOrder: 1,
            smsSortOrder: 0,
            smsDependencyRules: [
                'requires_variant_states' => [
                    'email' => ['terminal'],
                ],
            ],
        );
        $step = $campaign->steps()->firstOrFail();
        $contact = $this->contactWithConsent(email: false, sms: true);
        $enrollment = $this->createEnrollment($campaign, $step, $contact);

        $this->existingVariantMessage(
            contact: $contact,
            enrollment: $enrollment,
            step: $step,
            variantKey: 'email',
            status: ScheduledMessage::STATUS_SKIPPED,
        );

        $scheduledMessage = app(ScheduleCampaignStepMessagesAction::class)->handle(
            enrollment: $enrollment,
            campaign: $campaign,
            step: $step,
            contact: $contact,
        );

        $this->assertInstanceOf(ScheduledMessage::class, $scheduledMessage);
        $this->assertSame('sms', data_get($scheduledMessage->meta, 'campaign_step_variant_key'));
    }

    /**
     * @param array<string, mixed> $smsDependencyRules
     */
    private function createCampaignWithDependencyAwareVariants(
        int $emailSortOrder,
        int $smsSortOrder,
        array $smsDependencyRules,
        array $emailDependencyRules = [],
    ): Campaign {
        $campaign = Campaign::query()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);

        $step = $this->createStep($campaign, stepNumber: 1, variantStrategy: 'dependency_aware');

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => $emailSortOrder,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => $emailDependencyRules,
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email',
            'meta' => [],
        ]);

        CampaignStepVariant::query()->create([
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'name' => 'SMS follow-up',
            'sort_order' => $smsSortOrder,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => $smsDependencyRules,
            'source_config_path' => 'messaging.sms.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
            'meta' => [],
        ]);

        return $campaign->refresh();
    }

    private function createStep(Campaign $campaign, int $stepNumber, string $variantStrategy): CampaignStep
    {
        return CampaignStep::query()->create([
            'campaign_id' => $campaign->id,
            'step_number' => $stepNumber,
            'name' => 'Step '.$stepNumber,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'variant_strategy' => $variantStrategy,
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
    }

    private function createEnrollment(Campaign $campaign, CampaignStep $step, Contact $contact): CampaignEnrollment
    {
        return CampaignEnrollment::query()->create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => $step->step_number,
            'current_campaign_step_id' => $step->id,
            'started_at' => now(),
            'meta' => [],
        ]);
    }

    private function existingVariantMessage(
        Contact $contact,
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        string $variantKey,
        string $status,
    ): ScheduledMessage {
        return ScheduledMessage::factory()->create([
            'recipient_type' => Contact::class,
            'recipient_id' => $contact->id,
            'channel' => $variantKey === 'sms' ? 'sms' : 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_'.$step->step_number,
            'status' => $status,
            'sent_at' => $status === ScheduledMessage::STATUS_SENT ? now() : null,
            'skipped_at' => $status === ScheduledMessage::STATUS_SKIPPED ? now() : null,
            'failed_at' => $status === ScheduledMessage::STATUS_FAILED ? now() : null,
            'meta' => [
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $enrollment->campaign_id,
                'campaign_key' => $enrollment->campaign_key,
                'campaign_step_id' => $step->id,
                'campaign_step' => $step->step_number,
                'campaign_step_variant_key' => $variantKey,
                'campaign_step_waits_for_all_scheduled_variants' => true,
            ],
        ]);
    }

    private function configureCampaignMessagingDefinitions(): void
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
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        if ($sms) {
            MessageConsent::query()->create([
                'contact_id' => $contact->id,
                'channel' => 'sms',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'consented_at' => now()->subMinute(),
                'source' => 'test',
            ]);
        }

        return $contact;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}