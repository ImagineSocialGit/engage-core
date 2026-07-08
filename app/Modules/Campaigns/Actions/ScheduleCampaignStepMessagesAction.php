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
            if ($strategy === 'dependency_aware') {
                $dependencyEvaluation = $this->evaluateDependencies(
                    enrollment: $enrollment,
                    step: $step,
                    variant: $variant,
                    scheduledMessages: $scheduledMessages,
                );

                if (! $dependencyEvaluation['satisfied']) {
                    $attempts[] = $this->attemptBase($campaign, $step, $variant) + [
                        'result' => 'not_scheduled',
                        'reason' => 'campaign_variant_dependency_unsatisfied',
                        'dependency_requirements' => $dependencyEvaluation['requirements'],
                        'dependency_unsatisfied' => $dependencyEvaluation['unsatisfied'],
                    ];

                    continue;
                }
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
            meta: $this->messageMeta(
                enrollment: $enrollment,
                campaign: $campaign,
                step: $step,
                variant: $variant,
                strategy: $strategy,
                meta: $meta,
            ),
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
     * @param array<string, mixed>|null $meta
     * @return array<string, mixed>
     */
    private function messageMeta(
        CampaignEnrollment $enrollment,
        Campaign $campaign,
        CampaignStep $step,
        CampaignStepVariant $variant,
        string $strategy,
        ?array $meta,
    ): array {
        return array_replace_recursive(
            $this->flowRouteMetaFromEnrollment($enrollment),
            [
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
            ],
            $meta ?? [],
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function flowRouteMetaFromEnrollment(CampaignEnrollment $enrollment): array
    {
        $flowRoute = array_filter([
            'flow_route_progress_id' => $enrollment->flow_route_progress_id,
            'flow_route_plan_id' => $enrollment->flow_route_plan_id,
            'flow_route_plan_item_id' => $enrollment->flow_route_plan_item_id,
            'flow_route_progress_item_id' => $enrollment->flow_route_progress_item_id,
            'flow_route_id' => $enrollment->flow_route_id,
            'flow_route_point_id' => $enrollment->flow_route_point_id,
            'flow_route_capability_id' => $enrollment->flow_route_capability_id,
        ], fn (mixed $value): bool => $value !== null);

        return $flowRoute === [] ? [] : ['flow_route' => $flowRoute];
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
     * @return array{satisfied: bool, requirements: array<string, array<int, string>>, unsatisfied: array<int, array{variant_key: string, states: array<int, string>}>}
     */
    private function evaluateDependencies(
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        CampaignStepVariant $variant,
        array $scheduledMessages,
    ): array {
        $requirements = $this->dependencyRequirements($variant);
        $unsatisfied = [];

        foreach ($requirements as $requiredVariantKey => $allowedStates) {
            if (! $this->dependencyRequirementSatisfied(
                enrollment: $enrollment,
                step: $step,
                requiredVariantKey: $requiredVariantKey,
                allowedStates: $allowedStates,
                scheduledMessages: $scheduledMessages,
            )) {
                $unsatisfied[] = [
                    'variant_key' => $requiredVariantKey,
                    'states' => $allowedStates,
                ];
            }
        }

        return [
            'satisfied' => $unsatisfied === [],
            'requirements' => $requirements,
            'unsatisfied' => $unsatisfied,
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function dependencyRequirements(CampaignStepVariant $variant): array
    {
        $rules = $variant->dependency_rules ?? [];

        if (! is_array($rules)) {
            return [];
        }

        $requirements = [];

        foreach ($this->stringList(data_get($rules, 'requires_scheduled_variant_keys', [])) as $variantKey) {
            $requirements[$variantKey] = $this->mergeDependencyStates($requirements[$variantKey] ?? [], ['scheduled']);
        }

        $variantStates = data_get($rules, 'requires_variant_states', []);

        if (is_array($variantStates)) {
            foreach ($variantStates as $variantKey => $states) {
                if (is_int($variantKey)) {
                    continue;
                }

                $variantKey = $this->normalizeSegment((string) $variantKey);
                $requirements[$variantKey] = $this->mergeDependencyStates(
                    $requirements[$variantKey] ?? [],
                    $this->dependencyStates($states),
                );
            }
        }

        $requires = data_get($rules, 'requires', []);

        if (is_array($requires)) {
            foreach ($requires as $requirement) {
                if (! is_array($requirement)) {
                    continue;
                }

                $variantKey = $this->nullableNormalizedSegment(
                    $requirement['variant_key']
                        ?? $requirement['variant']
                        ?? $requirement['key']
                        ?? null,
                );

                if ($variantKey === null) {
                    continue;
                }

                $states = $requirement['states']
                    ?? $requirement['state']
                    ?? $requirement['status']
                    ?? 'scheduled';

                $requirements[$variantKey] = $this->mergeDependencyStates(
                    $requirements[$variantKey] ?? [],
                    $this->dependencyStates($states),
                );
            }
        }

        return $requirements;
    }

    /**
     * @param array<int, string> $currentStates
     * @param array<int, string> $newStates
     * @return array<int, string>
     */
    private function mergeDependencyStates(array $currentStates, array $newStates): array
    {
        $states = array_values(array_unique(array_merge($currentStates, $newStates)));

        return $states !== [] ? $states : ['scheduled'];
    }

    /**
     * @param array<string, ScheduledMessage> $scheduledMessages
     * @param array<int, string> $allowedStates
     */
    private function dependencyRequirementSatisfied(
        CampaignEnrollment $enrollment,
        CampaignStep $step,
        string $requiredVariantKey,
        array $allowedStates,
        array $scheduledMessages,
    ): bool {
        $currentPassMessage = $scheduledMessages[$requiredVariantKey] ?? null;

        if ($currentPassMessage instanceof ScheduledMessage && $this->messageMatchesAnyDependencyState($currentPassMessage, $allowedStates)) {
            return true;
        }

        return ScheduledMessage::query()
            ->where('meta->campaign_enrollment_id', $enrollment->id)
            ->where('meta->campaign_step_id', $step->id)
            ->where('meta->campaign_step_variant_key', $requiredVariantKey)
            ->get()
            ->contains(fn (ScheduledMessage $message): bool => $this->messageMatchesAnyDependencyState($message, $allowedStates));
    }

    /**
     * @param array<int, string> $allowedStates
     */
    private function messageMatchesAnyDependencyState(ScheduledMessage $message, array $allowedStates): bool
    {
        foreach ($allowedStates as $state) {
            if ($this->messageMatchesDependencyState($message, $state)) {
                return true;
            }
        }

        return false;
    }

    private function messageMatchesDependencyState(ScheduledMessage $message, string $state): bool
    {
        return match ($state) {
            'scheduled' => true,
            'pending' => $message->status === ScheduledMessage::STATUS_PENDING,
            'sent' => $message->status === ScheduledMessage::STATUS_SENT,
            'skipped' => $message->status === ScheduledMessage::STATUS_SKIPPED,
            'failed' => $message->status === ScheduledMessage::STATUS_FAILED,
            'terminal' => in_array($message->status, [
                ScheduledMessage::STATUS_SENT,
                ScheduledMessage::STATUS_SKIPPED,
                ScheduledMessage::STATUS_FAILED,
            ], true),
            default => false,
        };
    }

    /**
     * @return array<int, string>
     */
    private function dependencyStates(mixed $states): array
    {
        if (is_string($states)) {
            $states = [$states];
        }

        if (! is_array($states)) {
            return ['scheduled'];
        }

        $states = array_values(array_unique(array_filter(array_map(
            fn (mixed $state): ?string => is_string($state) && trim($state) !== ''
                ? $this->normalizeSegment($state)
                : null,
            $states,
        ))));

        $states = array_values(array_intersect($states, [
            'scheduled',
            'pending',
            'sent',
            'skipped',
            'failed',
            'terminal',
        ]));

        return $states !== [] ? $states : ['scheduled'];
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
            fn (mixed $value): ?string => $this->nullableNormalizedSegment($value),
            $values,
        ))));
    }

    private function variantKey(CampaignStepVariant $variant): string
    {
        return $this->normalizeSegment($variant->key);
    }

    private function nullableNormalizedSegment(mixed $value): ?string
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
