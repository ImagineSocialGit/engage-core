<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Messaging\Services\MessageDefinitionResolver;
use InvalidArgumentException;

class CampaignMessageDefinitionResolver
{
    public function __construct(
        private readonly MessageDefinitionResolver $messageDefinitionResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(Campaign $campaign, CampaignStep $step): array
    {
        $reference = $this->reference($campaign, $step);
        $definition = $this->findMessagingDefinition($reference, $step);

        $schedule = $this->messageSchedule($step);
        $payload = $this->payload($definition);
        $skipReason = $this->skipReason($definition);

        $resolved = array_replace_recursive($definition, [
            'channel' => $reference['channel'],
            'purpose' => $reference['purpose'],
            'scope' => $reference['scope'],
            'dispatch_keys' => [$reference['dispatch_key']],
            'conditions' => $this->conditions($step),
            'payload' => $payload,
            'campaign_key' => $campaign->key,
            'step' => $step->step_number,
            'skip_when_join_clicked' => (bool) data_get($step->meta ?? [], 'skip_when_join_clicked', false),
            'notification_type' => data_get($step->meta ?? [], 'notification_type'),
            'meta' => array_replace_recursive(
                $definition['meta'] ?? [],
                [
                    'campaign' => [
                        'campaign_id' => $campaign->id,
                        'campaign_key' => $campaign->key,
                        'campaign_step_id' => $step->id,
                        'campaign_step' => $step->step_number,
                    ],
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
     *     channel: string,
     *     purpose: string,
     *     scope: string
     * }
     */
    public function reference(Campaign $campaign, CampaignStep $step): array
    {
        $message = $this->messageReference($step);

        return [
            'dispatch_key' => $this->normalizeSegment($this->stringOrDefault(
                value: $message['dispatch_key'] ?? null,
                default: $step->dispatch_key,
            )),
            'campaign_key' => $this->normalizeSegment($this->stringOrDefault(
                value: $message['campaign_key'] ?? null,
                default: $campaign->key,
            )),
            'step_number' => (int) ($message['step_number'] ?? $step->step_number),
            'channel' => $this->normalizeSegment($this->stringOrDefault(
                value: $message['channel'] ?? null,
                default: $campaign->channel,
            )),
            'purpose' => $this->normalizeSegment($this->stringOrDefault(
                value: $message['purpose'] ?? null,
                default: $campaign->purpose,
            )),
            'scope' => $this->normalizeSegment($this->stringOrDefault(
                value: $message['scope'] ?? null,
                default: $campaign->scope,
            )),
        ];
    }

    /**
     * @param array{
     *     dispatch_key: string,
     *     campaign_key: string,
     *     step_number: int,
     *     channel: string,
     *     purpose: string,
     *     scope: string
     * } $reference
     * @return array<string, mixed>
     */
    private function findMessagingDefinition(array $reference, CampaignStep $step): array
    {
        $definition = $this->messageDefinitionResolver->resolveCampaignStep(
            channel: $reference['channel'],
            purpose: $reference['purpose'],
            scope: $reference['scope'],
            campaignKey: $reference['campaign_key'],
            stepNumber: $reference['step_number'],
            dispatchKey: $reference['dispatch_key'],
        );

        if (is_array($definition)) {
            return $definition;
        }

        throw new InvalidArgumentException(
            'Messaging campaign step definition not found for campaign step ['.$step->id.'] using ['.
            implode(':', [
                $reference['channel'],
                $reference['purpose'],
                $reference['scope'],
                $reference['campaign_key'],
                'step_'.$reference['step_number'],
                $reference['dispatch_key'],
            ]).
            '].'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function messageReference(CampaignStep $step): array
    {
        $message = data_get($step->meta ?? [], 'message');

        if (! is_array($message)) {
            throw new InvalidArgumentException('Campaign step ['.$step->id.'] is missing meta.message.');
        }

        return $message;
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
    private function messageSchedule(CampaignStep $step): ?array
    {
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
    private function conditions(CampaignStep $step): array
    {
        $conditions = data_get($step->criteria ?? [], 'conditions', []);

        return is_array($conditions) ? $conditions : [];
    }

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $default;
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}