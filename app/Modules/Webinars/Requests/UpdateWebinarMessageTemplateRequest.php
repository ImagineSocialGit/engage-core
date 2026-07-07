<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWebinarMessageTemplateRequest extends FormRequest
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
            'context_key' => ['required', 'string', Rule::in([
                'confirmation',
                'reminders',
                'waitlist',
                'post_attended',
                'post_missed',
            ])],
            'catalog_entry_id' => ['required', 'integer', Rule::exists('message_template_catalog_entries', 'id')],
            'channel' => ['required', 'string', Rule::in(['email', 'sms'])],
            'purpose' => ['required', 'string', Rule::in(['transactional', 'marketing'])],
            'scope' => ['required', 'string', Rule::in(['webinar', 'webinar_waitlist'])],
            'surface' => ['required', 'string', Rule::in(['webinar_registrations', 'webinar_waitlists'])],
            'message_type' => ['required', 'string', 'max:255'],
            'message_template_preset_id' => [
                'required',
                'integer',
                Rule::exists('message_template_presets', 'id'),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $preset = MessageTemplatePreset::query()->find($value);

                    if (! $preset instanceof MessageTemplatePreset || ! $this->isCompatible($preset)) {
                        $fail('Choose a compatible template for this webinar message.');
                    }
                },
            ],
        ];
    }

    public function messageTemplatePreset(): MessageTemplatePreset
    {
        return MessageTemplatePreset::query()->findOrFail((int) $this->validated('message_template_preset_id'));
    }

    private function isCompatible(MessageTemplatePreset $preset): bool
    {
        if (! $preset->isActive()) {
            return false;
        }

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->active()
            ->whereKey((int) $this->input('catalog_entry_id'))
            ->where('module_key', 'webinars')
            ->first();

        if (! $catalogEntry instanceof MessageTemplateCatalogEntry) {
            return false;
        }

        if ($this->normalizeSegment($preset->channel) !== $this->normalizeSegment((string) $this->input('channel'))) {
            return false;
        }

        if ($this->normalizeSegment($preset->purpose) !== $this->normalizeSegment((string) $this->input('purpose'))) {
            return false;
        }

        if ($this->normalizeSegment($preset->scope) !== $this->normalizeSegment((string) $this->input('scope'))) {
            return false;
        }

        if ($this->normalizeSegment($preset->message_type) !== $this->normalizeSegment((string) $this->input('message_type'))) {
            return false;
        }

        return MessageTemplateCatalogEntry::query()
            ->active()
            ->where('message_template_preset_id', $preset->getKey())
            ->where('module_key', 'webinars')
            ->where('usage_type', $catalogEntry->usage_type)
            ->where('channel', $this->normalizeSegment((string) $this->input('channel')))
            ->where('purpose', $this->normalizeSegment((string) $this->input('purpose')))
            ->where('scope', $this->normalizeSegment((string) $this->input('scope')))
            ->exists();
    }

    private function normalizeSegment(?string $value): string
    {
        return str_replace('-', '_', strtolower(trim((string) $value)));
    }
}
