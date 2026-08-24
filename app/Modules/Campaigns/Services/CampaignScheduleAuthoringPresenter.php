<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use Illuminate\Support\Str;

final class CampaignScheduleAuthoringPresenter
{
    /**
     * @return array{
     *     editable: bool,
     *     message_chain_version_id: int|null,
     *     version: int|null,
     *     steps: array<int, array<string, mixed>>,
     *     message_options: array<int, array{id: int, label: string, channel: string}>
     * }
     */
    public function forCampaign(Campaign $campaign): array
    {
        $campaign->loadMissing(
            'messageChain.currentVersion.steps.variants.messageTemplateVersion.messageTemplate',
        );
        $chain = $campaign->messageChain;
        $version = $chain instanceof MessageChain ? $chain->currentVersion : null;

        if (! $version instanceof MessageChainVersion || ! $version->isPublished()) {
            return [
                'editable' => false,
                'message_chain_version_id' => null,
                'version' => null,
                'steps' => [],
                'message_options' => [],
            ];
        }

        return [
            'editable' => true,
            'message_chain_version_id' => (int) $version->getKey(),
            'version' => (int) $version->version,
            'steps' => $version->steps
                ->values()
                ->map(fn (MessageChainStep $step, int $position): array =>
                    $this->step($step, $position)
                )
                ->all(),
            'message_options' => $this->messageOptions(),
        ];
    }

    /** @return array<string, mixed> */
    private function step(MessageChainStep $step, int $position): array
    {
        $channels = $step->variants
            ->pluck('channel')
            ->filter(fn (mixed $channel): bool => is_string($channel) && trim($channel) !== '')
            ->map(fn (string $channel): string => strtolower(trim($channel)))
            ->unique()
            ->sort()
            ->values()
            ->all();
        [$delayValue, $delayUnit] = $this->delayParts((int) $step->offset_seconds);
        $editableTiming = in_array($step->timing_type, [
            MessageChainStep::TIMING_IMMEDIATE,
            MessageChainStep::TIMING_DELAY,
        ], true);

        return [
            'id' => (int) $step->getKey(),
            'key' => (string) $step->key,
            'position' => $position + 1,
            'step_number' => $position + 1,
            'name' => trim((string) $step->name) !== ''
                ? (string) $step->name
                : 'Message '.($position + 1),
            'timing' => $this->timingLabel($step, $position),
            'timing_type' => $editableTiming
                ? (string) $step->timing_type
                : 'preserve',
            'timing_editable' => $editableTiming,
            'delay_value' => $delayValue,
            'delay_unit' => $delayUnit,
            'channels' => $channels,
            'message_count' => $step->variants->where('is_active', true)->count(),
        ];
    }

    /** @return array<int, array{id: int, label: string, channel: string}> */
    private function messageOptions(): array
    {
        return MessageTemplatePreset::query()
            ->active()
            ->whereHas('catalogEntries', fn ($query) => $query
                ->active()
                ->where('module_key', 'campaigns'))
            ->whereHas('canonicalTemplate.currentVersion')
            ->with('canonicalTemplate.currentVersion')
            ->orderBy('channel')
            ->orderBy('name')
            ->get()
            ->filter(fn (MessageTemplatePreset $preset): bool =>
                $preset->canonicalTemplate instanceof MessageTemplate
            )
            ->map(fn (MessageTemplatePreset $preset): array => [
                'id' => (int) $preset->getKey(),
                'label' => (string) $preset->name,
                'channel' => strtolower((string) $preset->channel),
            ])
            ->values()
            ->all();
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
            MessageChainStep::TIMING_ANCHORED => $this->anchoredLabel($step),
            MessageChainStep::TIMING_NEXT_DAY_AT => sprintf(
                '%s day(s) after %s at %s',
                (int) $step->day_offset,
                Str::headline((string) $step->anchor_key),
                mb_substr((string) $step->local_time, 0, 5),
            ),
            default => Str::headline((string) $step->timing_type),
        };
    }

    private function anchoredLabel(MessageChainStep $step): string
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

    /** @return array{0: int, 1: string} */
    private function delayParts(int $seconds): array
    {
        $seconds = max(0, $seconds);

        foreach ([86400 => 'days', 3600 => 'hours', 60 => 'minutes'] as $divisor => $unit) {
            if ($seconds >= $divisor && $seconds % $divisor === 0) {
                return [intdiv($seconds, $divisor), $unit];
            }
        }

        return [$seconds, 'seconds'];
    }

    private function durationLabel(int $seconds): string
    {
        [$value, $unit] = $this->delayParts($seconds);

        return $value.' '.Str::plural(Str::singular($unit), $value);
    }
}