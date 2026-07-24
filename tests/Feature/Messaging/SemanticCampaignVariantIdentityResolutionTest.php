<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SemanticCampaignVariantIdentityResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_semantic_variant_identity_beats_stale_source_config_path(): void
    {
        $primary = $this->preset(
            key: 'webinar_attended_nurture.step_1.email_primary',
            variantKey: 'email_primary',
            subject: 'Primary subject',
        );

        $alternate = $this->preset(
            key: 'webinar_attended_nurture.step_1.email_alternate',
            variantKey: 'email_alternate',
            subject: 'Alternate subject',
        );

        $this->assign($primary, 'email_primary');
        $alternateAssignment = $this->assign($alternate, 'email_alternate');

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_alternate',
        );

        $this->assertIsArray($definition);
        $this->assertSame('Alternate subject', $definition['payload']['subject']);
        $this->assertSame('email_alternate', $definition['variant']);
        $this->assertSame(
            $alternateAssignment->getKey(),
            data_get($definition, 'meta.message_template_preset.assignment_id'),
        );
    }

    public function test_stale_source_config_path_cannot_retarget_an_unknown_semantic_variant(): void
    {
        $legacy = $this->preset(
            key: 'webinar_attended_nurture.step_1.email_legacy',
            variantKey: 'email_legacy',
            subject: 'Legacy subject',
        );

        $this->assign($legacy, 'email_legacy');

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_renamed',
        );

        $this->assertNull($definition);
    }

    private function preset(
        string $key,
        string $variantKey,
        string $subject,
    ): MessageTemplatePreset {
        return MessageTemplatePreset::factory()->create([
            'key' => $key,
            'name' => $subject,
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'webinar_attended_nurture_step_1',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'dispatch_keys' => ['campaign_step_due'],
            'payload' => [
                'subject' => $subject,
                'body' => 'Body for '.$subject.'.',
            ],
            'source_config_path' => $this->sourceConfigPath($variantKey),
        ]);
    }

    private function assign(
        MessageTemplatePreset $preset,
        string $variantKey,
    ): MessageTemplatePresetAssignment {
        return MessageTemplatePresetAssignment::factory()
            ->forPreset($preset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: $variantKey,
                sourceConfigPath: $preset->source_config_path,
            )
            ->create();
    }

    private function sourceConfigPath(string $variantKey): string
    {
        return 'messaging.email.definitions.marketing.webinar_nurture.campaigns.'
            .'webinar_attended_nurture.steps.1.variants.'.$variantKey;
    }
}