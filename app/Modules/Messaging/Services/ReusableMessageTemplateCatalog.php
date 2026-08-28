<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;

class ReusableMessageTemplateCatalog
{
    private const LEGACY_BROADCAST_CONTEXT = 'broadcasts';
    private const LEGACY_ANNUAL_TOUCH_CONTEXT = 'campaign_annual_touch';

    /**
     * @param array<int, string> $channels
     * @return array<int, array{id: int, name: string, channel: string, payload: array<string, mixed>}>
     */
    public function definitions(
        array $channels = [],
        ?string $purpose = null,
        ?string $selectionContext = null,
    ): array {
        return $this->presets($channels, $purpose, $selectionContext)
            ->map(function (MessageTemplatePreset $preset): array {
                $template = $preset->canonicalTemplate;
                $payload = $template instanceof MessageTemplate
                    ? $template->currentPayload()
                    : (is_array($preset->payload) ? $preset->payload : []);

                return [
                    'id' => (int) $preset->getKey(),
                    'name' => (string) $preset->name,
                    'channel' => (string) $preset->channel,
                    'payload' => $this->payloadForChannel((string) $preset->channel, $payload),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Return operator-authored reusable templates that explicitly support the
     * requested selection context. Lifecycle-owned preset definitions are
     * excluded because they do not come through the CRM reusable authoring path.
     *
     * Legacy saved Broadcast messages predate authoring-context metadata. They
     * remain selectable in Broadcasts and Annual Touches so this refactor does
     * not strand existing operator-authored templates.
     *
     * @param array<int, string> $channels
     * @return Collection<int, MessageTemplatePreset>
     */
    public function presets(
        array $channels = [],
        ?string $purpose = null,
        ?string $selectionContext = null,
    ): Collection {
        $channels = $this->normalizedList($channels);
        $purpose = $this->nullableString($purpose);
        $selectionContext = $this->nullableString($selectionContext);

        return MessageTemplatePreset::query()
            ->active()
            ->where('source', CreateReusableMessageTemplateAction::SOURCE)
            ->whereHas('catalogEntries', fn ($query) => $query->active())
            ->when(
                $channels !== [],
                fn ($query) => $query->whereIn('channel', $channels),
            )
            ->when(
                $purpose !== null,
                fn ($query) => $query->where('purpose', $purpose),
            )
            ->with([
                'canonicalTemplate.currentVersion',
                'catalogEntries' => fn ($query) => $query->active(),
            ])
            ->orderBy('channel')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->when(
                $selectionContext !== null,
                fn (Collection $presets) => $presets
                    ->filter(fn (MessageTemplatePreset $preset): bool => $this->supportsSelectionContext(
                        $preset,
                        $selectionContext,
                    ))
                    ->values(),
            );
    }

    private function supportsSelectionContext(
        MessageTemplatePreset $preset,
        string $selectionContext,
    ): bool {
        foreach ($preset->catalogEntries as $entry) {
            if (! $entry instanceof MessageTemplateCatalogEntry || ! $entry->is_active) {
                continue;
            }

            $contexts = data_get($entry->meta, 'authoring.selection_contexts', []);

            if (is_array($contexts) && in_array($selectionContext, $contexts, true)) {
                return true;
            }

            if ($this->legacyBroadcastEntrySupports($entry, $selectionContext)) {
                return true;
            }
        }

        return false;
    }

    private function legacyBroadcastEntrySupports(
        MessageTemplateCatalogEntry $entry,
        string $selectionContext,
    ): bool {
        if ($entry->surface !== 'broadcasts' || $entry->usage_type !== 'broadcast_reuse') {
            return false;
        }

        return in_array($selectionContext, [
            self::LEGACY_BROADCAST_CONTEXT,
            self::LEGACY_ANNUAL_TOUCH_CONTEXT,
        ], true);
    }

    /** @param array<int, mixed> $values @return array<int, string> */
    private function normalizedList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? trim($value)
                : null,
            $values,
        ))));
    }

    private function nullableString(?string $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function payloadForChannel(string $channel, array $payload): array
    {
        $normalized = $channel === 'sms'
            ? [
                'message' => is_string($payload['message'] ?? null) ? $payload['message'] : '',
            ]
            : [
                'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : '',
                'body' => is_string($payload['body'] ?? null) ? $payload['body'] : '',
            ];

        if (is_array($payload['token_fallbacks'] ?? null)) {
            $normalized['token_fallbacks'] = array_values($payload['token_fallbacks']);
        }

        return $normalized;
    }
}