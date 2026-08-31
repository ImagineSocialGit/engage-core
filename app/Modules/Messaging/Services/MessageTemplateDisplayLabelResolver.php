<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Str;

class MessageTemplateDisplayLabelResolver
{
    /**
     * Resolve a concise label for a template picker where channel and purpose
     * are presented separately.
     */
    public function selectionLabel(MessageTemplatePreset $preset): string
    {
        $entry = $preset->relationLoaded('catalogEntries')
            ? $preset->catalogEntries
                ->where('is_active', true)
                ->sortBy(fn (MessageTemplateCatalogEntry $candidate): string =>
                    str_pad((string) $candidate->item_order, 12, '0', STR_PAD_LEFT)
                    .'|'.$candidate->item_label)
                ->first()
            : $preset->catalogEntries()
                ->active()
                ->orderBy('item_order')
                ->orderBy('item_label')
                ->first();

        if (! $entry instanceof MessageTemplateCatalogEntry) {
            return trim((string) $preset->name) ?: 'Message';
        }

        $group = $this->withoutTrailingChannel((string) $entry->group_label);
        $item = $this->withoutTrailingChannel($this->label($entry));

        if ($group === '') {
            return $item !== '' ? $item : 'Message';
        }

        if ($item === ''
            || strcasecmp($group, $item) === 0
            || Str::endsWith(Str::lower($group), ' — '.Str::lower($item))
        ) {
            return $group;
        }

        return $group.' — '.$item;
    }

    /**
     * Resolve the human-facing label for a catalogued reusable message.
     *
     * Catalog item labels remain stable technical/catalog metadata. This
     * resolver only changes presentation, so sequence numbers, assignment
     * identity, template keys, and runtime lookup behavior remain untouched.
     *
     * @param array<string, mixed>|null $payload
     */
    public function label(
        MessageTemplateCatalogEntry $entry,
        ?array $payload = null,
    ): string {
        $preset = $entry->messageTemplatePreset;
        $rawItemLabel = trim((string) $entry->item_label);

        if ($rawItemLabel !== '' && ! $this->looksTechnical($rawItemLabel)) {
            return $rawItemLabel;
        }

        if ($preset instanceof MessageTemplatePreset) {
            $semanticType = $this->semanticMessageTypeLabel(
                (string) ($preset->message_type ?? ''),
                (string) ($entry->item_key ?? ''),
                (string) ($preset->key ?? ''),
            );

            if ($semanticType !== null) {
                return $semanticType;
            }
        }

        $payload ??= $this->payload($preset);
        $channel = strtolower(trim((string) ($entry->channel ?: $preset?->channel)));

        if ($channel === 'email') {
            $subject = $this->cleanText($payload['subject'] ?? null);

            if ($subject !== null) {
                return $subject;
            }
        }

        if ($channel === 'sms') {
            $message = $this->cleanText($payload['message'] ?? null);

            if ($message !== null) {
                return Str::limit($message, 72);
            }
        }

        if ($preset instanceof MessageTemplatePreset) {
            $presetName = trim((string) $preset->name);

            if ($presetName !== '' && ! $this->looksTechnical($presetName)) {
                return $presetName;
            }
        }

        if ($rawItemLabel !== '') {
            return $rawItemLabel;
        }

        return 'Message';
    }

    /** @return array<string, mixed> */
    public function payload(?MessageTemplatePreset $preset): array
    {
        if (! $preset instanceof MessageTemplatePreset) {
            return [];
        }

        $preset->loadMissing('canonicalTemplate.currentVersion');
        $template = $preset->canonicalTemplate;

        if ($template) {
            $payload = $template->currentPayload();

            if ($payload !== []) {
                return $payload;
            }
        }

        return is_array($preset->payload) ? $preset->payload : [];
    }

    private function looksTechnical(string $label): bool
    {
        $label = trim($label);

        return preg_match('/^(?:step|reminder)\s+\d+(?:\s+(?:email|sms))?$/i', $label) === 1
            || preg_match('/^(?:email|sms)\s+(?:step|reminder)\s+\d+$/i', $label) === 1
            || preg_match('/\b(?:step|reminder)\s+\d+\s+(?:email|sms)$/i', $label) === 1;
    }

    private function semanticMessageTypeLabel(
        string $messageType,
        string $itemKey,
        string $presetKey,
    ): ?string {
        $identity = strtolower(implode(' ', [
            trim($messageType),
            trim($itemKey),
            trim($presetKey),
        ]));

        foreach ([
            '/reminder[_\-. ]+(\d+)[_\-. ]+(minute|hour|day|week)s?/',
            '/(\d+)[_\-. ]+(minute|hour|day|week)s?[_\-. ]+reminder/',
        ] as $pattern) {
            if (preg_match($pattern, $identity, $matches) === 1) {
                $amount = (int) $matches[1];
                $unit = Str::headline((string) $matches[2]);

                return $amount.'-'.$unit.' Reminder';
            }
        }

        return null;
    }

    private function cleanText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\s+/', ' ', trim($value)) ?? '';

        return $value !== '' ? $value : null;
    }

    private function withoutTrailingChannel(string $label): string
    {
        return trim((string) preg_replace(
            '/(?:\s+—\s+|\s+)(?:email|sms|text)$/i',
            '',
            trim($label),
        ));
    }
}