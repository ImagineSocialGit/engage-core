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
                'registration_opt_in',
                'reminders',
                'waitlist',
                'waitlist_opt_in',
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

    public function messageTemplateCatalogEntry(): MessageTemplateCatalogEntry
    {
        return MessageTemplateCatalogEntry::query()
            ->active()
            ->with('messageTemplatePreset')
            ->where('module_key', 'webinars')
            ->findOrFail((int) $this->validated('catalog_entry_id'));
    }

    private function isCompatible(MessageTemplatePreset $preset): bool
    {
        if (! $preset->isActive()) {
            return false;
        }

        $catalogEntry = MessageTemplateCatalogEntry::query()
            ->active()
            ->with('messageTemplatePreset')
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

        $targetDefinitionKey = $this->definitionKeyForCatalogEntry($catalogEntry);

        if ($targetDefinitionKey === null) {
            return false;
        }

        return MessageTemplateCatalogEntry::query()
            ->active()
            ->with('messageTemplatePreset')
            ->where('message_template_preset_id', $preset->getKey())
            ->where('module_key', 'webinars')
            ->where('usage_type', $catalogEntry->usage_type)
            ->where('channel', $this->normalizeSegment((string) $this->input('channel')))
            ->where('purpose', $this->normalizeSegment((string) $this->input('purpose')))
            ->where('scope', $this->normalizeSegment((string) $this->input('scope')))
            ->get()
            ->contains(fn (MessageTemplateCatalogEntry $candidate): bool => $this->definitionKeyForCatalogEntry($candidate) === $targetDefinitionKey);
    }

    private function definitionKeyForCatalogEntry(MessageTemplateCatalogEntry $entry): ?string
    {
        $definitionKey = $this->normalizeNullableSegment(data_get($entry->meta, 'definition_key'))
            ?? $this->normalizeNullableSegment(data_get($entry->messageTemplatePreset?->meta, 'seed.definition_key'));

        if ($definitionKey !== null) {
            return $definitionKey;
        }

        foreach ([$entry->source_config_path, $entry->messageTemplatePreset?->source_config_path] as $sourceConfigPath) {
            if (! is_string($sourceConfigPath) || trim($sourceConfigPath) === '') {
                continue;
            }

            $definition = config(trim($sourceConfigPath));
            $definitionKey = is_array($definition)
                ? $this->normalizeNullableSegment($definition['key'] ?? null)
                : null;

            if ($definitionKey !== null) {
                return $definitionKey;
            }
        }

        return null;
    }

    private function normalizeNullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(?string $value): string
    {
        return str_replace('-', '_', strtolower(trim((string) $value)));
    }
}
