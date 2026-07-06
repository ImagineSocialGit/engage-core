<?php

namespace App\Modules\Messaging\Requests;

use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateMessageTemplatePresetAssignmentRequest extends FormRequest
{
    private ?MessageTemplatePreset $selectedPreset = null;

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
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $assignment = $this->assignment();
            $preset = $this->selectedPreset();

            if (! $assignment instanceof MessageTemplatePresetAssignment || ! $preset instanceof MessageTemplatePreset) {
                return;
            }

            if (! $preset->isActive()) {
                $validator->errors()->add('message_template_preset_id', 'Choose an active message template.');

                return;
            }

            if (! $this->presetMatchesAssignment($preset, $assignment)) {
                $validator->errors()->add('message_template_preset_id', 'Choose a template for the same channel, purpose, scope, and message.');
            }
        });
    }

    public function selectedPreset(): MessageTemplatePreset
    {
        if ($this->selectedPreset instanceof MessageTemplatePreset) {
            return $this->selectedPreset;
        }

        $this->selectedPreset = MessageTemplatePreset::query()
            ->findOrFail($this->integer('message_template_preset_id'));

        return $this->selectedPreset;
    }

    private function assignment(): ?MessageTemplatePresetAssignment
    {
        $assignment = $this->route('messageTemplatePresetAssignment');

        return $assignment instanceof MessageTemplatePresetAssignment ? $assignment : null;
    }

    private function presetMatchesAssignment(
        MessageTemplatePreset $preset,
        MessageTemplatePresetAssignment $assignment,
    ): bool {
        return $preset->channel === $assignment->channel
            && $preset->purpose === $assignment->purpose
            && $preset->scope === $assignment->scope
            && $preset->message_type === $assignment->message_type;
    }
}
