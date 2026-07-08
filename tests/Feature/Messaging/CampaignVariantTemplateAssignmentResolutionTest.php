<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class CampaignVariantTemplateAssignmentResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_step_resolution_distinguishes_variant_specific_assignments(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_primary', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Config primary subject',
                'body' => 'Config primary body.',
            ],
        ]);

        Config::set('messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_alternate', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Config alternate subject',
                'body' => 'Config alternate body.',
            ],
        ]);

        $primaryPreset = $this->campaignVariantPreset(
            key: 'webinar_attended_nurture.step_1.email_primary',
            subject: 'Primary email subject',
            sourceConfigPath: 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_primary',
        );

        $alternatePreset = $this->campaignVariantPreset(
            key: 'webinar_attended_nurture.step_1.email_alternate',
            subject: 'Alternate email subject',
            sourceConfigPath: 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_alternate',
        );

        $primaryAssignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($primaryPreset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email_primary',
                sourceConfigPath: $primaryPreset->source_config_path,
            )
            ->create();

        $alternateAssignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($alternatePreset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email_alternate',
                sourceConfigPath: $alternatePreset->source_config_path,
            )
            ->create();

        $resolver = app(MessageDefinitionResolver::class);

        $primaryDefinition = $resolver->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_primary',
            variantSourceConfigPath: $primaryPreset->source_config_path,
        );

        $alternateDefinition = $resolver->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_alternate',
            variantSourceConfigPath: $alternatePreset->source_config_path,
        );

        $this->assertIsArray($primaryDefinition);
        $this->assertIsArray($alternateDefinition);
        $this->assertSame('Primary email subject', $primaryDefinition['payload']['subject']);
        $this->assertSame('Alternate email subject', $alternateDefinition['payload']['subject']);
        $this->assertSame('email_primary', $primaryDefinition['variant']);
        $this->assertSame('email_alternate', $alternateDefinition['variant']);
        $this->assertSame($primaryAssignment->getKey(), data_get($primaryDefinition, 'meta.message_template_preset.assignment_id'));
        $this->assertSame($alternateAssignment->getKey(), data_get($alternateDefinition, 'meta.message_template_preset.assignment_id'));
    }

    public function test_campaign_step_resolution_requires_variant_context(): void
    {
        Config::set('messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_primary', [
            'dispatch_key' => 'campaign_step_due',
            'payload_class' => EmailPayload::class,
            'queue' => 'marketing',
            'payload' => [
                'subject' => 'Variant config subject',
                'body' => 'Variant config body.',
            ],
        ]);

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
        );

        $this->assertNull($definition);
    }

    public function test_variant_specific_resolution_does_not_fall_back_to_broad_step_assignment(): void
    {
        $broadPreset = $this->campaignVariantPreset(
            key: 'webinar_attended_nurture.step_1.broad',
            subject: 'Broad step subject',
            sourceConfigPath: 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1',
        );

        MessageTemplatePresetAssignment::factory()
            ->forPreset($broadPreset)
            ->forCampaignStep('webinar_attended_nurture', 1)
            ->create();

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_primary',
        );

        $this->assertNull($definition);
    }

    public function test_context_specific_campaign_step_variant_assignment_beats_global_source_assignment(): void
    {
        $sourceConfigPath = 'messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email_primary';

        $globalPreset = $this->campaignVariantPreset(
            key: 'webinar_attended_nurture.step_1.email_primary.global',
            subject: 'Global source subject',
            sourceConfigPath: $sourceConfigPath,
        );

        $contextPreset = $this->campaignVariantPreset(
            key: 'webinar_attended_nurture.step_1.email_primary.context',
            subject: 'Context source subject',
            sourceConfigPath: $sourceConfigPath,
        );

        $context = MessageTemplatePreset::factory()->create([
            'key' => 'campaign_step_variant_context.email_primary',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
            'message_type' => 'campaign_step_variant_context',
        ]);

        MessageTemplatePresetAssignment::factory()
            ->forPreset($globalPreset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email_primary',
                sourceConfigPath: $sourceConfigPath,
            )
            ->create();

        $contextAssignment = MessageTemplatePresetAssignment::factory()
            ->forPreset($contextPreset)
            ->forCampaignStepVariant(
                campaignKey: 'webinar_attended_nurture',
                stepNumber: 1,
                variantKey: 'email_primary',
                sourceConfigPath: $sourceConfigPath,
            )
            ->create([
                'context_type' => $context->getMorphClass(),
                'context_id' => $context->getKey(),
            ]);

        $definition = app(MessageDefinitionResolver::class)->resolveCampaignStep(
            channel: 'email',
            purpose: 'marketing',
            scope: 'webinar_nurture',
            campaignKey: 'webinar_attended_nurture',
            stepNumber: 1,
            dispatchKey: 'campaign_step_due',
            variantKey: 'email_primary',
            variantSourceConfigPath: $sourceConfigPath,
            context: $context,
        );

        $this->assertIsArray($definition);
        $this->assertSame('Context source subject', $definition['payload']['subject']);
        $this->assertSame($contextAssignment->getKey(), data_get($definition, 'meta.message_template_preset.assignment_id'));
        $this->assertSame($contextAssignment->getKey(), data_get($definition, 'meta.message_template_assignment.id'));
    }

    private function campaignVariantPreset(
        string $key,
        string $subject,
        string $sourceConfigPath,
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
            'source_config_path' => $sourceConfigPath,
        ]);
    }
}

