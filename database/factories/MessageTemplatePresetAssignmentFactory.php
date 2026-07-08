<?php

namespace Database\Factories;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageTemplatePresetAssignmentFactory extends Factory
{
    protected $model = MessageTemplatePresetAssignment::class;

    public function definition(): array
    {
        return [
            'message_template_preset_id' => MessageTemplatePreset::factory(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'surface' => null,
            'message_type' => 'confirmation',
            'campaign_key' => null,
            'campaign_step' => null,
            'campaign_step_variant_key' => null,
            'source_config_path' => null,
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
            'meta' => [],
        ];
    }

    public function forPreset(MessageTemplatePreset $preset): static
    {
        return $this->state(fn () => [
            'message_template_preset_id' => $preset->getKey(),
            'channel' => $preset->channel,
            'purpose' => $preset->purpose,
            'scope' => $preset->scope,
            'message_type' => $preset->message_type,
        ]);
    }

    public function forCampaignStep(string $campaignKey, int $stepNumber): static
    {
        return $this->state(fn () => [
            'surface' => 'campaigns',
            'campaign_key' => $this->normalizeSegment($campaignKey),
            'campaign_step' => $stepNumber,
            'campaign_step_variant_key' => null,
        ]);
    }

    public function forCampaignStepVariant(
        string $campaignKey,
        int $stepNumber,
        string $variantKey,
        ?string $sourceConfigPath = null,
    ): static {
        return $this->state(fn () => [
            'surface' => 'campaigns',
            'campaign_key' => $this->normalizeSegment($campaignKey),
            'campaign_step' => $stepNumber,
            'campaign_step_variant_key' => $this->normalizeSegment($variantKey),
            'source_config_path' => $sourceConfigPath,
        ]);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
