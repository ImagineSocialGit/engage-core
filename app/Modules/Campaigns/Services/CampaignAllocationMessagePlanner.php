<?php

namespace App\Modules\Campaigns\Services;

use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignAllocationAssignment;
use App\Modules\Campaigns\Models\CampaignAllocationEnrollment;
use App\Modules\Campaigns\Models\CampaignAllocationRun;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\ScheduleMessageAction;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainVersion;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessagePlanningGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class CampaignAllocationMessagePlanner
{
    public function __construct(
        private readonly ConditionChecker $conditions,
        private readonly MessageChannelAvailability $channelAvailability,
        private readonly MessagePlanningGate $planningGate,
        private readonly MessageRecipientPayloadResolver $payloadResolver,
        private readonly ScheduleMessageAction $scheduleMessage,
    ) {}

    public function selectVariant(
        Campaign $campaign,
        CampaignAllocationEnrollment $enrollment,
        MessageChainStep $step,
    ): ?MessageChainStepVariant {
        if ($step->variant_strategy
            !== MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE
        ) {
            throw new RuntimeException(sprintf(
                'Recurring allocation message [%s] must use the first_available variant strategy.',
                (string) $step->key,
            ));
        }

        foreach ($step->variants as $variant) {
            if ($variant instanceof MessageChainStepVariant
                && $this->variantIsPlannable(
                    campaign: $campaign,
                    enrollment: $enrollment,
                    step: $step,
                    variant: $variant,
                )
            ) {
                return $variant;
            }
        }

        return null;
    }

    public function scheduleAssignment(
        CampaignAllocationAssignment $assignment,
    ): ?ScheduledMessage {
        $assignment->loadMissing([
            'campaign',
            'contact',
            'enrollment',
            'run',
            'messageChainVersion.steps.variants.messageTemplateVersion',
        ]);

        if ($assignment->scheduled_message_id !== null) {
            return $assignment->scheduledMessage()->first();
        }

        $campaign = $assignment->campaign;
        $contact = $assignment->contact;
        $enrollment = $assignment->enrollment;
        $run = $assignment->run;
        $version = $assignment->messageChainVersion;

        if (! $campaign instanceof Campaign
            || ! $contact instanceof Contact
            || ! $enrollment instanceof CampaignAllocationEnrollment
            || ! $run instanceof CampaignAllocationRun
            || $version === null
        ) {
            throw new RuntimeException(sprintf(
                'Campaign allocation assignment [%d] has incomplete runtime identity.',
                (int) $assignment->getKey(),
            ));
        }

        $step = $version->steps->first(
            fn (MessageChainStep $candidate): bool =>
                (string) $candidate->key === (string) $assignment->message_step_key,
        );

        if (! $step instanceof MessageChainStep) {
            throw new RuntimeException(sprintf(
                'Campaign allocation assignment [%d] references missing message step [%s].',
                (int) $assignment->getKey(),
                (string) $assignment->message_step_key,
            ));
        }

        $variantId = data_get(
            $assignment->meta,
            'selection.message_chain_step_variant_id',
        );
        $variant = $step->variants->first(
            fn (MessageChainStepVariant $candidate): bool =>
                is_numeric($variantId)
                && (int) $candidate->getKey() === (int) $variantId,
        );

        if (! $variant instanceof MessageChainStepVariant) {
            throw new RuntimeException(sprintf(
                'Campaign allocation assignment [%d] has no selected immutable message variant.',
                (int) $assignment->getKey(),
            ));
        }

        if (! $this->variantIsPlannable(
            campaign: $campaign,
            enrollment: $enrollment,
            step: $step,
            variant: $variant,
        )) {
            $this->recordPlanningState(
                assignment: $assignment,
                status: 'denied',
                details: [
                    'reason' => 'selected_variant_no_longer_plannable',
                ],
            );

            return null;
        }

        $destination = $this->payloadResolver->destinationForChannel(
            recipient: $contact,
            channel: (string) $variant->channel,
        );

        if (! is_string($destination) || trim($destination) === '') {
            $this->recordPlanningState(
                assignment: $assignment,
                status: 'denied',
                details: [
                    'reason' => 'destination_missing',
                ],
            );

            return null;
        }

        try {
            $scheduled = $this->scheduleMessage->handle(
                recipient: $contact,
                channel: (string) $variant->channel,
                purpose: (string) $variant->purpose,
                scope: (string) $variant->scope,
                messageType: (string) $variant->message_type,
                payloadClass: $this->payloadClass((string) $variant->channel),
                payload: [
                    'to' => trim($destination),
                    'tokens' => $this->tokens(
                        campaign: $campaign,
                        enrollment: $enrollment,
                        contact: $contact,
                        assignment: $assignment,
                        run: $run,
                    ),
                ],
                sendAt: $this->requestedSendAt($run, $version, $step),
                context: $assignment,
                behaviorOwner: $assignment,
                dedupeKey: 'campaign_allocation_assignment:'.(int) $assignment->getKey(),
                meta: [
                    'surface' => 'campaigns',
                    'campaign_key' => (string) $campaign->key,
                    'campaign_step_key' => (string) $step->key,
                    'campaign_allocation_run_id' => (int) $run->getKey(),
                    'campaign_allocation_enrollment_id' => (int) $enrollment->getKey(),
                    'campaign_allocation_assignment_id' => (int) $assignment->getKey(),
                ],
                queue: $variant->queue,
                dispatchKeys: [],
                definitionConfigPath: null,
                messageTemplateVersionId: (int) $variant->message_template_version_id,
                messageChainEnrollment: null,
                messageChainStepVariant: null,
                replyProfileKey: $variant->reply_profile_key,
            );
        } catch (Throwable $exception) {
            $this->recordPlanningState(
                assignment: $assignment,
                status: 'failed',
                details: [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );

            throw $exception;
        }

        $assignment->forceFill([
            'scheduled_message_id' => $scheduled->getKey(),
            'meta' => array_replace_recursive(
                is_array($assignment->meta) ? $assignment->meta : [],
                [
                    'planning' => [
                        'status' => 'scheduled',
                        'scheduled_message_id' => (int) $scheduled->getKey(),
                        'send_at' => $scheduled->send_at?->toISOString(),
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ),
        ])->save();

        return $scheduled;
    }

    private function variantIsPlannable(
        Campaign $campaign,
        CampaignAllocationEnrollment $enrollment,
        MessageChainStep $step,
        MessageChainStepVariant $variant,
    ): bool {
        if (! $variant->is_active) {
            return false;
        }

        $contact = $enrollment->contact;

        if (! $contact instanceof Contact) {
            throw new RuntimeException(sprintf(
                'Campaign allocation enrollment [%d] has no Contact.',
                (int) $enrollment->getKey(),
            ));
        }

        if (! $this->channelAvailability->isVisibleForSurface(
            channel: (string) $variant->channel,
            surface: 'campaigns',
            purpose: (string) $variant->purpose,
            scope: (string) $variant->scope,
        )) {
            return false;
        }

        $context = $this->executionContext(
            campaign: $campaign,
            enrollment: $enrollment,
            contact: $contact,
        );
        $stepConditions = is_array($step->conditions)
            ? $step->conditions
            : [];

        if ($stepConditions !== []
            && ! $this->conditions->passes($stepConditions, $context)
        ) {
            return false;
        }

        $variantConditions = is_array($variant->conditions)
            ? $variant->conditions
            : [];

        if ($variantConditions !== []
            && ! $this->conditions->passes($variantConditions, $context)
        ) {
            return false;
        }

        $templateVersion = $variant->messageTemplateVersion;

        if (! $templateVersion instanceof MessageTemplateVersion) {
            throw new RuntimeException(sprintf(
                'Recurring allocation variant [%d] has no resolvable MessageTemplateVersion.',
                (int) $variant->getKey(),
            ));
        }

        $payload = $this->payloadResolver->resolve(
            recipient: $contact,
            channel: (string) $variant->channel,
            purpose: (string) $variant->purpose,
            scope: (string) $variant->scope,
            messageType: (string) $variant->message_type,
            definitionPayload: $templateVersion->payload(),
            payload: [
                'tokens' => $this->tokens(
                    campaign: $campaign,
                    enrollment: $enrollment,
                    contact: $contact,
                ),
            ],
        );

        if (! is_array($payload)) {
            return false;
        }

        return $this->planningGate->allows(
            recipient: $contact,
            channel: (string) $variant->channel,
            purpose: (string) $variant->purpose,
            scope: (string) $variant->scope,
            definition: [
                'enabled' => true,
                'message_type' => (string) $variant->message_type,
                'conditions' => [],
            ],
            payload: $payload,
            context: $enrollment,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function executionContext(
        Campaign $campaign,
        CampaignAllocationEnrollment $enrollment,
        Contact $contact,
    ): array {
        return [
            'recipient' => $contact->attributesToArray(),
            'contact' => $contact->attributesToArray(),
            'context' => $enrollment->attributesToArray(),
            'campaign_enrollment' => $enrollment->attributesToArray(),
            'campaign_allocation_enrollment' => $enrollment->attributesToArray(),
            'origin' => $campaign->attributesToArray(),
            'campaign' => $campaign->attributesToArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tokens(
        Campaign $campaign,
        CampaignAllocationEnrollment $enrollment,
        Contact $contact,
        ?CampaignAllocationAssignment $assignment = null,
        ?CampaignAllocationRun $run = null,
    ): array {
        $tokens = $this->executionContext(
            campaign: $campaign,
            enrollment: $enrollment,
            contact: $contact,
        );

        if ($assignment instanceof CampaignAllocationAssignment) {
            $tokens['campaign_allocation_assignment'] =
                $assignment->attributesToArray();
        }

        if ($run instanceof CampaignAllocationRun) {
            $tokens['campaign_allocation_run'] = $run->attributesToArray();
        }

        return $tokens;
    }

    private function requestedSendAt(
        CampaignAllocationRun $run,
        MessageChainVersion $version,
        MessageChainStep $targetStep,
    ): Carbon {
        $base = $run->started_at
            ? Carbon::parse($run->started_at)->utc()
            : ($run->scheduled_for
                ? Carbon::parse($run->scheduled_for)->utc()
                : now()->utc());

        $orderedSteps = $version->steps
            ->filter(fn (MessageChainStep $step): bool => (bool) $step->is_active)
            ->sort(function (MessageChainStep $left, MessageChainStep $right): int {
                return ((int) $left->sort_order <=> (int) $right->sort_order)
                    ?: ((int) $left->getKey() <=> (int) $right->getKey());
            })
            ->values();

        foreach ($orderedSteps as $step) {
            if ((int) $step->getKey() !== (int) $targetStep->getKey()) {
                continue;
            }

            if ($step->timing_type === MessageChainStep::TIMING_IMMEDIATE) {
                return $base->copy();
            }

            if ($step->timing_type === MessageChainStep::TIMING_DELAY) {
                return $base->copy()->addSeconds(max(0, (int) $step->offset_seconds));
            }

            throw new RuntimeException(sprintf(
                'Recurring allocation message [%s] uses unsupported timing [%s].',
                (string) $step->key,
                (string) $step->timing_type,
            ));
        }

        throw new RuntimeException(sprintf(
            'Recurring allocation message [%s] is not part of the pinned allocation schedule.',
            (string) $targetStep->key,
        ));
    }

    private function payloadClass(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'email' => EmailPayload::class,
            'sms' => SmsPayload::class,
            default => throw new InvalidArgumentException(
                "Unsupported recurring-allocation channel [{$channel}].",
            ),
        };
    }

    /**
     * @param array<string, mixed> $details
     */
    private function recordPlanningState(
        CampaignAllocationAssignment $assignment,
        string $status,
        array $details,
    ): void {
        $assignment->forceFill([
            'meta' => array_replace_recursive(
                is_array($assignment->meta) ? $assignment->meta : [],
                [
                    'planning' => $details + [
                        'status' => $status,
                        'updated_at' => now()->toISOString(),
                    ],
                ],
            ),
        ])->save();
    }
}