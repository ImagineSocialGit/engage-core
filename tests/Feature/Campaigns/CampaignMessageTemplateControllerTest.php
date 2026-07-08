<?php

namespace Tests\Feature\Campaigns;

use App\Http\Middleware\ForceStagingAccess;
use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignMessageTemplateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_campaign_step_variants_and_template_selection_without_copy_editing(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $emailVariant, $emailPreset] = $this->campaignStepVariantWithTemplate();

        MessageTemplatePresetAssignment::factory()
            ->forPreset($emailPreset)
            ->forCampaignStepVariant($campaign->key, $step->step_number, $emailVariant->key, $emailVariant->source_config_path)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'message_type' => $emailPreset->message_type,
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
            ->assertSee('Delivery options')
            ->assertSee('Email follow-up')
            ->assertSee('Active template')
            ->assertSee('Step 1 Email')
            ->assertSee('Save selection')
            ->assertSee('Edit copy')
            ->assertSee(route('crm.messaging.message-templates.index', ['module' => 'campaigns']), false)
            ->assertSee('Campaigns decide the journey, business moments, timing, and step order')
            ->assertDontSee('Subject')
            ->assertDontSee('Body')
            ->assertDontSee('Template title');
    }

    public function test_it_updates_the_selected_template_for_a_campaign_step_variant(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $emailVariant, $oldPreset] = $this->campaignStepVariantWithTemplate();
        $newPreset = $this->templateForCampaignStepVariant($campaign, $step, $emailVariant, [
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email.alternate',
            'name' => 'Webinar Attended Nurture — Alternate Step 1 Email',
            'payload' => [
                'subject' => 'Alternate subject',
                'body' => 'Alternate body.',
            ],
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($oldPreset)
            ->forCampaignStepVariant($campaign->key, $step->step_number, $emailVariant->key, $emailVariant->source_config_path)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'message_type' => $oldPreset->message_type,
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->patch('http://crm.'.config('app.root_domain').'/campaigns/message-templates/steps/'.$step->getKey(), [
                'campaign_step_variant_id' => $emailVariant->getKey(),
                'message_template_preset_id' => $newPreset->getKey(),
            ])
            ->assertRedirect(route('crm.campaigns.message-templates.index', [
                'campaign' => $campaign->getKey(),
                'step' => $step->getKey(),
                'variant' => $emailVariant->getKey(),
            ]));

        $this->assertDatabaseHas('message_template_preset_assignments', [
            'message_template_preset_id' => $newPreset->getKey(),
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'surface' => 'campaigns',
            'campaign_key' => 'webinar_attended_nurture',
            'campaign_step' => 1,
            'campaign_step_variant_key' => 'email',
            'source_config_path' => 'presets.campaigns.definitions.webinar_attended_nurture.steps.1.variants.email',
            'is_active' => true,
        ]);

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email',
            variantSourceConfigPath: $emailVariant->source_config_path,
        );

        $this->assertIsArray($definition);
        $this->assertSame('Alternate subject', $definition['payload']['subject']);
        $this->assertNull($definition['config_path']);
    }

    public function test_it_rejects_a_template_that_is_not_cataloged_for_the_campaign_step_variant(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $emailVariant] = $this->campaignStepVariantWithTemplate();

        $wrongPreset = MessageTemplatePreset::factory()->create([
            'key' => 'sms.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
            'name' => 'SMS Variant Template',
            'channel' => 'sms',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => SmsPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
        ]);

        MessageTemplateCatalogEntry::factory()
            ->forPreset($wrongPreset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:webinar_attended_nurture',
                'group_label' => 'Webinar Attended Nurture',
                'item_key' => $wrongPreset->key,
                'item_label' => 'Step 1 SMS',
                'item_order' => 1,
                'usage_type' => 'campaign_step',
                'source_config_path' => 'messaging.sms.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.sms',
                'meta' => [
                    'campaign_key' => 'webinar_attended_nurture',
                    'campaign_step' => 1,
                    'campaign_step_variant_key' => 'sms',
                    'campaign_step_variant_source_config_path' => 'presets.campaigns.definitions.webinar_attended_nurture.steps.1.variants.sms',
                ],
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->from('http://crm.'.config('app.root_domain').'/campaigns/message-templates?campaign='.$campaign->getKey().'&step='.$step->getKey())
            ->patch('http://crm.'.config('app.root_domain').'/campaigns/message-templates/steps/'.$step->getKey(), [
                'campaign_step_variant_id' => $emailVariant->getKey(),
                'message_template_preset_id' => $wrongPreset->getKey(),
            ])
            ->assertSessionHasErrors(['message_template_preset_id']);
    }

    public function test_index_accepts_campaign_key_and_step_number_for_linking_from_usage(): void
    {
        config()->set('modules.enabled', [
            'campaigns',
            'messaging',
        ]);

        $user = User::factory()->create();
        [$campaign, $step, $emailVariant, $preset] = $this->campaignStepVariantWithTemplate();

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant($campaign->key, $step->step_number, $emailVariant->key, $emailVariant->source_config_path)
            ->create([
                'channel' => 'email',
                'purpose' => 'marketing',
                'scope' => 'webinar_nurture',
                'message_type' => $preset->message_type,
            ]);

        $this->withoutMiddleware(ForceStagingAccess::class);

        $this->actingAs($user)
            ->get('http://crm.'.config('app.root_domain').'/campaigns/message-templates?campaign='.$campaign->key.'&step='.$step->step_number)
            ->assertOk()
            ->assertSee('Webinar Attended Nurture')
            ->assertSee('Step 1')
            ->assertSee('Email follow-up')
            ->assertSee('Active template')
            ->assertSee($preset->name);
    }

    /**
     * @return array{0: Campaign, 1: CampaignStep, 2: CampaignStepVariant, 3: MessageTemplatePreset}
     */
    private function campaignStepVariantWithTemplate(): array
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
                'variant_strategy' => 'send_all_eligible',
            ]);

        $emailVariant = CampaignStepVariant::factory()->create([
            'campaign_step_id' => $step->id,
            'key' => 'email',
            'name' => 'Email follow-up',
            'sort_order' => 0,
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'source_config_path' => 'presets.campaigns.definitions.webinar_attended_nurture.steps.1.variants.email',
        ]);

        $preset = $this->templateForCampaignStepVariant($campaign, $step, $emailVariant);

        return [$campaign, $step, $emailVariant, $preset];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function templateForCampaignStepVariant(
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
        array $overrides = [],
    ): MessageTemplatePreset {
        $preset = MessageTemplatePreset::factory()->create(array_replace_recursive([
            'key' => 'email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.'.$step->step_number.'.variants.'.$variant->key.'.'.uniqid(),
            'name' => 'Webinar Attended Nurture — Step '.$step->step_number.' '.ucfirst($variant->key),
            'channel' => $variant->channel,
            'purpose' => $variant->purpose,
            'scope' => $variant->scope,
            'message_type' => $campaign->key.'_step_'.$step->step_number,
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => 'Step '.$step->step_number,
                'body' => 'Message '.$step->step_number.'.',
            ],
            'source_config_path' => 'messaging.email.marketing.webinar_nurture.campaigns.'.$campaign->key.'.steps.'.$step->step_number.'.variants.'.$variant->key,
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
                'item_label' => 'Step '.$step->step_number.' '.ucfirst($variant->key),
                'item_order' => $step->step_number,
                'usage_type' => 'campaign_step',
                'source_config_path' => $preset->source_config_path,
                'meta' => [
                    'campaign_key' => $campaign->key,
                    'campaign_step' => $step->step_number,
                    'campaign_step_variant_key' => $variant->key,
                    'campaign_step_variant_source_config_path' => $variant->source_config_path,
                ],
            ]);

        return $preset;
    }
}


