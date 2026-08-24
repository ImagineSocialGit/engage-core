<?php

namespace App\Modules\Campaigns\Actions;

use App\Models\User;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Messaging\Actions\PublishMessageChainVersionAction;
use App\Modules\Messaging\Models\MessageChain;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PublishCampaignMessageChainVersionAction
{
    public function __construct(
        private readonly PublishMessageChainVersionAction $publishMessageChainVersion,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $submittedSteps
     * @param array<string, mixed>|null $newStep
     */
    public function replaceSchedule(
        Campaign $campaign,
        int $expectedVersionId,
        array $submittedSteps,
        ?array $newStep = null,
        ?User $createdBy = null,
    ): MessageChainVersion {
        return DB::transaction(function () use (
            $campaign,
            $expectedVersionId,
            $submittedSteps,
            $newStep,
            $createdBy,
        ): MessageChainVersion {
            [$lockedCampaign, $chain, $version] = $this->lockedContext(
                campaign: $campaign,
                expectedVersionId: $expectedVersionId,
            );
            $definition = $version->definition();
            $currentSteps = $definition['steps'];
            $submittedByKey = collect($submittedSteps)->keyBy(
                fn (array $step): string => (string) $step['key'],
            );
            $currentKeys = collect($currentSteps)->pluck('key')->sort()->values()->all();
            $submittedKeys = $submittedByKey->keys()->sort()->values()->all();

            if ($currentKeys !== $submittedKeys) {
                throw ValidationException::withMessages([
                    'steps' => 'The Campaign schedule changed while you were editing it. Reload and try again.',
                ]);
            }

            $steps = [];

            foreach ($currentSteps as $currentStep) {
                $submitted = $submittedByKey->get((string) $currentStep['key']);

                if (! is_array($submitted)) {
                    throw ValidationException::withMessages([
                        'steps' => 'Every current schedule step must be submitted.',
                    ]);
                }

                if (filter_var($submitted['remove'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $steps[] = $this->updatedStep($currentStep, $submitted);
            }

            if ($newStep !== null) {
                $steps[] = $this->newStepDefinition(
                    currentSteps: $currentSteps,
                    input: $newStep,
                );
            }

            if ($steps === []) {
                throw ValidationException::withMessages([
                    'steps' => 'A Campaign schedule must keep at least one message step.',
                ]);
            }

            usort(
                $steps,
                static fn (array $left, array $right): int =>
                    [$left['_position'], $left['key']]
                    <=> [$right['_position'], $right['key']],
            );

            foreach ($steps as $index => &$step) {
                $step['sort_order'] = ($index + 1) * 10;
                unset($step['_position']);
            }
            unset($step);

            return $this->publish(
                campaign: $lockedCampaign,
                chain: $chain,
                previousVersion: $version,
                steps: $steps,
                createdBy: $createdBy,
            );
        }, 3);
    }

    public function replaceVariantTemplate(
        Campaign $campaign,
        int $expectedVersionId,
        int $messageChainStepVariantId,
        MessageTemplateVersion $replacement,
        ?User $createdBy = null,
    ): MessageChainVersion {
        return DB::transaction(function () use (
            $campaign,
            $expectedVersionId,
            $messageChainStepVariantId,
            $replacement,
            $createdBy,
        ): MessageChainVersion {
            [$lockedCampaign, $chain, $version] = $this->lockedContext(
                campaign: $campaign,
                expectedVersionId: $expectedVersionId,
            );
            $target = MessageChainStepVariant::query()
                ->whereKey($messageChainStepVariantId)
                ->whereHas(
                    'messageChainStep',
                    fn ($query) => $query->where(
                        'message_chain_version_id',
                        $version->getKey(),
                    ),
                )
                ->first();

            if (! $target instanceof MessageChainStepVariant) {
                throw ValidationException::withMessages([
                    '_editing_message_id' => 'That message is no longer part of the current Campaign schedule.',
                ]);
            }

            $target->loadMissing('messageChainStep');

            return $this->replaceVariantByKey(
                campaign: $lockedCampaign,
                chain: $chain,
                version: $version,
                stepKey: (string) $target->messageChainStep?->key,
                variantKey: (string) $target->key,
                replacement: $replacement,
                createdBy: $createdBy,
            );
        }, 3);
    }

    public function replaceVariantTemplateByKey(
        Campaign $campaign,
        int $expectedVersionId,
        string $stepKey,
        string $variantKey,
        MessageTemplateVersion $replacement,
        ?User $createdBy = null,
    ): MessageChainVersion {
        return DB::transaction(function () use (
            $campaign,
            $expectedVersionId,
            $stepKey,
            $variantKey,
            $replacement,
            $createdBy,
        ): MessageChainVersion {
            [$lockedCampaign, $chain, $version] = $this->lockedContext(
                campaign: $campaign,
                expectedVersionId: $expectedVersionId,
            );

            return $this->replaceVariantByKey(
                campaign: $lockedCampaign,
                chain: $chain,
                version: $version,
                stepKey: $stepKey,
                variantKey: $variantKey,
                replacement: $replacement,
                createdBy: $createdBy,
            );
        }, 3);
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    private function updatedStep(array $current, array $submitted): array
    {
        $current['name'] = $this->nullableString($submitted['name'] ?? null);
        $current['_position'] = (int) $submitted['position'];
        $timingType = (string) $submitted['timing_type'];

        if ($timingType === 'preserve') {
            if (in_array($current['timing_type'] ?? null, [
                MessageChainStep::TIMING_IMMEDIATE,
                MessageChainStep::TIMING_DELAY,
            ], true)) {
                throw ValidationException::withMessages([
                    'steps' => 'Immediate and delayed Campaign steps must submit editable timing.',
                ]);
            }

            return $current;
        }

        if ($timingType === MessageChainStep::TIMING_IMMEDIATE) {
            return array_replace($current, [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ]);
        }

        return array_replace($current, [
            'timing_type' => MessageChainStep::TIMING_DELAY,
            'anchor_key' => null,
            'offset_seconds' => $this->delaySeconds($submitted),
            'day_offset' => 0,
            'local_time' => null,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $currentSteps
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function newStepDefinition(array $currentSteps, array $input): array
    {
        $preset = MessageTemplatePreset::query()
            ->active()
            ->whereHas('catalogEntries', fn ($query) => $query
                ->active()
                ->where('module_key', 'campaigns'))
            ->with('canonicalTemplate.currentVersion')
            ->find($input['message_template_preset_id'] ?? null);
        $template = $preset?->canonicalTemplate;
        $templateVersion = $template?->currentVersion;

        if (! $preset instanceof MessageTemplatePreset
            || ! $template instanceof MessageTemplate
            || ! $templateVersion instanceof MessageTemplateVersion
        ) {
            throw ValidationException::withMessages([
                'new_step.message_template_preset_id' => 'Choose an active published message.',
            ]);
        }

        $timingType = (string) ($input['timing_type'] ?? MessageChainStep::TIMING_DELAY);
        $timing = $timingType === MessageChainStep::TIMING_IMMEDIATE
            ? [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ]
            : [
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'anchor_key' => null,
                'offset_seconds' => $this->delaySeconds($input),
                'day_offset' => 0,
                'local_time' => null,
            ];

        return [
            'key' => $this->nextStepKey($currentSteps),
            'name' => $this->nullableString($input['name'] ?? null) ?? $preset->name,
            'sort_order' => 0,
            '_position' => (int) ($input['position'] ?? (count($currentSteps) + 1)),
            ...$timing,
            'variant_strategy' => MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'conditions' => null,
            'is_active' => true,
            'variants' => [[
                'key' => $this->normalizeSegment((string) $preset->channel),
                'sort_order' => 10,
                'message_template_version_id' => (int) $templateVersion->getKey(),
                'channel' => $this->normalizeSegment((string) $preset->channel),
                'purpose' => $this->normalizeSegment((string) $preset->purpose),
                'scope' => $this->normalizeSegment((string) $preset->scope),
                'message_type' => $this->normalizeSegment((string) $preset->message_type),
                'queue' => $this->nullableString($preset->queue),
                'dependency_policy' => null,
                'conditions' => null,
                'is_active' => true,
            ]],
        ];
    }

    /**
     * @return array{0: Campaign, 1: MessageChain, 2: MessageChainVersion}
     */
    private function lockedContext(
        Campaign $campaign,
        int $expectedVersionId,
    ): array {
        $lockedCampaign = Campaign::query()
            ->whereKey($campaign->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if (! $lockedCampaign->message_chain_id) {
            throw ValidationException::withMessages([
                'message_chain_version_id' => 'This Campaign has no published message schedule.',
            ]);
        }

        $chain = MessageChain::query()
            ->whereKey($lockedCampaign->message_chain_id)
            ->lockForUpdate()
            ->first();

        if (! $chain instanceof MessageChain || ! $chain->current_version_id) {
            throw ValidationException::withMessages([
                'message_chain_version_id' => 'This Campaign has no current message schedule.',
            ]);
        }

        if ((int) $chain->current_version_id !== $expectedVersionId) {
            throw ValidationException::withMessages([
                'message_chain_version_id' => 'The Campaign schedule changed while you were editing it. Reload and try again.',
            ]);
        }

        $version = MessageChainVersion::query()
            ->with('steps.variants')
            ->whereKey($expectedVersionId)
            ->where('message_chain_id', $chain->getKey())
            ->first();

        if (! $version instanceof MessageChainVersion || ! $version->isPublished()) {
            throw ValidationException::withMessages([
                'message_chain_version_id' => 'The selected Campaign schedule is not published.',
            ]);
        }

        return [$lockedCampaign, $chain, $version];
    }

    private function replaceVariantByKey(
        Campaign $campaign,
        MessageChain $chain,
        MessageChainVersion $version,
        string $stepKey,
        string $variantKey,
        MessageTemplateVersion $replacement,
        ?User $createdBy,
    ): MessageChainVersion {
        $definition = $version->definition();
        $found = false;

        foreach ($definition['steps'] as &$step) {
            if ((string) $step['key'] !== $stepKey) {
                continue;
            }

            foreach ($step['variants'] as &$variant) {
                if ((string) $variant['key'] !== $variantKey) {
                    continue;
                }

                $variant['message_template_version_id'] = (int) $replacement->getKey();
                $found = true;
                break;
            }
            unset($variant);
            break;
        }
        unset($step);

        if (! $found) {
            throw ValidationException::withMessages([
                '_editing_message_id' => 'That message is no longer part of the current Campaign schedule.',
            ]);
        }

        return $this->publish(
            campaign: $campaign,
            chain: $chain,
            previousVersion: $version,
            steps: $definition['steps'],
            createdBy: $createdBy,
        );
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function publish(
        Campaign $campaign,
        MessageChain $chain,
        MessageChainVersion $previousVersion,
        array $steps,
        ?User $createdBy,
    ): MessageChainVersion {
        $published = $this->publishMessageChainVersion->handle(
            messageChain: $chain,
            steps: $steps,
            exitConditions: is_array($previousVersion->exit_conditions)
                ? $previousVersion->exit_conditions
                : [],
            createdBy: $createdBy,
        );

        if ((int) $published->getKey() === (int) $previousVersion->getKey()) {
            return $published;
        }

        $customizedAt = now();

        $chain->forceFill([
            'is_customized' => true,
            'customized_at' => $customizedAt,
        ])->save();

        $campaign->forceFill([
            'is_customized' => true,
            'customized_at' => $customizedAt,
        ])->save();

        return $published;
    }

    /** @param array<string, mixed> $input */
    private function delaySeconds(array $input): int
    {
        $value = max(0, (int) ($input['delay_value'] ?? 0));
        $seconds = $value * match ($input['delay_unit'] ?? null) {
            'seconds' => 1,
            'minutes' => 60,
            'hours' => 3600,
            'days' => 86400,
            default => throw ValidationException::withMessages([
                'steps' => 'Delayed steps require seconds, minutes, hours, or days.',
            ]),
        };

        if ($seconds > 315360000) {
            throw ValidationException::withMessages([
                'steps' => 'A Campaign wait cannot exceed ten years.',
            ]);
        }

        return $seconds;
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function nextStepKey(array $steps): string
    {
        $keys = array_values(array_filter(array_column($steps, 'key'), 'is_string'));
        $number = 1;

        while (in_array('step_'.$number, $keys, true)) {
            $number++;
        }

        return 'step_'.$number;
    }

    private function normalizeSegment(string $value): string
    {
        $value = str_replace('-', '_', strtolower(trim($value)));

        if ($value === '') {
            throw new RuntimeException('Campaign message definition segments cannot be empty.');
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}