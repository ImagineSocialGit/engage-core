<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Actions\ActivateCampaignAction;
use App\Modules\Campaigns\Actions\DeactivateCampaignAction;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Core\Models\Contact;
use App\Modules\FlowRoutes\Enums\FlowRoutePointType;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgress;
use App\Modules\FlowRoutes\Models\ContactFlowRouteProgressItem;
use App\Modules\FlowRoutes\Models\FlowRoute;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\MessageConsent;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Webinars\Actions\EmitWebinarAutomationEventAction;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Support\AutomationEvents\Models\AutomationEventOutboxEvent;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use Database\Factories\FlowRouteFactory;
use Database\Factories\FlowRoutePointFactory;
use Database\Factories\FlowRouteTriggerBindingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebinarCampaignLifecycleEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

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
    }

    public function test_inactive_campaign_blocks_webinar_triggered_enrollment_without_invalidating_the_route(): void
    {
        [$campaign, $route] = $this->automationFixture(
            campaignStatus: Campaign::STATUS_INACTIVE,
        );
        $registration = $this->registrationWithMarketingConsent();

        $this->emitAttendedEvent($registration);

        $this->assertDatabaseCount('campaign_enrollments', 0);
        $this->assertDatabaseHas('flow_routes', [
            'id' => $route->getKey(),
            'is_current_version' => true,
            'is_active' => true,
        ]);

        $progress = ContactFlowRouteProgress::query()
            ->where('flow_route_id', $route->getKey())
            ->where('contact_id', $registration->contact_id)
            ->firstOrFail();

        $this->assertSame(
            ContactFlowRouteProgress::STATUS_COMPLETED,
            $progress->status,
        );

        $progressItem = ContactFlowRouteProgressItem::query()
            ->where('contact_flow_route_progress_id', $progress->getKey())
            ->where('point_type', FlowRoutePointType::EnrollCampaign->value)
            ->firstOrFail();

        $this->assertSame(
            ContactFlowRouteProgressItem::STATUS_SKIPPED,
            $progressItem->status,
        );
        $this->assertSame('campaign_inactive', $progressItem->result_reason);
        $this->assertSame(
            $campaign->key,
            data_get($progressItem->result_payload, 'meta.campaign_key'),
        );
    }

    public function test_deactivation_stops_open_work_and_reactivation_allows_only_new_webinar_enrollments(): void
    {
        [$campaign, $route] = $this->automationFixture(
            campaignStatus: Campaign::STATUS_ACTIVE,
        );
        $firstRegistration = $this->registrationWithMarketingConsent();

        $this->emitAttendedEvent($firstRegistration);

        $firstEnrollment = CampaignEnrollment::query()->firstOrFail();
        $firstMessage = ScheduledMessage::query()->firstOrFail();

        $this->assertSame(
            CampaignEnrollment::STATUS_ACTIVE,
            $firstEnrollment->status,
        );
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $firstMessage->status);

        $deactivation = app(DeactivateCampaignAction::class)->handle(
            campaign: $campaign,
            source: 'test_webinar_lifecycle',
        );

        $this->assertSame(1, $deactivation['enrollments_cancelled']);
        $this->assertSame(1, $deactivation['scheduled_messages_skipped']);
        $this->assertSame(
            CampaignEnrollment::STATUS_CANCELLED,
            $firstEnrollment->refresh()->status,
        );
        $this->assertSame(
            CampaignEnrollment::EXIT_REASON_CAMPAIGN_DEACTIVATED,
            $firstEnrollment->exit_reason,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $firstMessage->refresh()->status,
        );
        $this->assertDatabaseHas('flow_routes', [
            'id' => $route->getKey(),
            'is_current_version' => true,
            'is_active' => true,
        ]);

        app(ActivateCampaignAction::class)->handle(
            campaign: $campaign,
            source: 'test_webinar_lifecycle',
        );

        $this->assertSame(
            CampaignEnrollment::STATUS_CANCELLED,
            $firstEnrollment->refresh()->status,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $firstMessage->refresh()->status,
        );

        $secondRegistration = $this->registrationWithMarketingConsent();
        $this->emitAttendedEvent($secondRegistration);

        $this->assertDatabaseCount('campaign_enrollments', 2);
        $this->assertDatabaseCount('scheduled_messages', 2);

        $secondEnrollment = CampaignEnrollment::query()
            ->where('contact_id', $secondRegistration->contact_id)
            ->firstOrFail();
        $secondMessage = ScheduledMessage::query()
            ->where('meta->campaign_enrollment_id', $secondEnrollment->getKey())
            ->firstOrFail();

        $this->assertSame(
            CampaignEnrollment::STATUS_ACTIVE,
            $secondEnrollment->status,
        );
        $this->assertSame(ScheduledMessage::STATUS_PENDING, $secondMessage->status);
        $this->assertSame(
            CampaignEnrollment::STATUS_CANCELLED,
            $firstEnrollment->refresh()->status,
        );
        $this->assertSame(
            ScheduledMessage::STATUS_SKIPPED,
            $firstMessage->refresh()->status,
        );
    }

    /**
     * @return array{0: Campaign, 1: FlowRoute}
     */
    private function automationFixture(string $campaignStatus): array
    {
        $campaign = Campaign::factory()->create([
            'key' => 'webinar_attended_nurture',
            'status' => $campaignStatus,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ]);

        $step = CampaignStep::factory()
            ->forCampaign($campaign)
            ->create([
                'step_number' => 1,
                'variant_strategy' => 'first_available',
                'criteria' => [
                    'timing' => [
                        'type' => 'delay',
                        'minutes' => 60,
                    ],
                ],
            ]);

        $variant = CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->getKey(),
            'key' => 'email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source_config_path' => 'stale.campaign.authoring.path',
        ]);

        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.webinar_nurture.campaigns.'
                .'webinar_attended_nurture.steps.1.variants.email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Thanks for attending',
                'body' => 'Hi {first_name}, thanks for attending.',
            ],
            'source_config_path' => 'diagnostics.current.template.path',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: $campaign->key,
                stepNumber: 1,
                variantKey: $variant->key,
                sourceConfigPath: 'diagnostics.previous.template.path',
            )
            ->create([
                'message_type' => $preset->message_type,
            ]);

        $route = FlowRouteFactory::new()
            ->forAutomationEvent('webinar.attended')
            ->create([
                'key' => 'webinar_attended_campaign_enrollment',
                'is_current_version' => true,
                'is_active' => true,
            ]);

        FlowRoutePointFactory::new()
            ->for($route)
            ->type(FlowRoutePointType::EnrollCampaign)
            ->start()
            ->create([
                'key' => 'enroll_webinar_attended_nurture',
                'definition' => [
                    'campaign_key' => $campaign->key,
                ],
            ]);

        FlowRouteTriggerBindingFactory::new()
            ->for($route)
            ->forAutomationEvent('webinar.attended')
            ->create();

        return [$campaign, $route];
    }

    private function registrationWithMarketingConsent(): WebinarRegistration
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Test',
            'email' => fake()->unique()->safeEmail(),
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->getKey(),
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->create([
            'webinar_series_id' => $series->getKey(),
        ]);

        return WebinarRegistration::factory()->create([
            'contact_id' => $contact->getKey(),
            'webinar_id' => $webinar->getKey(),
            'webinar_slug' => $webinar->slug,
            'status' => 'attended',
            'attended_at' => now(),
        ]);
    }

    private function emitAttendedEvent(WebinarRegistration $registration): void
    {
        $beforeId = (int) (AutomationEventOutboxEvent::query()->max('id') ?? 0);

        app(EmitWebinarAutomationEventAction::class)->forRegistration(
            eventKey: 'webinar.attended',
            registration: $registration,
            occurredAt: now(),
        );

        $outboxEvent = AutomationEventOutboxEvent::query()
            ->where('id', '>', $beforeId)
            ->where('event_key', 'webinar.attended')
            ->latest('id')
            ->firstOrFail();

        $outboxEvent->refresh();

        if ($outboxEvent->status !== AutomationEventOutboxEvent::STATUS_PUBLISHED) {
            $this->assertTrue(
                app(AutomationEventOutbox::class)->publish($outboxEvent->getKey()),
                $outboxEvent->fresh()->last_error
                    ?? 'Webinar automation event was not published.',
            );
        }

        $this->assertSame(
            AutomationEventOutboxEvent::STATUS_PUBLISHED,
            $outboxEvent->fresh()->status,
        );
    }
}