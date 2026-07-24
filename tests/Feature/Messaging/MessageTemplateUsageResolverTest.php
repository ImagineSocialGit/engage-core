<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Services\MessageTemplateUsageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTemplateUsageResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_usage_catalog_selection_uses_semantic_variant_identity_instead_of_source_path(): void
    {
        $preset = MessageTemplatePreset::factory()->create([
            'key' => 'email.marketing.webinar_nurture.campaigns.'
                .'webinar_attended_nurture.steps.1.variants.email_alternate',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
        ]);

        $primaryPath = 'messaging.email.definitions.marketing.webinar_nurture.'
            .'campaigns.webinar_attended_nurture.steps.1.variants.email_primary';

        $this->catalogEntry(
            preset: $preset,
            variantKey: 'email_primary',
            itemLabel: 'Primary Email',
            sourceConfigPath: $primaryPath,
            itemOrder: 10,
        );
        $this->catalogEntry(
            preset: $preset,
            variantKey: 'email_alternate',
            itemLabel: 'Alternate Email',
            sourceConfigPath: 'messaging.email.definitions.marketing.'
                .'webinar_nurture.campaigns.webinar_attended_nurture.'
                .'steps.1.variants.email_alternate',
            itemOrder: 20,
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email_alternate',
                sourceConfigPath: $primaryPath,
            )
            ->create([
                'meta' => [
                    'source_config_path' => $primaryPath,
                    'catalog' => [
                        'item_key' => $preset->key.'.email_primary',
                    ],
                ],
            ]);

        $usage = app(MessageTemplateUsageResolver::class)
            ->forPreset($preset)
            ->sole();

        $this->assertSame('Campaigns', $usage['module_label']);
        $this->assertSame('Webinar Attended Nurture', $usage['context_label']);
        $this->assertSame('Alternate Email', $usage['item_label']);
    }

    private function catalogEntry(
        MessageTemplatePreset $preset,
        string $variantKey,
        string $itemLabel,
        string $sourceConfigPath,
        int $itemOrder,
    ): MessageTemplateCatalogEntry {
        return MessageTemplateCatalogEntry::factory()
            ->forPreset($preset)
            ->create([
                'module_key' => 'campaigns',
                'module_label' => 'Campaigns',
                'surface' => 'campaigns',
                'group_key' => 'campaign:webinar_attended_nurture',
                'group_label' => 'Webinar Attended Nurture',
                'item_key' => $preset->key.'.'.$variantKey,
                'item_label' => $itemLabel,
                'item_order' => $itemOrder,
                'usage_type' => 'campaign_step',
                'source_config_path' => $sourceConfigPath,
                'meta' => [
                    'campaign_key' => 'webinar_attended_nurture',
                    'campaign_step' => 1,
                    'campaign_step_variant_key' => $variantKey,
                ],
            ]);
    }
}