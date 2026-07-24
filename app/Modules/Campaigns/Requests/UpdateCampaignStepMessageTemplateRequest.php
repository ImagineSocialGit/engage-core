<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignStepMessageTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'campaign_step_variant_id' => [
                'required',
                'integer',
                Rule::exists('campaign_step_variants', 'id'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $step = $this->route('campaignStep');
                    $variant = CampaignStepVariant::query()->find($value);

                    if (! $step instanceof CampaignStep || ! $variant instanceof CampaignStepVariant || (int) $variant->campaign_step_id !== (int) $step->id) {
                        $fail('Choose a valid delivery option for this campaign step.');
                    }
                },
            ],
            'message_template_preset_id' => [
                'required',
                'integer',
                Rule::exists('message_template_presets', 'id'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $step = $this->route('campaignStep');
                    $variant = $this->campaignStepVariantOrNull();
                    $preset = MessageTemplatePreset::query()->find($value);

                    if (
                        ! $step instanceof CampaignStep
                        || ! $variant instanceof CampaignStepVariant
                        || ! $preset instanceof MessageTemplatePreset
                        || ! $this->isCompatible($step, $variant, $preset)
                    ) {
                        $fail('Choose a compatible template for this campaign delivery option.');
                    }
                },
            ],
        ];
    }

    public function messageTemplatePreset(): MessageTemplatePreset
    {
        return MessageTemplatePreset::query()->findOrFail((int) $this->validated('message_template_preset_id'));
    }

    public function campaignStepVariant(): CampaignStepVariant
    {
        return CampaignStepVariant::query()->findOrFail((int) $this->validated('campaign_step_variant_id'));
    }

    private function campaignStepVariantOrNull(): ?CampaignStepVariant
    {
        $value = $this->input('campaign_step_variant_id');

        return is_numeric($value)
            ? CampaignStepVariant::query()->find((int) $value)
            : null;
    }

    private function isCompatible(CampaignStep $step, CampaignStepVariant $variant, MessageTemplatePreset $preset): bool
    {
        $step->loadMissing('campaign');

        if (! $step->campaign) {
            return false;
        }

        if ((int) $variant->campaign_step_id !== (int) $step->id) {
            return false;
        }

        if (! $preset->isActive()) {
            return false;
        }

        return MessageTemplateCatalogEntry::query()
            ->active()
            ->where('message_template_preset_id', $preset->getKey())
            ->where('usage_type', 'campaign_step')
            ->where('channel', $this->normalizeSegment($variant->channel))
            ->where('purpose', $this->normalizeSegment($variant->purpose))
            ->where('scope', $this->normalizeSegment($variant->scope))
            ->where('meta->campaign_key', $this->normalizeSegment($step->campaign->key))
            ->where('meta->campaign_step', (int) $step->step_number)
            ->where('meta->campaign_step_variant_key', $this->normalizeSegment($variant->key))
            ->exists();
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}