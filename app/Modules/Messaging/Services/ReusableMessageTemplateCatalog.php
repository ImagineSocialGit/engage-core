<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Collection;

class ReusableMessageTemplateCatalog
{
    /**
     * @param array<int, string> $channels
     * @return array<int, array{id: int, name: string, channel: string, payload: array<string, mixed>}>
     */
    public function definitions(array $channels = []): array
    {
        $channels = array_values(array_unique(array_filter(
            array_map(static fn (mixed $channel): string => is_string($channel) ? trim($channel) : '', $channels),
            static fn (string $channel): bool => $channel !== '',
        )));

        return $this->templates($channels)
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
     * @param array<int, string> $channels
     * @return Collection<int, MessageTemplatePreset>
     */
    private function templates(array $channels): Collection
    {
        return MessageTemplatePreset::query()
            ->active()
            ->where('source', CreateReusableMessageTemplateAction::SOURCE)
            ->whereHas('catalogEntries', fn ($query) => $query
                ->active()
                ->where('surface', CreateReusableMessageTemplateAction::SURFACE)
                ->where('usage_type', CreateReusableMessageTemplateAction::USAGE_TYPE))
            ->when(
                $channels !== [],
                fn ($query) => $query->whereIn('channel', $channels),
            )
            ->with('canonicalTemplate.currentVersion')
            ->orderBy('channel')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function payloadForChannel(string $channel, array $payload): array
    {
        if ($channel === 'sms') {
            return [
                'message' => is_string($payload['message'] ?? null) ? $payload['message'] : '',
            ];
        }

        return [
            'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : '',
            'body' => is_string($payload['body'] ?? null) ? $payload['body'] : '',
        ];
    }
}