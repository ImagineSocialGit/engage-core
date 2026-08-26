<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;

class DirectMessageTemplateResolver
{
    public function preset(string $templateKey): ?MessageTemplatePreset
    {
        $templateKey = trim($templateKey);

        if ($templateKey === '') {
            return null;
        }

        $preset = MessageTemplatePreset::query()
            ->active()
            ->where('key', $templateKey)
            ->with('canonicalTemplate.currentVersion')
            ->first();

        if (! $preset instanceof MessageTemplatePreset) {
            return null;
        }

        $template = $preset->canonicalTemplate;

        if (! $template instanceof MessageTemplate
            || ! $template->isActive()
            || ! $template->currentVersion
        ) {
            return null;
        }

        return $preset;
    }

    /** @return array<string, mixed>|null */
    public function definition(string $templateKey): ?array
    {
        $preset = $this->preset($templateKey);

        if (! $preset instanceof MessageTemplatePreset) {
            return null;
        }

        $definition = $preset->toMessageDefinition();

        foreach (['channel', 'purpose', 'scope', 'message_type', 'payload_class'] as $required) {
            if (! is_string($definition[$required] ?? null) || trim($definition[$required]) === '') {
                return null;
            }
        }

        if ($preset->dispatchKeys() === []) {
            return null;
        }

        return $definition;
    }
}