<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_campaign_steps_and_template_selection_without_copy_editing(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $preset] = $this->campaignStepWithTemplate();

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStep($campaign->key, $step->step_number)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'message_type' => $preset->message_type,
                'meta' => [
                    'catalog' => [
                        'group_label' => 'Webinar Attended Nurture',
                        'item_label' => 'Step 1 Email',
                    ],
                ],
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/campaigns/message-templates?campaign='.$campaign->getKey())
            ->assertOk()
            ->assertSee('Campaign Message Templates')
            ->assertSee('Message selection')
            ->assertSee('Webinar Attended Nurture')
            ->assertSee('Step 1')
            ->assertSee('Active template')
            ->assertSee('Step 1 Email')
            ->assertSee('Save selection')
            ->assertSee('Edit copy')
            ->assertSee('Campaigns decide the journey, timing, and step order')
            ->assertDontSee('Subject')
            ->assertDontSee('Body')
            ->assertDontSee('Template title');
    }

    public function test_it_updates_the_selected_template_for_a_campaign_step(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $oldPreset] = $this->campaignStepWithTemplate();
        $newPreset = $this->templateForCampaignStep($campaign, $step, [
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variant',
            'name' => 'Webinar Attended Nurture — Alternate Step 1 Email',
            'payload' => [
                'subject' => 'Alternate subject',
                'body' => 'Alternate body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($oldPreset)
            ->forCampaignStep($campaign->key, $step->step_number)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'message_type' => $oldPreset->message_type,
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/campaigns/message-templates/steps/'.$step->getKey(), [
                'message_template_preset_id' => $newPreset->getKey(),
            ])
            ->assertRedirect(route('crm.campaigns.message-templates.index', [
                'campaign' => $campaign->getKey(),
                'step' => $step->getKey(),
            ]));

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $newPreset->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'surface' => 'campaigns',
            'campaign_key' => 'webinar_attended_nurture',
            'campaign_step' => 1,
            'is_active' => true,
        ]);

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
        );

        $this->assertIsArray($definition);
        $this->assertSame('Alternate subject', $definition['payload']['subject']);
        $this->assertNull($definition['config_path']);
    }

    public function test_it_rejects_a_template_that_is_not_cataloged_for_the_campaign_step(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step] = $this->campaignStepWithTemplate();

        $wrongPreset = MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.webinar_nurture.campaigns.other_campaign.steps.1',
            'name' => 'Other Campaign — Step 1 Email',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'other_campaign_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($wrongPreset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:other_campaign',
                'group_label' => 'Other Campaign',
                'item_key' => 'email.marketing.webinar_nurture.campaigns.other_campaign.steps.1',
                'item_label' => 'Step 1 Email',
                'item_order' => 1,
                'usage_type' => 'campaign_step',
                'meta' => [
                    'campaign_key' => 'other_campaign',
                    'campaign_step' => 1,
                ],
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/campaigns/message-templates?campaign='.$campaign->getKey().'&step='.$step->getKey())
            ->patch('http://crm.'.config('app.root_domain').'/campaigns/message-templates/steps/'.$step->getKey(), [
                'message_template_preset_id' => $wrongPreset->getKey(),
            ])
            ->assertSessionHasErrors(['message_template_preset_id']);
    }

    /**
     * @return array{0: Campaign, 1: CampaignStep, 2: MessageTemplatePreset}
     */
    private function campaignStepWithTemplate(): array
    {
        $campaign = Campaign::factory()->create([
            'key' => 'webinar_attended_nurture',
            'name' => 'Webinar Attended Nurture',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ]);

        $step = CampaignStep::factory()
            ->forCampaign($campaign)
            ->create([
                'step_number' => 1,
                'name' => 'Step 1',
                'dispatch_key' => 'campaign_step_due',
            ]);

        $preset = $this->templateForCampaignStep($campaign, $step);

        return [$campaign, $step, $preset];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function templateForCampaignStep(Campaign $campaign, CampaignStep $step, array $overrides = []): MessageTemplatePreset
    {
        $preset = MessageTemplatePreset::factory()->create(array_replace_recursive([
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.'.$step->step_number.'.'.uniqid(),
            'name' => 'Webinar Attended Nurture — Step '.$step->step_number.' Email',
            'channel' => $step->channel,
            'purpose' => $step->purpose,
            'scope' => $step->scope,
            'message_type' => $campaign->key.'_step_'.$step->step_number,
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Step '.$step->step_number,
                'body' => 'Message '.$step->step_number.'.',
            ],
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.'.$campaign->key.'.steps.'.$step->step_number,
        ], $overrides));

        MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:'.$campaign->key,
                'group_label' => $campaign->name,
                'item_key' => $preset->key,
                'item_label' => 'Step '.$step->step_number.' Email',
                'item_order' => $step->step_number,
                'usage_type' => 'campaign_step',
                'source_config_path' => $preset->source_config_path,
                'meta' => [
                    'campaign_key' => $campaign->key,
                    'campaign_step' => $step->step_number,
                ],
            ]);

        return $preset;
    }
}
