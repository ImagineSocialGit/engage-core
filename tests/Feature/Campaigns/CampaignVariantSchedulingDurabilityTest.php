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
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignVariantSchedulingDurabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_all_eligible_same_channel_variants_do_not_dedupe_each_other(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureEmailCampaignAvailability();

        $campaign = $this->campaignWithSameChannelVariants();
        $step = $campaign->steps()->firstOrFail();

        $primaryVariant = $step->variants()->where('key', 'email_primary')->firstOrFail();
        $alternateVariant = $step->variants()->where('key', 'email_alternate')->firstOrFail();

        $primaryPreset = $this->presetForVariant($primaryVariant, 'Primary subject');
        $alternatePreset = $this->presetForVariant($alternateVariant, 'Alternate subject');

        $primaryAssignment = $this->assignmentForVariant($primaryPreset, $campaign, $step, $primaryVariant);
        $alternateAssignment = $this->assignmentForVariant($alternatePreset, $campaign, $step, $alternateVariant);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $this->contactWithConsent(),
            campaignKey: $campaign->key,
        );

        $this->assertDatabaseCount('scheduled_messages', 2);

        $messages = ScheduledMessage::query()
            ->orderBy('id')
            ->get();

        $this->assertSame(
            ['Primary subject', 'Alternate subject'],
            $messages->pluck('payload.subject')->all(),
        );

        $this->assertSame(
            ['email_primary', 'email_alternate'],
            $messages->pluck('meta.campaign_step_variant_key')->all(),
        );

        $this->assertSame(
            [$primaryVariant->source_config_path, $alternateVariant->source_config_path],
            $messages->pluck('meta.campaign_step_variant_source_config_path')->all(),
        );

        $this->assertSame(
            [$primaryAssignment->getKey(), $alternateAssignment->getKey()],
            $messages->pluck('meta.message_template_preset.assignment_id')->all(),
        );

        $this->assertSame(
            [$primaryPreset->getKey(), $alternatePreset->getKey()],
            $messages->pluck('meta.message_template_preset.id')->all(),
        );
    }

    public function test_campaign_variant_scheduled_payload_stays_compact_while_meta_keeps_variant_identity(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-07-08 12:00:00');

        $this->configureEmailCampaignAvailability();

        $campaign = $this->campaignWithSameChannelVariants();
        $step = $campaign->steps()->firstOrFail();

        $primaryVariant = $step->variants()->where('key', 'email_primary')->firstOrFail();
        $alternateVariant = $step->variants()->where('key', 'email_alternate')->firstOrFail();

        $primaryPreset = $this->presetForVariant($primaryVariant, 'Primary subject');
        $alternatePreset = $this->presetForVariant($alternateVariant, 'Alternate subject');

        $assignment = $this->assignmentForVariant($primaryPreset, $campaign, $step, $primaryVariant);
        $this->assignmentForVariant($alternatePreset, $campaign, $step, $alternateVariant);

        app(EnrollContactInCampaignAction::class)->handle(
            contact: $this->contactWithConsent(),
            campaignKey: $campaign->key,
        );

        $message = ScheduledMessage::query()
            ->where('meta->campaign_step_variant_key', 'email_primary')
            ->first();

        $this->assertNotNull($message);
        $this->assertSame('Primary subject', $message->payload['subject']);
        $this->assertSame('Jeff', $message->payload['first_name']);
        $this->assertArrayNotHasKey('context', $message->payload);
        $this->assertArrayNotHasKey('created_at', $message->payload['tokens']['contact'] ?? []);
        $this->assertArrayNotHasKey('updated_at', $message->payload['tokens']['contact'] ?? []);
        $this->assertSame('email_primary', $message->meta['campaign_step_variant_key']);
        $this->assertSame($primaryVariant->source_config_path, $message->meta['campaign_step_variant_source_config_path']);
        $this->assertSame($assignment->getKey(), data_get($message->meta, 'message_template_preset.assignment_id'));
    }

    private function configureEmailCampaignAvailability(): void
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
    }

    private function campaignWithSameChannelVariants(): Campaign
    {
        $campaign = Campaign::query()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'status' => Campaign::STATUS_ACTIVE,
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
            'variant_strategy' => 'send_all_eligible',
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

        foreach ([
            ['key' => 'email_primary', 'name' => 'Primary email', 'sort_order' => 0],
            ['key' => 'email_alternate', 'name' => 'Alternate email', 'sort_order' => 1],
        ] as $variant) {
            CampaignStepVariant::query()->create([
                'campaign_step_id' => $step->id,
                'key' => $variant['key'],
                'name' => $variant['name'],
                'sort_order' => $variant['sort_order'],
                'dispatch_key' => 'campaign_step_due',
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'is_active' => true,
                'criteria' => [],
                'dependency_rules' => [],
                'source_config_path' => 'presets.modules.webinars.campaigns.definitions.webinar_attended_nurture.steps.1.variants.'.$variant['key'],
                'source_version' => 'test',
                'meta' => [],
            ]);
        }

        return $campaign->refresh();
    }

    private function presetForVariant(CampaignStepVariant $variant, string $subject): MessageTemplatePreset
    {
        return MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.'.$variant->key,
            'name' => $subject,
            'channel' => $variant->channel,
            'purpose' => $variant->purpose,
            'scope' => $variant->scope,
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => $subject,
                'body' => 'Body for '.$subject.'.',
            ],
            'source_config_path' => 'messaging.email.definitions.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.'.$variant->key,
        ]);
    }

    private function assignmentForVariant(
        MessageTemplatePreset $preset,
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
    ): MessageTemplatePresetAssignment {
        return MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: $campaign->key,
                stepNumber: (int) $step->step_number,
                variantKey: $variant->key,
                sourceConfigPath: $preset->source_config_path,
            )
            ->create([
                'message_type' => $preset->message_type,
            ]);
    }

    private function contactWithConsent(): Contact
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Jeff',
            'name' => 'Jeff Yarnall',
            'email' => 'jeff@example.com',
        ]);

        MessageConsent::query()->create([
            'contact_id' => $contact->id,
            'channel' => MessageChannel::Email->value,
            'purpose' => MessagePurpose::Marketing->value,
            'scope' => 'webinar',
            'consented_at' => now()->subMinute(),
            'source' => 'test',
        ]);

        return $contact;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}