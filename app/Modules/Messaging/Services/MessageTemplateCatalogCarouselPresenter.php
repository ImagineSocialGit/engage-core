<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageTemplateCatalogCarouselPresenter
{
    /**
     * Build the canonical one-message-at-a-time editor presentation for a
     * Message Templates catalog group.
     *
     * @param Collection<int, MessageTemplateCatalogEntry> $entries
     * @return array<string, mixed>
     */
    public function present(Collection $entries): array
    {
        $entries = $entries
            ->filter(fn (mixed $entry): bool =>
                $entry instanceof MessageTemplateCatalogEntry
                && $entry->messageTemplatePreset instanceof MessageTemplatePreset
            )
            ->values();

        if ($entries->isEmpty()) {
            return [
                'message_count' => 0,
                'channels' => [],
            ];
        }

        $templateKeys = $entries
            ->map(fn (MessageTemplateCatalogEntry $entry): string =>
                (string) $entry->messageTemplatePreset->key
            )
            ->filter()
            ->unique()
            ->values();

        $templates = MessageTemplate::query()
            ->with('currentVersion')
            ->whereIn('key', $templateKeys)
            ->get()
            ->keyBy('key');

        $channels = [];

        foreach ($entries as $entry) {
            $preset = $entry->messageTemplatePreset;
            $channel = $this->normalizeChannel(
                (string) ($entry->channel ?: $preset->channel),
            );
            $template = $templates->get($preset->key);
            $version = $template instanceof MessageTemplate
                ? $template->currentVersion
                : null;
            $payload = $version instanceof MessageTemplateVersion
                ? $version->payload()
                : (is_array($preset->payload) ? $preset->payload : []);

            if (! isset($channels[$channel])) {
                $channels[$channel] = [
                    'key' => $channel,
                    'label' => $this->channelLabel($channel),
                    'messages' => [],
                ];
            }

            $channels[$channel]['messages'][] = [
                'id' => 'preset:'.$preset->getKey(),
                'preset_id' => (int) $preset->getKey(),
                'step_name' => $entry->item_label ?: $preset->name,
                'template_name' => $preset->name,
                'template_key' => $preset->key,
                'template_version' => $version?->version,
                'channel' => $channel,
                'channel_label' => $this->channelLabel($channel, plural: false),
                'purpose' => $entry->purpose ?: $preset->purpose,
                'scope' => $entry->scope ?: $preset->scope,
                'message_type' => $preset->message_type,
                'message_type_label' => Str::headline((string) $preset->message_type),
                'timing' => $preset->description ?: 'Reusable published message',
                'area_label' => $entry->module_label,
                'payload' => $payload,
                'edit_payload' => $payload,
                'update_action' => route(
                    'crm.messaging.message-templates.update',
                    $preset,
                ),
                'details_url' => route(
                    'crm.messaging.message-templates.index',
                    array_filter([
                        'channel' => $entry->channel ?: $preset->channel,
                        'purpose' => $entry->purpose ?: $preset->purpose,
                        'module' => $entry->module_key,
                        'group' => $entry->group_key,
                        'preset' => $preset->getKey(),
                    ], static fn (mixed $value): bool =>
                        $value !== null && $value !== ''
                    ),
                ),
                'edit_note' => 'Publishing creates a new immutable message version. Existing scheduled messages stay pinned to the version they already use.',
            ];
        }

        foreach ($channels as &$channel) {
            $channel['count'] = count($channel['messages']);
        }
        unset($channel);

        return [
            'message_count' => array_sum(array_map(
                static fn (array $channel): int => count($channel['messages']),
                $channels,
            )),
            'channels' => $channels,
        ];
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));

        return $channel !== '' ? $channel : 'message';
    }

    private function channelLabel(string $channel, bool $plural = true): string
    {
        return match ($channel) {
            'email' => $plural ? 'Emails' : 'Email',
            'sms' => 'SMS',
            default => Str::headline($channel).($plural ? ' messages' : ''),
        };
    }
}