<?php

namespace Database\Factories;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageTemplateCatalogEntryFactory extends Factory
{
    protected $model = MessageTemplateCatalogEntry::class;

    public function definition(): array
    {
        return [
            'message_template_preset_id' => MessageTemplatePreset::factory(),
            'channel' => 'email',
            'purpose' => 'transactional',
            'scope' => 'webinar',
            'module_key' => 'webinars',
            'module_label' => 'Webinars',
            'surface' => 'webinar_registrations',
            'group_key' => 'webinar:transactional:webinar:confirmation',
            'group_label' => 'Webinar Confirmations',
            'item_key' => 'webinar:transactional:webinar:confirmation:email',
            'item_label' => 'Confirmation Email',
            'item_order' => 0,
            'usage_type' => 'webinar_confirmation',
            'source' => 'factory',
            'source_config_path' => null,
            'context_type' => null,
            'context_id' => null,
            'is_active' => true,
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
        ]);
    }
}
