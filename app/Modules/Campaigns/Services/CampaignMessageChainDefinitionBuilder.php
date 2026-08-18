<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use InvalidArgumentException;
use RuntimeException;

class CampaignMessageChainDefinitionBuilder
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function build(Campaign $campaign): array
    {
        $campaign->loadMissing('steps.variants');

        return $campaign->steps
            ->sortBy('step_number')
            ->values()
            ->map(fn (CampaignStep $step): array => $this->stepDefinition(
                campaign: $campaign,
                step: $step,
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stepDefinition(
        Campaign $campaign,
        CampaignStep $step,
    ): array {
        $variants = $step->variants
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values()
            ->map(fn (CampaignStepVariant $variant): array => $this->variantDefinition(
                campaign: $campaign,
                step: $step,
                variant: $variant,
            ))
            ->all();

        if ($variants === []) {
            throw new RuntimeException(
                "Campaign step [{$campaign->key}:{$step->step_number}] has no MessageChain variants.",
            );
        }

        return [
            'key' => 'step_'.(int) $step->step_number,
            'name' => $step->name,
            'sort_order' => (int) $step->step_number * 10,
            ...$this->timingDefinition($step),
            'variant_strategy' => $this->variantStrategy($step),
            'advance_policy' => MessageChainStep::ADVANCE_ALL_TERMINAL,
            'conditions' => $this->conditions($step->criteria),
            'is_active' => (bool) $step->is_active,
            'variants' => $variants,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variantDefinition(
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
    ): array {
        $definition = $this->messageDefinitionResolver->resolveCampaignStep(
            channel: $variant->channel,
            purpose: $variant->purpose,
            scope: $variant->scope,
            campaignKey: $campaign->key,
            stepNumber: (int) $step->step_number,
            dispatchKey: $variant->dispatch_key,
            variantKey: $variant->key,
            context: $variant,
        );

        if (! is_array($definition)) {
            throw new RuntimeException(sprintf(
                'Campaign variant [%s:step_%d:%s] could not resolve its Messaging definition.',
                $campaign->key,
                (int) $step->step_number,
                $variant->key,
            ));
        }
        $templateVersionId = $definition['message_template_version_id'] ?? null;

        if (! is_numeric($templateVersionId) || (int) $templateVersionId < 1) {
            throw new RuntimeException(sprintf(
                'Campaign variant [%s:step_%d:%s] has no canonical MessageTemplateVersion. Run Messaging template preset sync before Campaign preset sync.',
                $campaign->key,
                (int) $step->step_number,
                $variant->key,
            ));
        }

        $messageType = $definition['message_type'] ?? null;

        if (! is_string($messageType) || trim($messageType) === '') {
            throw new RuntimeException(sprintf(
                'Campaign variant [%s:step_%d:%s] has no Messaging message type.',
                $campaign->key,
                (int) $step->step_number,
                $variant->key,
            ));
        }

        return [
            'key' => $this->normalizeSegment($variant->key),
            'sort_order' => (int) $variant->sort_order,
            'message_template_version_id' => (int) $templateVersionId,
            'channel' => $this->normalizeSegment($variant->channel),
            'purpose' => $this->normalizeSegment($variant->purpose),
            'scope' => $this->normalizeSegment($variant->scope),
            'message_type' => $this->normalizeSegment($messageType),
            'queue' => $this->nullableSegment($definition['queue'] ?? null),
            'dependency_policy' => is_array($variant->dependency_rules)
                && $variant->dependency_rules !== []
                    ? $variant->dependency_rules
                    : null,
            'conditions' => $this->conditions($variant->criteria),
            'is_active' => (bool) $variant->is_active,
        ];
    }

    /**
     * @return array{
     *     timing_type: string,
     *     anchor_key: string|null,
     *     offset_seconds: int,
     *     day_offset: int,
     *     local_time: string|null
     * }
     */
    private function timingDefinition(CampaignStep $step): array
    {
        $criteria = is_array($step->criteria) ? $step->criteria : [];
        $timing = data_get($criteria, 'timing');

        if (! is_array($timing)) {
            $timing = data_get($criteria, 'schedule');
        }

        if (! is_array($timing) || $timing === []) {
            return [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ];
        }

        $type = $this->normalizeSegment((string) ($timing['type'] ?? 'immediate'));

        if ($type === MessageChainStep::TIMING_IMMEDIATE) {
            return [
                'timing_type' => MessageChainStep::TIMING_IMMEDIATE,
                'anchor_key' => null,
                'offset_seconds' => 0,
                'day_offset' => 0,
                'local_time' => null,
            ];
        }

        $offsetSeconds = $this->timingSeconds($timing);

        if ($type === MessageChainStep::TIMING_DELAY) {
            return [
                'timing_type' => MessageChainStep::TIMING_DELAY,
                'anchor_key' => null,
                'offset_seconds' => $offsetSeconds,
                'day_offset' => 0,
                'local_time' => null,
            ];
        }

        if ($type === MessageChainStep::TIMING_ANCHORED) {
            $anchorKey = $this->nullableSegment($timing['anchor_key'] ?? null);

            if ($anchorKey === null) {
                throw new InvalidArgumentException(sprintf(
                    'Campaign step [%d] anchored timing requires criteria.timing.anchor_key before it can be published as a MessageChain.',
                    (int) $step->step_number,
                ));
            }

            return [
                'timing_type' => MessageChainStep::TIMING_ANCHORED,
                'anchor_key' => $anchorKey,
                'offset_seconds' => $offsetSeconds,
                'day_offset' => 0,
                'local_time' => null,
            ];
        }

        throw new InvalidArgumentException(sprintf(
            'Campaign step [%d] timing type [%s] cannot be published as a MessageChain.',
            (int) $step->step_number,
            $type,
        ));
    }

    /**
     * @param array<string, mixed> $timing
     */
    private function timingSeconds(array $timing): int
    {
        foreach ([
            'seconds' => 1,
            'minutes' => 60,
            'hours' => 3600,
            'days' => 86400,
        ] as $field => $multiplier) {
            if (array_key_exists($field, $timing)) {
                return (int) $timing[$field] * $multiplier;
            }
        }

        throw new InvalidArgumentException(
            'Campaign scheduled timing must include seconds, minutes, hours, or days.',
        );
    }

    private function variantStrategy(CampaignStep $step): string
    {
        $strategy = $this->normalizeSegment((string) $step->variant_strategy);

        if (! in_array($strategy, [
            MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE,
            MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE,
            MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE,
        ], true)) {
            throw new InvalidArgumentException(sprintf(
                'Campaign step [%d] has unsupported MessageChain variant strategy [%s].',
                (int) $step->step_number,
                $strategy,
            ));
        }

        return $strategy;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function conditions(mixed $criteria): ?array
    {
        if (! is_array($criteria)) {
            return null;
        }

        $conditions = data_get($criteria, 'conditions', []);

        return is_array($conditions) && $conditions !== []
            ? $conditions
            : null;
    }

    private function nullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}