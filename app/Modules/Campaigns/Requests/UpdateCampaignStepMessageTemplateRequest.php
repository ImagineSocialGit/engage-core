<?php

namespace App\Modules\Campaigns\Requests;

use App\Modules\Campaigns\Models\CampaignStep;
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
            'message_template_preset_id' => [
                'required',
                'integer',
                Rule::exists('message_template_presets', 'id'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $step = $this->route('campaignStep');
                    $preset = MessageTemplatePreset::query()->find($value);

                    if (! $step instanceof CampaignStep || ! $preset instanceof MessageTemplatePreset || ! $this->isCompatible($step, $preset)) {
                        $fail('Choose a compatible template for this campaign step.');
                    }
                },
            ],
        ];
    }

    public function messageTemplatePreset(): MessageTemplatePreset
    {
        return MessageTemplatePreset::query()->findOrFail((int) $this->validated('message_template_preset_id'));
    }

    private function isCompatible(CampaignStep $step, MessageTemplatePreset $preset): bool
    {
        $step->loadMissing('campaign');

        if (! $step->campaign) {
            return false;
        }

        if (! $preset->isActive()) {
            return false;
        }

        return MessageTemplateCatalogEntry::query()
            ->active()
            ->where('message_template_preset_id', $preset->getKey())
            ->where('usage_type', 'campaign_step')
            ->where('channel', $this->normalizeSegment($step->channel))
            ->where('purpose', $this->normalizeSegment($step->purpose))
            ->where('scope', $this->normalizeSegment($step->scope))
            ->where('meta->campaign_key', $this->normalizeSegment($step->campaign->key))
            ->where('meta->campaign_step', (int) $step->step_number)
            ->exists();
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
