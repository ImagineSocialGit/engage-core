<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignSetupActionabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $this->withoutMiddleware(ForceStagingAccess::class);
    }

    public function test_setup_exposes_actionable_start_schedule_messages_and_review_surfaces(): void
    {
        $user = User::factory()->create();
        [$campaign, $preset] = $this->campaignWithSelectedMessage();

        $response = $this->actingAs($user)
            ->get(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'messages',
                'message' => 'preset:'.$preset->getKey(),
            ]))
            ->assertOk()
            ->assertViewIs('crm.campaigns.edit')
            ->assertViewHas('initialPanel', 'messages')
            ->assertSee('data-campaign-setup', false)
            ->assertSee('data-campaign-panel-open="start"', false)
            ->assertSee('data-campaign-panel-open="schedule"', false)
            ->assertSee('data-campaign-panel-open="messages"', false)
            ->assertSee('data-campaign-start-editor', false)
            ->assertSee('data-campaign-eligibility-form', false)
            ->assertSee('data-campaign-panel-modal="schedule"', false)
            ->assertSee('data-campaign-panel-modal="messages"', false)
            ->assertSee('data-message-editor-carousel', false)
            ->assertSee('data-message-editor-form', false)
            ->assertSee('data-campaign-schedule-step="1"', false)
            ->assertSee('data-campaign-lifecycle-action="activate"', false)
            ->assertSee(route('crm.campaigns.eligibility.update', $campaign), false);

        $workspace = $response->viewData('workspace');
        $messageReview = $response->viewData('messageReview');

        $this->assertCount(1, $workspace['schedule_steps']);
        $this->assertSame(1, $workspace['schedule_steps'][0]['step_number']);
        $this->assertEquals(['email'], $workspace['schedule_steps'][0]['channels']);
        $this->assertSame(1, $workspace['schedule_steps'][0]['message_count']);
        $this->assertSame(1, $messageReview['message_count']);
        $this->assertSame('preset:'.$preset->getKey(), $messageReview['initial_message_id']);

        $returnTo = route('crm.campaigns.edit', [
            'campaign' => $campaign,
            'panel' => 'messages',
        ], false);

        $response->assertSee('name="return_to" value="'.$returnTo.'"', false);
    }

    public function test_unknown_panel_does_not_open_an_authoring_context(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create();

        $this->actingAs($user)
            ->get(route('crm.campaigns.edit', [
                'campaign' => $campaign,
                'panel' => 'unknown',
            ]))
            ->assertOk()
            ->assertViewHas('initialPanel', null);
    }

    public function test_lifecycle_actions_can_return_to_the_campaign_review_context(): void
    {
        $user = User::factory()->create();
        $campaign = Campaign::factory()->create([
            'status' => Campaign::STATUS_INACTIVE,
        ]);
        $this->attachPublishedChain($campaign, MessageChain::STATUS_INACTIVE);
        $returnTo = route('crm.campaigns.edit', [
            'campaign' => $campaign,
            'panel' => 'review',
        ], false);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.activate', $campaign), [
                'return_to' => $returnTo,
            ])
            ->assertRedirect('http://crm.'.config('app.root_domain').$returnTo);

        $this->assertSame(Campaign::STATUS_ACTIVE, $campaign->refresh()->status);

        $this->actingAs($user)
            ->patch(route('crm.campaigns.deactivate', $campaign), [
                'return_to' => $returnTo,
            ])
            ->assertRedirect('http://crm.'.config('app.root_domain').$returnTo);

        $this->assertSame(Campaign::STATUS_INACTIVE, $campaign->refresh()->status);
    }

    /** @return array{0: Campaign, 1: MessageTemplatePreset} */
    private function campaignWithSelectedMessage(): array
    {
        $campaign = Campaign::factory()->create([
            'key' => 'campaign_setup_actionability',
            'name' => 'Campaign Setup Actionability',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'status' => Campaign::STATUS_INACTIVE,
        ]);
        $step = CampaignStep::factory()
            ->forCampaign($campaign)
            ->create([
                'step_number' => 1,
                'name' => 'Initial follow-up',
                'criteria' => [
                    'timing' => [
                        'type' => 'delay',
                        'days' => 2,
                    ],
                ],
            ]);
        $variant = CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->getKey(),
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => 0,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'source_config_path' => 'presets.fixture.campaigns.steps.1.variants.email',
        ]);
        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.fixture_nurture.campaign_setup_actionability.step_1.email',
            'name' => 'Campaign Setup Actionability Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'fixture_nurture',
            'message_type' => 'campaign_setup_actionability_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Fixture subject',
                'body' => 'Fixture body.',
            ],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:'.$campaign->key,
                'group_label' => $campaign->name,
                'item_key' => $preset->key,
                'item_label' => 'Step 1 Email',
                'item_order' => 1,
                'usage_type' => 'campaign_step',
                'meta' => [
                    'campaign_key' => $campaign->key,
                    'campaign_step' => 1,
                    'campaign_step_variant_key' => $variant->key,
                ],
            ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                $campaign->key,
                $step->step_number,
                $variant->key,
                $variant->source_config_path,
            )
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'fixture_nurture',
                'message_type' => $preset->message_type,
            ]);

        return [$campaign, $preset];
    }

    private function attachPublishedChain(
        Campaign $campaign,
        string $status,
    ): void {
        $chain = MessageChain::query()->create([
            'key' => 'campaign.setup_actionability.'.$campaign->getKey(),
            'name' => 'Campaign setup actionability chain',
            'status' => $status,
            'source' => 'test',
            'is_customized' => false,
        ]);
        $version = MessageChainVersion::query()->create([
            'message_chain_id' => $chain->getKey(),
            'version' => 1,
            'exit_conditions' => [],
            'content_hash' => hash('sha256', 'campaign-setup-actionability-'.$campaign->getKey()),
            'published_at' => now(),
        ]);

        $chain->forceFill(['current_version_id' => $version->getKey()])->save();
        $campaign->forceFill(['message_chain_id' => $chain->getKey()])->save();
    }
}