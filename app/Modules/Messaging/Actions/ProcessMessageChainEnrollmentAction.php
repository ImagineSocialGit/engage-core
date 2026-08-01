<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\Delivery\MessageDeliveryComponent;
use App\Modules\Messaging\Jobs\ProcessMessageChainEnrollmentJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Services\ConditionChecker;
use App\Modules\Messaging\Services\MessageChainExecutionContextResolver;
use App\Modules\Messaging\Services\MessageChainTimingResolver;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\MessagePlanningGate;
use App\Modules\Messaging\Services\MessageRecipientPayloadResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class ProcessMessageChainEnrollmentAction
{
    public function __construct(
        private readonly MessageChainExecutionContextResolver $contextResolver,
        private readonly MessageChainTimingResolver $timingResolver,
        private readonly ConditionChecker $conditionChecker,
        private readonly MessageRecipientPayloadResolver $recipientPayloadResolver,
        private readonly MessageChannelAvailability $messageChannelAvailability,
        private readonly MessagePlanningGate $planningGate,
        private readonly ScheduleMessageAction $scheduleMessage,
        private readonly AttachScheduledMessageComponentsAction $attachComponents,
    ) {}

    /**
     * @param array<int, MessageDeliveryComponent> $components
     */
    public function handle(
        MessageChainEnrollment|int $enrollment,
        array $components = [],
        bool $materializeCurrentWave = false,
    ): MessageChainEnrollment {
        $enrollmentId = $enrollment instanceof MessageChainEnrollment
            ? (int) $enrollment->getKey()
            : $enrollment;

        $result = DB::transaction(
            fn (): array => $this->processLocked(
                enrollmentId: $enrollmentId,
                components: $components,
                materializeCurrentWave: $materializeCurrentWave,
            ),
            3,
        );

        /** @var MessageChainEnrollment $resolved */
        $resolved = $result['enrollment'];

        if ($result['dispatch'] === true) {
            $this->dispatch($resolved);
        }

        return $resolved;
    }

    public function handleTerminal(
        ScheduledMessage $scheduledMessage,
    ): void {
        if ($scheduledMessage->message_chain_enrollment_id === null
            || $scheduledMessage->message_chain_step_variant_id === null
        ) {
            return;
        }

        $result = DB::transaction(function () use ($scheduledMessage): array {
            $message = ScheduledMessage::query()
                ->with('messageChainStepVariant')
                ->find($scheduledMessage->getKey());

            if (! $message instanceof ScheduledMessage
                || $message->message_chain_enrollment_id === null
                || $message->message_chain_step_variant_id === null
            ) {
                return ['enrollment' => null, 'dispatch' => false];
            }

            $enrollment = $this->lockedEnrollment(
                (int) $message->message_chain_enrollment_id,
            );

            if (! $enrollment->isActive()) {
                return ['enrollment' => $enrollment, 'dispatch' => false];
            }

            $variant = $message->messageChainStepVariant;

            if (! $variant instanceof MessageChainStepVariant
                || (int) $variant->message_chain_step_id
                    !== (int) $enrollment->current_message_chain_step_id
            ) {
                return ['enrollment' => $enrollment, 'dispatch' => false];
            }

            $step = $enrollment->currentMessageChainStep;

            if (! $step instanceof MessageChainStep
                || ! $this->waveSatisfied($enrollment, $step)
            ) {
                return ['enrollment' => $enrollment, 'dispatch' => false];
            }

            return $this->advance(
                enrollment: $enrollment,
                context: $this->contextResolver->resolve($enrollment),
                baseAt: now(),
            );
        }, 3);

        $enrollment = $result['enrollment'] ?? null;

        if (
            $result['dispatch'] === true
            && $enrollment instanceof MessageChainEnrollment
        ) {
            $this->dispatch($enrollment);
        }
    }

    /**
     * @param array<int, MessageDeliveryComponent> $components
     * @return array{enrollment: MessageChainEnrollment, dispatch: bool}
     */
    private function processLocked(
        int $enrollmentId,
        array $components = [],
        bool $materializeCurrentWave = false,
    ): array {
        $enrollment = $this->lockedEnrollment($enrollmentId);

        if (! $enrollment->isActive()) {
            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $step = $enrollment->currentMessageChainStep;

        if ($step instanceof MessageChainStep && $components !== []) {
            $this->attachToWave(
                enrollment: $enrollment,
                step: $step,
                components: $components,
            );
        }

        if ($enrollment->next_action_at === null) {
            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        if ($enrollment->next_action_at->isFuture() && ! $materializeCurrentWave) {
            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $context = $this->contextResolver->resolve($enrollment);
        $exitConditions = $enrollment->messageChainVersion?->exit_conditions;

        if (
            is_array($exitConditions)
            && $exitConditions !== []
            && $this->conditionChecker->passes($exitConditions, $context)
        ) {
            $enrollment->forceFill([
                'current_message_chain_step_id' => null,
                'next_action_at' => null,
                'status' => MessageChainEnrollment::STATUS_EXITED,
                'exited_at' => now(),
                'exit_reason_code' => 'conditions_met',
            ])->save();

            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        if (! $step instanceof MessageChainStep) {
            $this->complete($enrollment);

            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $stepConditions = is_array($step->conditions)
            ? $step->conditions
            : [];

        if (
            $stepConditions !== []
            && ! $this->conditionChecker->passes($stepConditions, $context)
        ) {
            return $this->advance(
                enrollment: $enrollment,
                context: $context,
                baseAt: now(),
            );
        }

        $existingWave = $this->waveMessages($enrollment, $step);

        if ($existingWave->isNotEmpty()) {
            $this->attachToMessages(
                messages: $existingWave,
                components: $components,
            );
            $enrollment->forceFill(['next_action_at' => null])->save();

            if ($this->waveSatisfied($enrollment, $step, $existingWave)) {
                return $this->advance(
                    enrollment: $enrollment,
                    context: $context,
                    baseAt: now(),
                );
            }

            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $variants = $this->eligibleVariants(
            enrollment: $enrollment,
            step: $step,
            context: $context,
        );

        if ($variants->isEmpty()) {
            return $this->advance(
                enrollment: $enrollment,
                context: $context,
                baseAt: now(),
            );
        }

        $sendAt = $enrollment->next_action_at->copy();

        foreach ($variants as $variant) {
            $message = $this->materialize(
                enrollment: $enrollment,
                variant: $variant,
                sendAt: $sendAt,
            );
            $this->attachComponents->handle(
                scheduledMessage: $message,
                components: $components,
            );
        }

        $enrollment->forceFill([
            'next_action_at' => null,
        ])->save();

        return ['enrollment' => $enrollment, 'dispatch' => false];
    }

    private function lockedEnrollment(
        int $enrollmentId,
    ): MessageChainEnrollment {
        return MessageChainEnrollment::query()
            ->with([
                'messageChainVersion.messageChain',
                'messageChainVersion.steps.variants.messageTemplateVersion',
                'recipient',
                'context',
                'origin',
                'currentMessageChainStep.variants.messageTemplateVersion',
            ])
            ->whereKey($enrollmentId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @param array<string, mixed> $context
     * @return Collection<int, MessageChainStepVariant>
     */
    private function eligibleVariants(
        MessageChainEnrollment $enrollment,
        MessageChainStep $step,
        array $context,
    ): Collection {
        $recipient = $enrollment->recipient;

        if (! $recipient instanceof Model) {
            throw new RuntimeException(
                "MessageChainEnrollment [{$enrollment->getKey()}] has no recipient.",
            );
        }

        $contextModel = $enrollment->context instanceof Model
            ? $enrollment->context
            : null;

        $eligible = $step->variants
            ->filter(
                fn (MessageChainStepVariant $variant): bool =>
                    (bool) $variant->is_active,
            )
            ->filter(function (
                MessageChainStepVariant $variant,
            ) use ($enrollment, $recipient, $contextModel, $context): bool {
                if (
                    is_string($enrollment->surface)
                    && $enrollment->surface !== ''
                    && ! $this->messageChannelAvailability->isVisibleForSurface(
                        channel: $variant->channel,
                        surface: $enrollment->surface,
                        purpose: $variant->purpose,
                        scope: $variant->scope,
                    )
                ) {
                    return false;
                }

                $conditions = is_array($variant->conditions)
                    ? $variant->conditions
                    : [];

                if (
                    $conditions !== []
                    && ! $this->conditionChecker->passes($conditions, $context)
                ) {
                    return false;
                }

                $templateVersion = $variant->messageTemplateVersion;

                if (! $templateVersion instanceof MessageTemplateVersion) {
                    throw new RuntimeException(
                        "MessageChainStepVariant [{$variant->getKey()}] has no resolvable MessageTemplateVersion.",
                    );
                }

                $payload = $this->recipientPayloadResolver->resolve(
                    recipient: $recipient,
                    channel: $variant->channel,
                    purpose: $variant->purpose,
                    scope: $variant->scope,
                    messageType: $variant->message_type,
                    definitionPayload: $templateVersion->payload(),
                );

                if (! is_array($payload)) {
                    return false;
                }

                return $this->planningGate->allows(
                    recipient: $recipient,
                    channel: $variant->channel,
                    purpose: $variant->purpose,
                    scope: $variant->scope,
                    definition: [
                        'enabled' => true,
                        'message_type' => $variant->message_type,
                        'conditions' => [],
                    ],
                    payload: $payload,
                    context: $contextModel,
                );
            })
            ->values();

        return match ($step->variant_strategy) {
            MessageChainStep::VARIANT_STRATEGY_FIRST_AVAILABLE =>
                $eligible->take(1)->values(),
            MessageChainStep::VARIANT_STRATEGY_SEND_ALL_ELIGIBLE =>
                $eligible,
            MessageChainStep::VARIANT_STRATEGY_DEPENDENCY_AWARE =>
                throw new LogicException(
                    'Dependency-aware message-chain variant selection is not implemented.',
                ),
            default => throw new LogicException(
                "MessageChainStep [{$step->getKey()}] has unsupported variant strategy [{$step->variant_strategy}].",
            ),
        };
    }

    private function materialize(
        MessageChainEnrollment $enrollment,
        MessageChainStepVariant $variant,
        Carbon $sendAt,
    ): ScheduledMessage {
        $recipient = $enrollment->recipient;

        if (! $recipient instanceof Model) {
            throw new RuntimeException(
                "MessageChainEnrollment [{$enrollment->getKey()}] has no recipient.",
            );
        }

        $context = $enrollment->context instanceof Model
            ? $enrollment->context
            : null;
        $destination = $this->recipientPayloadResolver->destinationForChannel(
            recipient: $recipient,
            channel: $variant->channel,
        );

        if (! is_string($destination) || trim($destination) === '') {
            throw new RuntimeException(
                "MessageChainStepVariant [{$variant->getKey()}] has no current destination.",
            );
        }

        return $this->scheduleMessage->handle(
            recipient: $recipient,
            channel: $variant->channel,
            purpose: $variant->purpose,
            scope: $variant->scope,
            messageType: $variant->message_type,
            payloadClass: $this->payloadClass($variant->channel),
            payload: [
                'to' => trim($destination),
            ],
            sendAt: $sendAt,
            context: $context,
            behaviorOwner: $enrollment,
            dedupeKey: $this->scheduledMessageDedupeKey(
                enrollment: $enrollment,
                variant: $variant,
            ),
            meta: [],
            queue: $variant->queue,
            dispatchKeys: [],
            definitionConfigPath: null,
            messageTemplateVersionId: (int) $variant->message_template_version_id,
            messageChainEnrollment: $enrollment,
            messageChainStepVariant: $variant,
        );
    }

    /**
     * @param array<int, MessageDeliveryComponent> $components
     */
    private function attachToWave(
        MessageChainEnrollment $enrollment,
        MessageChainStep $step,
        array $components,
    ): void {
        $this->attachToMessages(
            messages: $this->waveMessages($enrollment, $step),
            components: $components,
        );
    }

    /**
     * @param Collection<int, ScheduledMessage> $messages
     * @param array<int, MessageDeliveryComponent> $components
     */
    private function attachToMessages(
        Collection $messages,
        array $components,
    ): void {
        foreach ($messages as $message) {
            $this->attachComponents->handle(
                scheduledMessage: $message,
                components: $components,
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     * @return array{enrollment: MessageChainEnrollment, dispatch: bool}
     */
    private function advance(
        MessageChainEnrollment $enrollment,
        array $context,
        Carbon $baseAt,
    ): array {
        $currentStep = $enrollment->currentMessageChainStep;

        if (! $currentStep instanceof MessageChainStep) {
            $this->complete($enrollment);

            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $nextStep = MessageChainStep::query()
            ->where(
                'message_chain_version_id',
                $enrollment->message_chain_version_id,
            )
            ->where('is_active', true)
            ->where(function ($query) use ($currentStep): void {
                $query
                    ->where('sort_order', '>', $currentStep->sort_order)
                    ->orWhere(function ($query) use ($currentStep): void {
                        $query
                            ->where('sort_order', $currentStep->sort_order)
                            ->where('id', '>', $currentStep->getKey());
                    });
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $nextStep instanceof MessageChainStep) {
            $this->complete($enrollment);

            return ['enrollment' => $enrollment, 'dispatch' => false];
        }

        $enrollment->forceFill([
            'current_message_chain_step_id' => $nextStep->getKey(),
            'next_action_at' => $this->timingResolver->resolve(
                step: $nextStep,
                context: $context,
                baseAt: $baseAt,
            ),
        ])->save();
        $enrollment->setRelation('currentMessageChainStep', $nextStep);

        return ['enrollment' => $enrollment, 'dispatch' => true];
    }

    private function complete(
        MessageChainEnrollment $enrollment,
    ): void {
        $enrollment->forceFill([
            'current_message_chain_step_id' => null,
            'next_action_at' => null,
            'status' => MessageChainEnrollment::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
        $enrollment->setRelation('currentMessageChainStep', null);
    }

    private function waveSatisfied(
        MessageChainEnrollment $enrollment,
        MessageChainStep $step,
        ?Collection $messages = null,
    ): bool {
        $messages ??= $this->waveMessages($enrollment, $step);

        if ($messages->isEmpty()) {
            return false;
        }

        $terminalStatuses = [
            ScheduledMessage::STATUS_SENT,
            ScheduledMessage::STATUS_SKIPPED,
            ScheduledMessage::STATUS_FAILED,
        ];
        $allTerminal = $messages->every(
            fn (ScheduledMessage $message): bool =>
                in_array($message->status, $terminalStatuses, true),
        );

        return match ($step->advance_policy) {
            MessageChainStep::ADVANCE_ALL_TERMINAL => $allTerminal,
            MessageChainStep::ADVANCE_FIRST_TERMINAL =>
                $messages->contains(
                    fn (ScheduledMessage $message): bool =>
                        in_array($message->status, $terminalStatuses, true),
                ),
            MessageChainStep::ADVANCE_FIRST_SENT =>
                $messages->contains(
                    fn (ScheduledMessage $message): bool =>
                        $message->status === ScheduledMessage::STATUS_SENT,
                ) || $allTerminal,
            default => throw new LogicException(
                "MessageChainStep [{$step->getKey()}] has unsupported advance policy [{$step->advance_policy}].",
            ),
        };
    }

    /**
     * @return Collection<int, ScheduledMessage>
     */
    private function waveMessages(
        MessageChainEnrollment $enrollment,
        MessageChainStep $step,
    ): Collection {
        $variantIds = $step->variants
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($variantIds === []) {
            return collect();
        }

        return ScheduledMessage::query()
            ->where(
                'message_chain_enrollment_id',
                $enrollment->getKey(),
            )
            ->whereIn(
                'message_chain_step_variant_id',
                $variantIds,
            )
            ->orderBy('id')
            ->get();
    }

    private function payloadClass(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'email' => EmailPayload::class,
            'sms' => SmsPayload::class,
            default => throw new InvalidArgumentException(
                "Unsupported message-chain channel [{$channel}].",
            ),
        };
    }

    private function scheduledMessageDedupeKey(
        MessageChainEnrollment $enrollment,
        MessageChainStepVariant $variant,
    ): string {
        return implode(':', [
            'message_chain',
            $enrollment->getKey(),
            'variant',
            $variant->getKey(),
        ]);
    }

    private function dispatch(MessageChainEnrollment $enrollment): void
    {
        ProcessMessageChainEnrollmentJob::dispatch(
            enrollmentId: (int) $enrollment->getKey(),
        )->delay($enrollment->next_action_at);
    }
}