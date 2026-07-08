<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use InvalidArgumentException;

class CampaignMessageDefinitionResolver
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
        private readonly MessageChannelAvailability $messageChannelAvailability,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(Campaign $campaign, CampaignStep $step, ?CampaignStepVariant $variant = null): array
    {
        if (! $variant instanceof CampaignStepVariant) {
            throw new InvalidArgumentException('Campaign message definitions require a campaign step variant.');
        }

        $reference = $this->reference($campaign, $step, $variant);

        if (! $this->messageChannelAvailability->isVisibleForSurface(
            channel: $reference['channel'],
            surface: 'campaigns',
            purpose: $reference['purpose'],
            scope: $reference['scope'],
        )) {
            return $this->unavailableChannelDefinition(
                campaign: $campaign,
                step: $step,
                reference: $reference,
                variant: $variant,
            );
        }

        $definition = $this->findMessagingDefinition($reference, $step, $variant);

        $schedule = $this->messageSchedule($step, $variant);
        $payload = $this->payload($definition);
        $skipReason = $this->skipReason($definition);

        $resolved = array_replace_recursive($definition, [
            'channel' => $reference['channel'],
            'purpose' => $reference['purpose'],
            'scope' => $reference['scope'],
            'dispatch_keys' => [$reference['dispatch_key']],
            'conditions' => $this->conditions($step, $variant),
            'payload' => $payload,
            'campaign_key' => $campaign->key,
            'step' => $step->step_number,
            'variant' => $reference['variant_key'],
            'campaign_step_variant_key' => $reference['variant_key'],
            'campaign_step_variant_source_config_path' => $reference['variant_source_config_path'],
            'skip_when_join_clicked' => (bool) data_get($variant?->meta ?? $step->meta ?? [], 'skip_when_join_clicked', false),
            'notification_type' => data_get($variant?->meta ?? $step->meta ?? [], 'notification_type'),
            'meta' => array_replace_recursive(
                $definition['meta'] ?? [],
                [
                    'campaign' => array_filter([
                        'campaign_id' => $campaign->id,
                        'campaign_key' => $campaign->key,
                        'campaign_step_id' => $step->id,
                        'campaign_step' => $step->step_number,
                        'campaign_step_variant_id' => $variant?->exists ? $variant->id : null,
                        'campaign_step_variant_key' => $reference['variant_key'],
                        'campaign_step_variant_source_config_path' => $reference['variant_source_config_path'],
                        'campaign_step_variant_source_version' => $variant?->source_version,
                    ], fn (mixed $value): bool => $value !== null),
                ],
            ),
        ]);

        if ($schedule !== null) {
            $resolved['timing'] = $schedule['timing'];
            $resolved['schedule'] = $schedule['schedule'];
        }

        if ($skipReason !== null) {
            $resolved['meta'] = array_replace_recursive($resolved['meta'] ?? [], [
                'campaign_skip_reason' => $skipReason,
            ]);
        }

        return $resolved;
    }

    /**
     * @return array{
     *     dispatch_key: string,
     *     campaign_key: string,
     *     step_number: int,
     *     variant_key: string|null,
     *     variant_source_config_path: string|null,
     *     channel: string,
     *     purpose: string,
     *     scope: string
     * }
     */
    public function reference(Campaign $campaign, CampaignStep $step, ?CampaignStepVariant $variant = null): array
    {
        if (! $variant instanceof CampaignStepVariant) {
            throw new InvalidArgumentException('Campaign message references require a campaign step variant.');
        }

        return [
            'dispatch_key' => $this->normalizeSegment($variant?->dispatch_key ?? $step->dispatch_key),
            'campaign_key' => $this->normalizeSegment($campaign->key),
            'step_number' => (int) $step->step_number,
            'variant_key' => $variant instanceof CampaignStepVariant ? $this->normalizeSegment($variant->key) : null,
            'variant_source_config_path' => $this->nullableString($variant?->source_config_path),
            'channel' => $this->normalizeSegment($variant?->channel ?? $step->channel),
            'purpose' => $this->normalizeSegment($variant?->purpose ?? $step->purpose),
            'scope' => $this->normalizeSegment($variant?->scope ?? $step->scope),
        ];
    }

    /**
     * @param array{
     *     dispatch_key: string,
     *     campaign_key: string,
     *     step_number: int,
     *     variant_key: string|null,
     *     variant_source_config_path: string|null,
     *     channel: string,
     *     purpose: string,
     *     scope: string
     * } $reference
     * @return array<string, mixed>
     */
    private function findMessagingDefinition(array $reference, CampaignStep $step, ?CampaignStepVariant $variant): array
    {
        $definition = $this->messageDefinitionResolver->resolveCampaignStep(
            channel: $reference['channel'],
            purpose: $reference['purpose'],
            scope: $reference['scope'],
            campaignKey: $reference['campaign_key'],
            stepNumber: $reference['step_number'],
            dispatchKey: $reference['dispatch_key'],
            variantKey: $reference['variant_key'],
            variantSourceConfigPath: $reference['variant_source_config_path'],
            context: $variant instanceof CampaignStepVariant && $variant->exists ? $variant : null,
        );

        if (is_array($definition)) {
            return $definition;
        }

        throw new InvalidArgumentException(
            'Messaging campaign step definition not found for campaign step ['.$step->id.'] using ['.
            implode(':', array_filter([
                $reference['channel'],
                $reference['purpose'],
                $reference['scope'],
                $reference['campaign_key'],
                'step_'.$reference['step_number'],
                $reference['variant_key'] ? 'variant_'.$reference['variant_key'] : null,
                $reference['variant_source_config_path'],
                $reference['dispatch_key'],
            ])).
            '].'
        );
    }

    /**
     * @param array{
     *     dispatch_key: string,
     *     campaign_key: string,
     *     step_number: int,
     *     variant_key: string|null,
     *     variant_source_config_path: string|null,
     *     channel: string,
     *     purpose: string,
     *     scope: string
     * } $reference
     * @return array<string, mixed>
     */
    private function unavailableChannelDefinition(
        Campaign $campaign,
        CampaignStep $step,
        array $reference,
        ?CampaignStepVariant $variant,
    ): array {
        return [
            'channel' => $reference['channel'],
            'purpose' => $reference['purpose'],
            'scope' => $reference['scope'],
            'dispatch_keys' => [$reference['dispatch_key']],
            'conditions' => $this->conditions($step, $variant),
            'payload' => [],
            'campaign_key' => $campaign->key,
            'step' => $step->step_number,
            'variant' => $reference['variant_key'],
            'campaign_step_variant_key' => $reference['variant_key'],
            'campaign_step_variant_source_config_path' => $reference['variant_source_config_path'],
            'skip_when_join_clicked' => (bool) data_get($variant?->meta ?? $step->meta ?? [], 'skip_when_join_clicked', false),
            'notification_type' => data_get($variant?->meta ?? $step->meta ?? [], 'notification_type'),
            'meta' => [
                'campaign' => array_filter([
                    'campaign_id' => $campaign->id,
                    'campaign_key' => $campaign->key,
                    'campaign_step_id' => $step->id,
                    'campaign_step' => $step->step_number,
                    'campaign_step_variant_id' => $variant?->exists ? $variant->id : null,
                    'campaign_step_variant_key' => $reference['variant_key'],
                    'campaign_step_variant_source_config_path' => $reference['variant_source_config_path'],
                    'campaign_step_variant_source_version' => $variant?->source_version,
                ], fn (mixed $value): bool => $value !== null),
                'campaign_skip_reason' => 'campaign_channel_unavailable',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private function payload(array $definition): array
    {
        $payload = $definition['payload'] ?? null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function skipReason(array $definition): ?string
    {
        $payload = $definition['payload'] ?? null;

        if (! is_array($payload) || $payload === []) {
            return 'messaging_definition_missing_payload';
        }

        return null;
    }

    /**
     * @return array{timing: string, schedule: array<string, mixed>|null}|null
     */
    private function messageSchedule(CampaignStep $step, ?CampaignStepVariant $variant): ?array
    {
        $criteria = $variant instanceof CampaignStepVariant && is_array($variant->criteria)
            ? $variant->criteria
            : [];

        $timing = data_get($criteria, 'timing');

        if (is_array($timing)) {
            return $this->normalizeTiming($timing);
        }

        $schedule = data_get($criteria, 'schedule');

        if (is_array($schedule)) {
            return $this->normalizeTiming($schedule);
        }

        $timing = data_get($step->criteria ?? [], 'timing');

        if (is_array($timing)) {
            return $this->normalizeTiming($timing);
        }

        $schedule = data_get($step->criteria ?? [], 'schedule');

        if (is_array($schedule)) {
            return $this->normalizeTiming($schedule);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $timing
     * @return array{timing: string, schedule: array<string, mixed>|null}
     */
    private function normalizeTiming(array $timing): array
    {
        $type = $this->normalizeSegment((string) ($timing['type'] ?? 'immediate'));

        if ($type === 'immediate') {
            return [
                'timing' => 'immediate',
                'schedule' => null,
            ];
        }

        if (! in_array($type, ['delay', 'anchored'], true)) {
            throw new InvalidArgumentException('Campaign step timing.type must be immediate, delay, or anchored.');
        }

        return [
            'timing' => 'scheduled',
            'schedule' => [
                'type' => $type,
                'minutes' => $this->timingMinutes($timing),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $timing
     */
    private function timingMinutes(array $timing): int
    {
        if (array_key_exists('minutes', $timing)) {
            return (int) $timing['minutes'];
        }

        if (array_key_exists('hours', $timing)) {
            return (int) $timing['hours'] * 60;
        }

        if (array_key_exists('days', $timing)) {
            return (int) $timing['days'] * 1440;
        }

        throw new InvalidArgumentException('Campaign step scheduled timing must include minutes, hours, or days.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function conditions(CampaignStep $step, ?CampaignStepVariant $variant): array
    {
        $stepConditions = data_get($step->criteria ?? [], 'conditions', []);
        $variantConditions = data_get($variant?->criteria ?? [], 'conditions', []);

        return array_values(array_merge(
            is_array($stepConditions) ? $stepConditions : [],
            is_array($variantConditions) ? $variantConditions : [],
        ));
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
