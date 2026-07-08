<?php

namespace App\Modules\Campaigns\Actions;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Models\CampaignStep;
use App\Modules\Campaigns\Models\CampaignStepVariant;
use App\Modules\Campaigns\Services\CampaignMessageDefinitionResolver;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\DispatchMessageAction;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;

class ScheduleCampaignStepMessagesAction
{
    public function __construct(
        private readonly DispatchMessageAction $dispatchMessageAction,
        private readonly CampaignMessageDefinitionResolver $campaignMessageDefinitionResolver,
    ) {}

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
    public function handle(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        CampaignStep $step,
        Contact $contact,
        ?Model $context = null,
        array $payload = [],
        ?array $meta = null,
    ): ?ScheduledMessage {
        $step->loadMissing('variants');

        $strategy = $this->variantStrategy($step);
        $scheduledMessages = [];
        $attempts = [];

        $variants = $this->variantsForStep($step);

        if ($variants === []) {
            $attempts[] = $this->stepAttemptBase($campaign, $step) + [
                'result' => 'not_scheduled',
                'reason' => 'campaign_step_has_no_active_variants',
            ];

            $this->recordMessageScheduleAttempts(
                enrollment: $enrollment,
                step: $step,
                attempts: $attempts,
            );

            return null;
        }

        foreach ($variants as $variant) {
            if ($strategy === 'dependency_aware' && ! $this->dependenciesSatisfied($variant, $scheduledMessages)) {
                $attempts[] = $this->attemptBase($campaign, $step, $variant) + [
                    'result' => 'not_scheduled',
                    'reason' => 'campaign_variant_dependency_unsatisfied',
                ];

                continue;
            }

            $scheduledMessage = $this->scheduleVariant(
                enrollment: $enrollment,
                campaign: $campaign,
                step: $step,
                variant: $variant,
                contact: $contact,
                context: $context,
                payload: $payload,
                meta: $meta,
                strategy: $strategy,
                attempts: $attempts,
            );

            if ($scheduledMessage instanceof ScheduledMessage) {
                $scheduledMessages[$this->variantKey($variant)] = $scheduledMessage;

                if ($strategy === 'first_available') {
                    $this->recordMessageScheduleAttempts(
                        enrollment: $enrollment,
                        step: $step,
                        attempts: $attempts,
                    );

                    return $scheduledMessage;
                }
            }
        }

        $this->recordMessageScheduleAttempts(
            enrollment: $enrollment,
            step: $step,
            attempts: $attempts,
        );

        return array_values($scheduledMessages)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     * @param array<int, array<string, mixed>> $attempts
     */
    private function scheduleVariant(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
        Contact $contact,
        ?Model $context,
        array $payload,
        ?array $meta,
        string $strategy,
        array &$attempts,
    ): ?ScheduledMessage {
        $definition = $this->campaignMessageDefinitionResolver->resolve(
            campaign: $campaign,
            step: $step,
            variant: $variant,
        );

        $reference = $this->campaignMessageDefinitionResolver->reference(
            campaign: $campaign,
            step: $step,
            variant: $variant,
        );

        $attempt = $this->attemptBase($campaign, $step, $variant) + [
            'reference' => $reference,
            'definition_config_path' => $definition['config_path'] ?? null,
            'definition' => [
                'dispatch_keys' => $definition['dispatch_keys'] ?? [],
                'message_type' => $definition['message_type'] ?? null,
                'channel' => $definition['channel'] ?? null,
                'purpose' => $definition['purpose'] ?? null,
                'scope' => $definition['scope'] ?? null,
                'timing' => $definition['timing'] ?? null,
                'schedule' => $definition['schedule'] ?? null,
                'conditions' => $definition['conditions'] ?? [],
                'campaign_step_variant_key' => $definition['campaign_step_variant_key'] ?? null,
                'campaign_step_variant_source_config_path' => $definition['campaign_step_variant_source_config_path'] ?? null,
            ],
        ];

        $skipReason = data_get($definition, 'meta.campaign_skip_reason');

        if (is_string($skipReason) && trim($skipReason) !== '') {
            $attempts[] = $attempt + [
                'result' => 'not_scheduled',
                'reason' => $skipReason,
            ];

            return null;
        }

        $scheduledMessages = $this->dispatchMessageAction->handle(
            recipient: $contact,
            channel: $definition['channel'],
            purpose: $definition['purpose'],
            scope: $definition['scope'],
            dispatchKeys: $definition['dispatch_keys'],
            payload: $this->campaignPayload($enrollment, $payload),
            context: $context,
            meta: array_replace_recursive([
                'campaign_enrollment_id' => $enrollment->id,
                'campaign_id' => $campaign->id,
                'campaign_key' => $campaign->key,
                'campaign_step_id' => $step->id,
                'campaign_step' => $step->step_number,
                'campaign_step_variant_id' => $variant->exists ? $variant->id : null,
                'campaign_step_variant_key' => $variant->key,
                'campaign_step_variant_source_config_path' => $variant->source_config_path,
                'campaign_step_variant_source_version' => $variant->source_version,
                'campaign_variant_strategy' => $strategy,
                'campaign_step_waits_for_all_scheduled_variants' => in_array($strategy, ['send_all_eligible', 'dependency_aware'], true),
            ], $meta ?? []),
            criteria: [
                'campaign_key' => $campaign->key,
                'step' => $step->step_number,
                'variant' => $variant->key,
                'source_config_path' => $variant->source_config_path,
            ],
            definitions: [$definition],
        );

        $scheduledMessage = $scheduledMessages[0] ?? null;

        if (! $scheduledMessage instanceof ScheduledMessage) {
            $attempts[] = $attempt + [
                'result' => 'not_scheduled',
            ];

            return null;
        }

        $attempts[] = $attempt + [
            'result' => 'scheduled',
            'scheduled_message_id' => $scheduledMessage->id,
        ];

        return $scheduledMessage;
    }

    /**
     * @return array<int, CampaignStepVariant>
     */
    private function variantsForStep(CampaignStep $step): array
    {
        $variants = $step->variants
            ->filter(fn (CampaignStepVariant $variant): bool => $variant->is_active)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values();

        return $variants->all();
    }

    /**
     * @param array<string, ScheduledMessage> $scheduledMessages
     */
    private function dependenciesSatisfied(CampaignStepVariant $variant, array $scheduledMessages): bool
    {
        $requiredScheduledKeys = $this->stringList(data_get($variant->dependency_rules ?? [], 'requires_scheduled_variant_keys', []));

        foreach ($requiredScheduledKeys as $requiredKey) {
            if (! array_key_exists($requiredKey, $scheduledMessages)) {
                return false;
            }
        }

        return true;
    }


    /**
     * @return array<string, mixed>
     */
    private function stepAttemptBase(Campaign $campaign, CampaignStep $step): array
    {
        return [
            'attempted_at' => now()->toISOString(),
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'campaign_step_id' => $step->id,
            'step' => $step->step_number,
            'campaign_step_variant_id' => null,
            'variant_key' => null,
            'variant_source_config_path' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function attemptBase(Campaign $campaign, CampaignStep $step, CampaignStepVariant $variant): array
    {
        return [
            'attempted_at' => now()->toISOString(),
            'campaign_id' => $campaign->id,
            'campaign_key' => $campaign->key,
            'campaign_step_id' => $step->id,
            'step' => $step->step_number,
            'campaign_step_variant_id' => $variant->exists ? $variant->id : null,
            'variant_key' => $variant->key,
            'variant_source_config_path' => $variant->source_config_path,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $attempts
     */
    private function recordMessageScheduleAttempts(
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        array $attempts,
    ): void {
        $meta = $enrollment->meta ?? [];
        $lastAttempt = $attempts !== [] ? $attempts[array_key_last($attempts)] : null;

        $meta['last_message_schedule_attempt'] = $lastAttempt;
        $meta['last_campaign_step_variant_attempts'] = array_values($attempts);

        $enrollment->forceFill([
            'meta' => $meta,
        ])->save();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function campaignPayload(CampaignEnrollment $enrollment, array $payload): array
    {
        $startPayload = data_get($enrollment->start_context ?? [], 'payload', []);

        return array_replace_recursive(
            is_array($startPayload) ? $startPayload : [],
            $payload,
        );
    }

    private function variantStrategy(CampaignStep $step): string
    {
        $strategy = $this->normalizeSegment((string) ($step->variant_strategy ?: 'first_available'));

        return in_array($strategy, ['first_available', 'send_all_eligible', 'dependency_aware'], true)
            ? $strategy
            : 'first_available';
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (is_string($values)) {
            $values = [$values];
        }

        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $value): ?string => is_string($value) && trim($value) !== ''
                ? $this->normalizeSegment($value)
                : null,
            $values,
        ))));
    }

    private function variantKey(CampaignStepVariant $variant): string
    {
        return $this->normalizeSegment($variant->key);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
