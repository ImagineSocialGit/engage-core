<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Str;
use RuntimeException;

class MessageChainPresentationService
{
    /**
     * Build one read-only, business-facing projection of the current immutable
     * MessageChain version. Consumers may add their own ownership/edit links,
     * but should not duplicate chain/timing/template traversal.
     *
     * @param array<string, string> $anchorLabels
     * @return array<string, mixed>
     */
    public function present(
        MessageChain $messageChain,
        array $anchorLabels = [],
    ): array {
        $messageChain->loadMissing([
            'currentVersion.steps.variants.messageTemplateVersion.messageTemplate',
        ]);

        $version = $messageChain->currentVersion;

        if ($version === null) {
            return [
                'id' => $messageChain->getKey(),
                'key' => $messageChain->key,
                'name' => $messageChain->name,
                'description' => $messageChain->description,
                'version' => null,
                'message_count' => 0,
                'channels' => [],
            ];
        }

        $channels = [];

        foreach ($version->steps as $step) {
            if (! $step instanceof MessageChainStep || ! $step->is_active) {
                continue;
            }

            foreach ($step->variants as $variant) {
                if (! $variant instanceof MessageChainStepVariant || ! $variant->is_active) {
                    continue;
                }

                $templateVersion = $variant->messageTemplateVersion;

                if (! $templateVersion instanceof MessageTemplateVersion) {
                    throw new RuntimeException(
                        "MessageChainStepVariant [{$variant->getKey()}] has no immutable template version.",
                    );
                }

                $channel = $this->normalizeChannel((string) $variant->channel);

                if (! isset($channels[$channel])) {
                    $channels[$channel] = [
                        'key' => $channel,
                        'label' => $this->channelLabel($channel),
                        'messages' => [],
                    ];
                }

                $channels[$channel]['messages'][] = [
                    'id' => implode(':', [
                        (string) $messageChain->getKey(),
                        (string) $step->getKey(),
                        (string) $variant->getKey(),
                    ]),
                    'chain_id' => $messageChain->getKey(),
                    'chain_key' => $messageChain->key,
                    'chain_name' => $messageChain->name,
                    'chain_version' => $version->version,
                    'step_id' => $step->getKey(),
                    'step_key' => $step->key,
                    'step_name' => $step->name ?: Str::headline($step->key),
                    'timing' => $this->timingLabel($step, $anchorLabels),
                    'variant_id' => $variant->getKey(),
                    'variant_key' => $variant->key,
                    'channel' => $channel,
                    'channel_label' => $this->channelLabel($channel, plural: false),
                    'purpose' => $variant->purpose,
                    'scope' => $variant->scope,
                    'message_type' => $variant->message_type,
                    'message_type_label' => Str::headline((string) $variant->message_type),
                    'template_id' => $templateVersion->message_template_id,
                    'template_key' => $templateVersion->messageTemplate?->key,
                    'template_name' => $templateVersion->messageTemplate?->name
                        ?? Str::headline((string) $variant->message_type),
                    'template_version_id' => $templateVersion->getKey(),
                    'template_version' => $templateVersion->version,
                    'payload' => $templateVersion->payload(),
                ];
            }
        }

        $channels = collect($channels)
            ->sortBy(fn (array $item, string $key): string => sprintf(
                '%02d:%s',
                $this->channelOrder($key),
                $key,
            ))
            ->map(function (array $channel): array {
                $channel['count'] = count($channel['messages']);

                return $channel;
            })
            ->all();

        return [
            'id' => $messageChain->getKey(),
            'key' => $messageChain->key,
            'name' => $messageChain->name,
            'description' => $messageChain->description,
            'version' => $version->version,
            'message_count' => array_sum(array_map(
                static fn (array $channel): int => count($channel['messages']),
                $channels,
            )),
            'channels' => $channels,
        ];
    }

    /**
     * @param array<string, string> $anchorLabels
     */
    private function timingLabel(
        MessageChainStep $step,
        array $anchorLabels,
    ): string {
        return match ($step->timing_type) {
            MessageChainStep::TIMING_IMMEDIATE => 'Immediately',
            MessageChainStep::TIMING_DELAY => $this->durationLabel(
                abs((int) $step->offset_seconds),
            ).((int) $step->offset_seconds < 0
                ? ' before the sequence starts'
                : ' after the sequence starts'),
            MessageChainStep::TIMING_ANCHORED => $this->anchoredTimingLabel(
                step: $step,
                anchorLabels: $anchorLabels,
            ),
            MessageChainStep::TIMING_NEXT_DAY_AT => $this->nextDayTimingLabel($step),
            default => Str::headline((string) $step->timing_type),
        };
    }

    /**
     * @param array<string, string> $anchorLabels
     */
    private function anchoredTimingLabel(
        MessageChainStep $step,
        array $anchorLabels,
    ): string {
        $seconds = (int) $step->offset_seconds;
        $anchor = trim((string) $step->anchor_key);
        $anchorLabel = $anchorLabels[$anchor]
            ?? Str::of($anchor)
                ->replace(['.', '_', '-'], ' ')
                ->squish()
                ->lower()
                ->toString();

        if ($anchorLabel === '') {
            $anchorLabel = 'the scheduled time';
        }

        if ($seconds === 0) {
            return 'At '.$anchorLabel;
        }

        return $this->durationLabel(abs($seconds))
            .($seconds < 0 ? ' before ' : ' after ')
            .$anchorLabel;
    }

    private function nextDayTimingLabel(MessageChainStep $step): string
    {
        $dayOffset = (int) $step->day_offset;
        $time = $this->timeLabel($step->local_time);

        if ($dayOffset === 1) {
            return 'Next day at '.$time;
        }

        if ($dayOffset === 0) {
            return 'Same day at '.$time;
        }

        return sprintf(
            '%d %s later at %s',
            abs($dayOffset),
            Str::plural('day', abs($dayOffset)),
            $time,
        );
    }

    private function timeLabel(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('g:i A');
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 'the configured time';
        }

        $parsed = \DateTimeImmutable::createFromFormat('!H:i:s', $value)
            ?: \DateTimeImmutable::createFromFormat('!H:i', $value);

        return $parsed?->format('g:i A') ?? $value;
    }

    private function durationLabel(int $seconds): string
    {
        $minutes = max(0, (int) round($seconds / 60));

        if ($minutes % 1440 === 0 && $minutes >= 1440) {
            $days = (int) ($minutes / 1440);

            return $days.' '.Str::plural('day', $days);
        }

        if ($minutes % 60 === 0 && $minutes >= 60) {
            $hours = (int) ($minutes / 60);

            return $hours.' '.Str::plural('hour', $hours);
        }

        return $minutes.' '.Str::plural('minute', $minutes);
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

    private function channelOrder(string $channel): int
    {
        return match ($channel) {
            'email' => 10,
            'sms' => 20,
            default => 90,
        };
    }
}