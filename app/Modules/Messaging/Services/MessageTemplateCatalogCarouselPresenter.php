<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Support\ReplyHandling\Data\ReplyProfilePresentation;
use App\Support\ReplyHandling\ReplyProfilePresentationRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageTemplateCatalogCarouselPresenter
{
    public function __construct(
        private readonly MessageTemplateUsageResolver $usageResolver,
        private readonly ReplyProfilePresentationRegistry $replyProfiles,
    ) {}

    /**
     * Build the canonical one-message-at-a-time editor presentation for a
     * Message Templates catalog group.
     *
     * Reply handling remains assignment-owned. The reusable template only
     * presents the reply profiles carried by its active usages and links back
     * to the usage owner for association changes.
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

        /** @var array<int, array<string, mixed>> $replyHandlingByPreset */
        $replyHandlingByPreset = [];
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

            if (! isset($replyHandlingByPreset[(int) $preset->getKey()])) {
                $replyHandlingByPreset[(int) $preset->getKey()] = $this->replyHandlingForPreset($preset);
            }

            if (! isset($channels[$channel])) {
                $channels[$channel] = [
                    'key' => $channel,
                    'label' => $this->channelLabel($channel),
                    'messages' => [],
                ];
            }

            $channels[$channel]['messages'][] = array_replace([
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
            ], $replyHandlingByPreset[(int) $preset->getKey()]);
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

    /** @return array<string, mixed> */
    private function replyHandlingForPreset(MessageTemplatePreset $preset): array
    {
        $usages = $this->usageResolver
            ->forPreset($preset)
            ->filter(fn (array $usage): bool =>
                is_string($usage['reply_profile_key'] ?? null)
                && trim((string) $usage['reply_profile_key']) !== ''
            )
            ->map(function (array $usage): array {
                $profileKey = trim((string) $usage['reply_profile_key']);
                $profile = $this->replyProfiles->find($profileKey);

                return [
                    'assignment_id' => (int) $usage['assignment_id'],
                    'module_label' => (string) $usage['module_label'],
                    'context_label' => (string) $usage['context_label'],
                    'item_label' => (string) $usage['item_label'],
                    'detail' => $usage['detail'],
                    'owner_url' => $usage['url'],
                    'reply_profile_key' => $profileKey,
                    'reply_handling' => $profile?->toArray(),
                ];
            })
            ->values();

        $profileKeys = $usages
            ->pluck('reply_profile_key')
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->unique()
            ->values();

        $singleProfile = null;

        if ($profileKeys->count() === 1) {
            $singleProfile = $this->replyProfiles->find((string) $profileKeys->first());
        }

        return [
            'reply_profile_key' => $singleProfile?->key,
            'reply_handling' => $singleProfile instanceof ReplyProfilePresentation
                ? $singleProfile->toArray()
                : null,
            'reply_handling_index_url' => $this->replyProfiles->indexUrl(),
            'reply_handling_usages' => $usages->all(),
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