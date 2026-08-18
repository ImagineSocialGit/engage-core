<?php

namespace Tests\Feature\Campaigns;

use App\Modules\Campaigns\Automation\EnrollCampaignAutomationActionHandler;
use App\Modules\Campaigns\Exceptions\CampaignUnavailableForEnrollmentException;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EnrollCampaignAutomationActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_message_chain_enrollment_identity_after_successful_enrollment(): void
    {
        Queue::fake();

        $campaign = $this->activeCampaignWithChain('automation_campaign');
        $context = $this->context($campaign->key);

        $result = app(EnrollCampaignAutomationActionHandler::class)->handle($context);

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $result->status);
        $this->assertSame('campaign_enrolled', $result->reason);
        $this->assertNotNull($result->output['campaign_enrollment']['message_chain_enrollment_id']);
        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $result->output['campaign_enrollment']['message_chain_status'],
        );
        $this->assertSame(
            MessageChainEnrollment::STATUS_ACTIVE,
            $result->output['campaign_enrollment']['status'],
        );
        $this->assertNotNull($result->output['campaign_enrollment']['message_chain_version_id']);
        $this->assertNotNull($result->output['campaign_enrollment']['current_message_chain_step_id']);
        $this->assertSame(
            $result->output['campaign_enrollment']['message_chain_enrollment_id'],
            $result->correlation['message_chain_enrollment_id'],
        );
    }

    public function test_terminal_chain_state_does_not_block_intentional_reenrollment_even_if_legacy_wrapper_status_is_stale(): void
    {
        Queue::fake();

        $campaign = $this->activeCampaignWithChain('automation_reenroll');
        $context = $this->context($campaign->key);

        $first = app(EnrollCampaignAutomationActionHandler::class)->handle($context);
        $firstChainId = $first->output['campaign_enrollment']['message_chain_enrollment_id'];

        MessageChainEnrollment::query()
            ->whereKey($firstChainId)
            ->update([
                'status' => MessageChainEnrollment::STATUS_COMPLETED,
                'current_message_chain_step_id' => null,
                'next_action_at' => null,
                'completed_at' => now(),
            ]);

        $second = app(EnrollCampaignAutomationActionHandler::class)->handle($context);

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $second->status);
        $this->assertSame('campaign_enrolled', $second->reason);
        $this->assertNotSame(
            $firstChainId,
            $second->output['campaign_enrollment']['message_chain_enrollment_id'],
        );
        $this->assertDatabaseCount('campaign_enrollments', 2);
    }

    public function test_it_skips_inactive_campaign_with_explicit_reason(): void
    {
        $campaign = Campaign::factory()->create([
            'key' => 'inactive_campaign',
            'status' => Campaign::STATUS_INACTIVE,
        ]);

        $result = app(EnrollCampaignAutomationActionHandler::class)->handle(
            $this->context($campaign->key),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame(
            CampaignUnavailableForEnrollmentException::REASON_INACTIVE,
            $result->reason,
        );
        $this->assertSame($campaign->key, $result->output['campaign_key']);
        $this->assertSame(Campaign::STATUS_INACTIVE, $result->output['campaign_status']);
        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    public function test_it_skips_missing_campaign_with_distinct_reason(): void
    {
        $result = app(EnrollCampaignAutomationActionHandler::class)->handle(
            $this->context('__missing_campaign__'),
        );

        $this->assertSame(AutomationActionResult::STATUS_SKIPPED, $result->status);
        $this->assertSame(
            CampaignUnavailableForEnrollmentException::REASON_MISSING,
            $result->reason,
        );
        $this->assertSame('__missing_campaign__', $result->output['campaign_key']);
        $this->assertArrayNotHasKey('campaign_status', $result->output);
        $this->assertDatabaseCount('campaign_enrollments', 0);
    }

    private function context(string $campaignKey): AutomationActionContext
    {
        $contact = Contact::factory()->create();

        return new AutomationActionContext(
            input: [
                'campaign_key' => $campaignKey,
            ],
            subject: $contact,
            models: [
                'current_contact' => $contact,
            ],
            source: $contact,
            runtimeContext: [
                'event_key' => 'test.event',
            ],
        );
    }

    private function activeCampaignWithChain(string $key): Campaign
    {
        $template = MessageTemplate::query()->create([
            'key' => "fixture.{$key}.email",
            'name' => "fixture.{$key}.email",
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $templateVersion = MessageTemplateVersion::query()->create([
            'message_template_id' => $template->getKey(),
            'version' => 1,
            'subject' => 'Fixture subject',
            'content' => ['body' => 'Fixture body'],
            'renderer_key' => 'fixture',
            'renderer_version' => '1',
            'content_hash' => hash('sha256', $key),
        ]);
        $template->forceFill(['current_version_id' => $templateVersion->getKey()])->save();

        $chain = MessageChain::query()->create([
            'key' => "campaign.{$key}",
            'name' => "{$key} chain",
            'status' => MessageChain::STATUS_ACTIVE,
            'source' => 'test',
            'is_customized' => false,
        ]);

        app(PublishMessageChainVersionAction::class)->handle(
            messageChain: $chain,
            steps: [[
                'key' => 'step_1',
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'offset_seconds' => 3600,
                'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
                'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
                'variants' => [[
                    'key' => 'email',
                    'message_template_version_id' => $templateVersion->getKey(),
                    'channel' => 'email',
                    'purpose' => 'marketing',
                    'scope' => 'campaign_test',
                    'message_type' => "{$key}_step_1",
                    'queue' => 'marketing',
                ]],
            ]],
        );

        return Campaign::query()->create([
            'key' => $key,
            'name' => "{$key} campaign",
            'message_chain_id' => $chain->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'campaign_test',
            'status' => Campaign::STATUS_ACTIVE,
            'meta' => [],
        ]);
    }
}