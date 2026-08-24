<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MessageChainVersionCarouselPresenter
{
    /** @return array<string, mixed> */
    public function present(MessageChainVersion $version): array
    {
        $version->loadMissing([
            'messageChain',
            'steps.variants.messageTemplateVersion.messageTemplate',
        ]);

        /** @var Collection<int, MessageChainStepVariant> $variants */
        $variants = $version->steps
            ->flatMap(fn (MessageChainStep $step): Collection => $step->variants)
            ->values();

        $templateKeys = $variants
            ->map(fn (MessageChainStepVariant $variant): ?string =>
                $variant->messageTemplateVersion?->messageTemplate?->key
            )
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->unique()
            ->values();

        $presets = MessageTemplatePreset::query()
            ->active()
            ->whereIn('key', $templateKeys->all())
            ->get()
            ->keyBy('key');

        $channels = [];

        foreach ($version->steps as $stepPosition => $step) {
            if (! $step instanceof MessageChainStep || ! $step->is_active) {
                continue;
            }

            foreach ($step->variants as $variant) {
                if (! $variant instanceof MessageChainStepVariant || ! $variant->is_active) {
                    continue;
                }

                $templateVersion = $variant->messageTemplateVersion;
                $template = $templateVersion?->messageTemplate;
                $templateKey = $template instanceof MessageTemplate
                    ? (string) $template->key
                    : '';
                $preset = $presets->get($templateKey);
                $channel = $this->normalizeChannel((string) $variant->channel);

                if (! isset($channels[$channel])) {
                    $channels[$channel] = [
                        'key' => $channel,
                        'label' => $this->channelLabel($channel),
                        'messages' => [],
                    ];
                }

                $payload = $templateVersion instanceof MessageTemplateVersion
                    ? $templateVersion->payload()
                    : [];

                $channels[$channel]['messages'][] = [
                    'id' => 'chain-variant:'.$variant->getKey(),
                    'preset_id' => $preset instanceof MessageTemplatePreset
                        ? (int) $preset->getKey()
                        : null,
                    'message_chain_step_id' => (int) $step->getKey(),
                    'message_chain_step_variant_id' => (int) $variant->getKey(),
                    'step_key' => (string) $step->key,
                    'step_name' => trim((string) $step->name) !== ''
                        ? (string) $step->name
                        : 'Message '.($stepPosition + 1),
                    'template_name' => $template instanceof MessageTemplate
                        ? (string) $template->name
                        : ($preset instanceof MessageTemplatePreset ? (string) $preset->name : 'Message'),
                    'template_key' => $templateKey,
                    'template_version' => $templateVersion?->version,
                    'channel' => $channel,
                    'channel_label' => $this->channelLabel($channel, plural: false),
                    'purpose' => (string) $variant->purpose,
                    'scope' => (string) $variant->scope,
                    'message_type' => (string) $variant->message_type,
                    'message_type_label' => Str::headline((string) $variant->message_type),
                    'timing' => $this->timingLabel($step, $stepPosition),
                    'area_label' => $version->messageChain?->name ?? 'Message chain',
                    'payload' => $payload,
                    'edit_payload' => $payload,
                    'update_action' => $preset instanceof MessageTemplatePreset
                        ? route('crm.messaging.message-templates.update', $preset)
                        : '',
                    'details_url' => $preset instanceof MessageTemplatePreset
                        ? route('crm.messaging.message-templates.index', [
                            'preset' => $preset->getKey(),
                        ])
                        : null,
                    'edit_note' => $preset instanceof MessageTemplatePreset
                        ? 'Publishing creates a new immutable message version. Existing scheduled messages and enrolled chains keep their pinned versions.'
                        : 'This pinned message has no active reusable template preset and cannot be edited here.',
                ];
            }
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

    private function timingLabel(MessageChainStep $step, int $position): string
    {
        return match ($step->timing_type) {
            MessageChainStep::TIMING_IMMEDIATE => $position === 0
                ? 'Immediately after the Campaign starts'
                : 'Immediately after the previous step finishes',
            MessageChainStep::TIMING_DELAY => $this->durationLabel(
                max(0, (int) $step->offset_seconds),
            ).' after '.($position === 0
                ? 'the Campaign starts'
                : 'the previous step finishes'),
            MessageChainStep::TIMING_ANCHORED => $this->anchoredTimingLabel($step),
            MessageChainStep::TIMING_NEXT_DAY_AT => sprintf(
                '%s day(s) after %s at %s',
                (int) $step->day_offset,
                Str::headline((string) $step->anchor_key),
                mb_substr((string) $step->local_time, 0, 5),
            ),
            default => Str::headline((string) $step->timing_type),
        };
    }

    private function anchoredTimingLabel(MessageChainStep $step): string
    {
        $anchor = Str::headline((string) $step->anchor_key);
        $seconds = (int) $step->offset_seconds;

        if ($seconds === 0) {
            return 'When '.$anchor.' occurs';
        }

        return $this->durationLabel(abs($seconds))
            .($seconds < 0 ? ' before ' : ' after ')
            .$anchor;
    }

    private function durationLabel(int $seconds): string
    {
        foreach ([
            86400 => 'day',
            3600 => 'hour',
            60 => 'minute',
        ] as $divisor => $unit) {
            if ($seconds >= $divisor && $seconds % $divisor === 0) {
                $value = intdiv($seconds, $divisor);

                return $value.' '.Str::plural($unit, $value);
            }
        }

        return $seconds.' '.Str::plural('second', $seconds);
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