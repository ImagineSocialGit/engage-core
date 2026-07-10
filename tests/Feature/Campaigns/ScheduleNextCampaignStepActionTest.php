<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ScheduleNextCampaignStepAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Webinars\Models\WebinarRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ScheduleNextCampaignStepActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_schedules_the_next_campaign_step_variant(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();
        $contact = $this->contactWithMarketingEmailConsent();
        $registration = WebinarRegistration::factory()->create(['contact_id' => $contact->id]);

        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();
        $stepTwo = $campaign->steps()->where('step_number', 2)->firstOrFail();
        $variantTwo = $stepTwo->variants()->where('key', 'email')->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'source_type' => $registration->getMorphClass(),
            'source_id' => $registration->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => Carbon::now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertInstanceOf(ScheduledMessage::class, $scheduledMessage);
        $this->assertSame('webinar_attended_step_2', $scheduledMessage->message_type);
        $this->assertSame(Contact::class, $scheduledMessage->recipient_type);
        $this->assertSame($contact->id, $scheduledMessage->recipient_id);
        $this->assertSame('email', $scheduledMessage->channel);
        $this->assertSame('marketing', $scheduledMessage->purpose);
        $this->assertSame('webinar', $scheduledMessage->scope);
        $this->assertSame('marketing', $scheduledMessage->queue);
        $this->assertSame(['campaign_step_due'], $scheduledMessage->dispatch_keys);
        $this->assertSame('messaging.email.marketing.webinar.campaigns.webinar_attended.steps.2.variants.email', $scheduledMessage->definition_config_path);
        $this->assertSame($campaign->id, $scheduledMessage->meta['campaign_id']);
        $this->assertSame('webinar_attended', $scheduledMessage->meta['campaign_key']);
        $this->assertSame(2, $scheduledMessage->meta['campaign_step']);
        $this->assertSame($stepTwo->id, $scheduledMessage->meta['campaign_step_id']);
        $this->assertSame($variantTwo->id, $scheduledMessage->meta['campaign_step_variant_id']);
        $this->assertSame('email', $scheduledMessage->meta['campaign_step_variant_key']);
        $this->assertSame($enrollment->id, $scheduledMessage->meta['campaign_enrollment_id']);
        $this->assertTrue($scheduledMessage->send_at->equalTo(Carbon::now()->addMinutes(720)));

        $enrollment->refresh();
        $this->assertSame(CampaignEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(2, $enrollment->current_step);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertSame($scheduledMessage->id, $enrollment->last_scheduled_message_id);
    }

    public function test_it_completes_the_enrollment_when_no_next_step_exists(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithStep(1);
        $contact = $this->contactWithMarketingEmailConsent();
        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => Carbon::now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);
        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->refresh()->status);
    }

    public function test_it_does_not_schedule_when_enrollment_is_not_active(): void
    {
        Queue::fake();

        $campaign = $this->createCampaignWithSteps();
        $contact = $this->contactWithMarketingEmailConsent();
        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_PAUSED,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => now(),
            'paused_at' => now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);
        $this->assertSame(CampaignEnrollment::STATUS_PAUSED, $enrollment->refresh()->status);
    }

    public function test_it_completes_when_next_step_has_no_active_variants(): void
    {
        Queue::fake();

        $campaign = $this->createCampaignWithStep(1);
        $contact = $this->contactWithMarketingEmailConsent();
        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $stepTwo = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => 2,
            'name' => 'Step 2',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => ['timing' => ['type' => 'delay', 'minutes' => 720]],
            'meta' => ['type' => 'message'],
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);
        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->refresh()->status);
        $this->assertSame($stepTwo->id, $enrollment->current_campaign_step_id);
        $this->assertSame('campaign_step_has_no_active_variants', data_get($enrollment->meta, 'last_message_schedule_attempt.reason'));
    }

    public function test_it_skips_sms_campaign_step_variant_when_sms_is_not_available_for_campaigns(): void
    {
        Queue::fake();

        config()->set('messaging.channel_availability.sms.runtime_supported', true);
        config()->set('messaging.channel_availability.sms.provider_enabled', true);
        config()->set('messaging.channel_availability.sms.surfaces.campaigns', false);
        config()->set('messaging.channel_availability.sms.purpose_scopes', ['marketing:webinar' => true]);
        config()->set('messaging.sms.marketing.webinar.campaigns.webinar_sms.steps.1.variants.sms', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'payload' => ['message' => 'SMS step'],
        ]);

        $campaign = Campaign::create([
            'key' => 'webinar_sms',
            'name' => 'Webinar SMS',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'status' => Campaign::STATUS_ACTIVE,
            'is_active' => true,
            'meta' => [],
        ]);

        $step = CampaignStep::create([
            'campaign_id' => $campaign->id,
            'step_number' => 1,
            'name' => 'SMS Step 1',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => ['timing' => ['type' => 'delay', 'minutes' => 15]],
            'meta' => ['type' => 'message'],
        ]);

        CampaignStepVariant::create([
            'campaign_step_id' => $step->id,
            'key' => 'sms',
            'name' => 'SMS follow-up',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'is_active' => true,
            'criteria' => [],
            'dependency_rules' => [],
            'meta' => [],
        ]);

        $contact = Contact::factory()->create(['phone' => '+15555550123']);
        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_sms',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 0,
            'started_at' => now(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertNull($scheduledMessage);
        $this->assertDatabaseCount('scheduled_messages', 0);
        $this->assertSame(CampaignEnrollment::STATUS_COMPLETED, $enrollment->refresh()->status);
        $this->assertSame('campaign_channel_unavailable', data_get($enrollment->meta, 'last_message_schedule_attempt.reason'));
    }

    public function test_it_preserves_flow_route_provenance_from_enrollment_on_next_scheduled_message(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-06-12 12:00:00');

        $campaign = $this->createCampaignWithSteps();
        $contact = $this->contactWithMarketingEmailConsent();

        $flowRoute = $this->createFlowRouteProvenance($contact);

        $stepOne = $campaign->steps()->where('step_number', 1)->firstOrFail();

        $enrollment = CampaignEnrollment::create([
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'campaign_key' => 'webinar_attended',
            'status' => CampaignEnrollment::STATUS_ACTIVE,
            'current_step' => 1,
            'current_campaign_step_id' => $stepOne->id,
            'started_at' => Carbon::now(),
            'flow_route_progress_id' => $flowRoute['progress']->getKey(),
            'flow_route_plan_id' => $flowRoute['plan']->getKey(),
            'flow_route_plan_item_id' => $flowRoute['plan_item']->getKey(),
            'flow_route_progress_item_id' => $flowRoute['progress_item']->getKey(),
            'flow_route_id' => $flowRoute['flow_route']->getKey(),
            'flow_route_point_id' => $flowRoute['flow_route_point']->getKey(),
            'flow_route_capability_id' => $flowRoute['capability']->getKey(),
        ]);

        $scheduledMessage = app(ScheduleNextCampaignStepAction::class)->handle($enrollment);

        $this->assertInstanceOf(ScheduledMessage::class, $scheduledMessage);

        $this->assertSame($flowRoute['progress']->getKey(), $scheduledMessage->flow_route_progress_id);
        $this->assertSame($flowRoute['plan']->getKey(), $scheduledMessage->flow_route_plan_id);
        $this->assertSame($flowRoute['plan_item']->getKey(), $scheduledMessage->flow_route_plan_item_id);
        $this->assertSame($flowRoute['progress_item']->getKey(), $scheduledMessage->flow_route_progress_item_id);
        $this->assertSame($flowRoute['flow_route']->getKey(), $scheduledMessage->flow_route_id);
        $this->assertSame($flowRoute['flow_route_point']->getKey(), $scheduledMessage->flow_route_point_id);
        $this->assertSame($flowRoute['capability']->getKey(), $scheduledMessage->flow_route_capability_id);

        $this->assertSame($flowRoute['progress']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_progress_id'));
        $this->assertSame($flowRoute['plan']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_plan_id'));
        $this->assertSame($flowRoute['plan_item']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_plan_item_id'));
        $this->assertSame($flowRoute['progress_item']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_progress_item_id'));
        $this->assertSame($flowRoute['flow_route']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_id'));
        $this->assertSame($flowRoute['flow_route_point']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_point_id'));
        $this->assertSame($flowRoute['capability']->getKey(), data_get($scheduledMessage->meta, 'flow_route.flow_route_capability_id'));
    }

    private function createCampaignWithSteps(): Campaign
    {
        $campaign = $this->createCampaignWithStep(1);
        $this->defineCampaignStepVariantMessageTemplate('webinar_attended', 2, 'Second message');
        $this->createStepWithEmailVariant($campaign, 2);

        return $campaign->refresh();
    }

    private function createCampaignWithStep(int $stepNumber): Campaign
    {
        $this->defineCampaignStepVariantMessageTemplate('webinar_attended', $stepNumber, 'Message '.$stepNumber);

        $campaign = Campaign::query()->firstOrCreate(
            ['key' => 'webinar_attended'],
            [
                'name' => 'Webinar Attended',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar',
                'status' => Campaign::STATUS_ACTIVE,
                'is_active' => true,
                'meta' => [],
            ],
        );

        $this->createStepWithEmailVariant($campaign, $stepNumber);

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
            'source_config_path' => "messaging.email.marketing.webinar.campaigns.{$campaign->key}.steps.{$stepNumber}.variants.email",
            'meta' => [],
        ]);

        return $step;
    }

    private function defineCampaignStepVariantMessageTemplate(string $campaignKey, int $stepNumber, string $body): void
    {
        config()->set("messaging.email.marketing.webinar.campaigns.{$campaignKey}.steps.{$stepNumber}.variants.email", [
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

    private function contactWithMarketingEmailConsent(): Contact
    {
        $contact = Contact::factory()->create(['email' => 'person@example.com']);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        return $contact;
    }

    private function createFlowRouteProvenance(Contact $contact): array
    {
        $status = \App\Modules\Core\Models\ContactStatus::query()->create([
            'key' => 'test_flow_route_status_'.uniqid(),
            'name' => 'Test Flow Route Status',
            'is_active' => true,
        ]);

        $flowRoute = \App\Modules\FlowRoutes\Models\FlowRoute::query()->create([
            'key' => 'test_flow_route_'.uniqid(),
            'contact_status_id' => $status->getKey(),
            'name' => 'Test Flow Route',
            'description' => null,
            'version' => 1,
            'trigger_type' => \App\Modules\FlowRoutes\Models\FlowRoute::TRIGGER_CONTACT_STATUS,
            'trigger_key' => $status->key,
            'is_active' => true,
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $capability = \App\Modules\FlowRoutes\Models\FlowRouteCapability::query()->create([
            'key' => 'test_capability_'.uniqid(),
            'module_key' => 'flow_routes',
            'capability_type' => \App\Modules\FlowRoutes\Models\FlowRouteCapability::TYPE_ACTION,
            'point_type' => \App\Modules\FlowRoutes\Enums\FlowRoutePointType::Noop->value,
            'handler_key' => 'noop',
            'event_key' => null,
            'action_key' => 'noop',
            'name' => 'Test Capability',
            'description' => null,
            'category' => 'test',
            'surface' => 'test',
            'supported_subjects' => [],
            'required_modules' => [],
            'input_schema' => [],
            'output_schema' => [],
            'available_fields' => [],
            'defaults' => [],
            'is_active' => true,
            'source' => 'test',
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);


        $flowRoutePoint = \App\Modules\FlowRoutes\Models\FlowRoutePoint::query()->create([
            'flow_route_id' => $flowRoute->getKey(),
            'type' => \App\Modules\FlowRoutes\Enums\FlowRoutePointType::Noop->value,
            'name' => 'Test Point',
            'description' => null,
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_point',
            'sort_order' => 10,
            'is_start' => true,
            'is_active' => true,
            'next_flow_route_point_id' => null,
            'definition' => [],
            'settings' => [],
            'cancel_conditions' => [],
            'source_version' => 'test',
            'is_customized' => false,
            'customized_at' => null,
            'meta' => [],
        ]);

        $progress = \App\Modules\FlowRoutes\Models\ContactFlowRouteProgress::query()->create([
            'contact_id' => $contact->getKey(),
            'contact_status_id' => $status->getKey(),
            'contact_workflow_profile_id' => null,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'current_flow_route_point_id' => $flowRoutePoint->getKey(),
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRouteProgress::STATUS_ACTIVE,
            'started_at' => now(),
            'meta' => [],
        ]);

        $plan = \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_id' => $contact->getKey(),
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::STATUS_ACTIVE,
            'source' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlan::SOURCE_TEMPLATE,
            'flow_route_version' => 1,
            'snapshot_at' => now(),
            'started_at' => now(),
            'route_snapshot' => [],
            'meta' => [],
        ]);

        $planItem = \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_flow_route_plan_id' => $plan->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_plan_item',
            'point_type' => $flowRoutePoint->type,
            'sort_order' => 10,
            'sequence' => 1,
            'attempt' => 1,
            'source' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::SOURCE_TEMPLATE,
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRoutePlanItem::STATUS_COMPLETED,
            'definition_snapshot' => [],
            'settings_snapshot' => [],
            'cancel_conditions_snapshot' => [],
            'started_at' => now(),
            'completed_at' => now(),
            'meta' => [],
        ]);

        $progressItem = \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::query()->create([
            'contact_flow_route_progress_id' => $progress->getKey(),
            'contact_flow_route_plan_id' => $plan->getKey(),
            'contact_flow_route_plan_item_id' => $planItem->getKey(),
            'flow_route_id' => $flowRoute->getKey(),
            'flow_route_point_id' => $flowRoutePoint->getKey(),
            'flow_route_capability_id' => $capability->getKey(),
            'key' => 'test_flow_route_progress_item',
            'point_type' => $flowRoutePoint->type,
            'sequence' => 1,
            'attempt' => 1,
            'status' => \App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem::STATUS_COMPLETED,
            'started_at' => now(),
            'completed_at' => now(),
            'meta' => [],
        ]);

        return [
            'progress' => $progress,
            'plan' => $plan,
            'plan_item' => $planItem,
            'progress_item' => $progressItem,
            'flow_route' => $flowRoute,
            'flow_route_point' => $flowRoutePoint,
            'capability' => $capability,
        ];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
