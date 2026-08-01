<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Messaging\Models\MessageChainStep;
use App\Modules\Messaging\Models\MessageChainStepVariant;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use App\Modules\Messaging\Models\ScheduledMessage;
use App\Modules\Messaging\Services\PendingMessageDeliveryConsolidator;
use App\Modules\Messaging\Services\ScheduledMessageMetaCanonicalizer;
use App\Modules\Messaging\Services\ScheduledMessagePayloadCanonicalizer;
use App\Support\Queues\QueueContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ScheduleMessageAction
{
    public function __construct(
        private readonly PendingMessageDeliveryConsolidator $pendingMessageDeliveryConsolidator,
        private readonly QueueContract $queueContract,
        private readonly ScheduledMessageMetaCanonicalizer $metaCanonicalizer,
        private readonly ScheduledMessagePayloadCanonicalizer $payloadCanonicalizer,
    ) {}

    public function handle(
        Model $recipient,
        MessageChannel|string $channel,
        MessagePurpose|string $purpose,
        string $scope,
        string $messageType,
        string $payloadClass,
        array $payload,
        Carbon|string|null $sendAt = null,
        ?Model $context = null,
        ?Model $behaviorOwner = null,
        ?string $dedupeKey = null,
        ?array $meta = null,
        ?string $queue = null,
        array $dispatchKeys = [],
        ?string $definitionConfigPath = null,
        ?int $messageTemplateVersionId = null,
        ?MessageChainEnrollment $messageChainEnrollment = null,
        ?MessageChainStepVariant $messageChainStepVariant = null,
    ): ScheduledMessage {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);
        $messageType = $this->normalizeSegment($messageType);
        [
            $messageChainEnrollmentId,
            $messageChainStepVariantId,
        ] = $this->chainReferences(
            enrollment: $messageChainEnrollment,
            variant: $messageChainStepVariant,
        );

        if ($behaviorOwner === null && $messageChainEnrollment !== null) {
            $behaviorOwner = $messageChainEnrollment;
        }

        $sourceSendAt = $sendAt ? Carbon::parse($sendAt) : now();
        $sendAt = $sourceSendAt->copy()->utc();

        $meta ??= [];
        $queue = $this->queueContract->assertDispatchable($this->nullableString(
            $queue ?? $meta['queue'] ?? null,
        ));
        $definitionConfigPath = $this->nullableString(
            $definitionConfigPath
                ?? $meta['definition_config_path']
                ?? null,
        );
        $dispatchKeys = $this->normalizeDispatchKeys(
            $dispatchKeys !== []
                ? $dispatchKeys
                : ($meta['dispatch_keys'] ?? []),
        );
        $templateVersion = $this->messageTemplateVersion(
            $messageTemplateVersionId,
        );
        $conditions = is_array($meta['conditions'] ?? null)
            ? $meta['conditions']
            : [];
        $payload = $this->payloadCanonicalizer->forPersistence(
            payloadClass: $payloadClass,
            payload: $templateVersion instanceof MessageTemplateVersion
                ? array_replace_recursive(
                    $templateVersion->payload(),
                    $payload,
                )
                : $payload,
            channel: $channel,
            purpose: $purpose,
            scope: $scope,
            messageType: $messageType,
            conditions: $conditions,
        );

        if ($templateVersion instanceof MessageTemplateVersion) {
            $templatePayload = $this->payloadCanonicalizer->forPersistence(
                payloadClass: $payloadClass,
                payload: $templateVersion->payload(),
                channel: $channel,
                purpose: $purpose,
                scope: $scope,
                messageType: $messageType,
                conditions: $conditions,
            );
            $payload = $this->payloadDifferences(
                resolved: $payload,
                template: $templatePayload,
            );
        }

        $meta = $this->metaCanonicalizer->forPersistence($meta);

        $attributes = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'message_template_version_id' => $messageTemplateVersionId,
            'message_chain_enrollment_id' => $messageChainEnrollmentId,
            'message_chain_step_variant_id' => $messageChainStepVariantId,
            'channel' => $channel,
            'message_type' => $messageType,
            'purpose' => $purpose,
            'scope' => $scope,
            'payload_class' => $payloadClass,
            'queue' => $queue,
            'dispatch_keys' => $dispatchKeys,
            'definition_config_path' => $definitionConfigPath,
            'payload' => $payload,
            'send_at' => $sendAt,
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => $meta,
        ];

        if ($context) {
            $attributes['context_type'] = $context->getMorphClass();
            $attributes['context_id'] = $context->getKey();
        }

        if ($behaviorOwner) {
            $attributes['behavior_owner_type'] = $behaviorOwner->getMorphClass();
            $attributes['behavior_owner_id'] = $behaviorOwner->getKey();
        }

        $scheduledMessage = $dedupeKey
            ? ScheduledMessage::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                $attributes + ['dedupe_key' => $dedupeKey],
            )
            : ScheduledMessage::query()->create($attributes);

        $wasRecentlyCreated = $scheduledMessage->wasRecentlyCreated;

        if (
            ! $wasRecentlyCreated
            && is_array(data_get($meta, 'delivery_consolidation'))
        ) {
            $scheduledMessage = $this->pendingMessageDeliveryConsolidator
                ->merge(
                    scheduledMessage: $scheduledMessage,
                    incomingAttributes: $attributes,
                );
        }

        if ($wasRecentlyCreated) {
            SendScheduledMessageJob::dispatch(
                scheduledMessageId: $scheduledMessage->id,
                horizon: $this->horizonPayload(
                    $scheduledMessage,
                    $sendAt,
                    $context,
                ),
            )
                ->delay($sendAt)
                ->afterCommit()
                ->onQueue($queue);
        }

        return $scheduledMessage;
    }

    private function messageTemplateVersion(
        ?int $messageTemplateVersionId,
    ): ?MessageTemplateVersion {
        if ($messageTemplateVersionId === null) {
            return null;
        }

        return MessageTemplateVersion::query()
            ->findOrFail($messageTemplateVersionId);
    }

    /**
     * @param array<string, mixed> $resolved
     * @param array<string, mixed> $template
     * @return array<string, mixed>
     */
    private function payloadDifferences(
        array $resolved,
        array $template,
    ): array {
        $differences = [];

        foreach ($resolved as $key => $value) {
            if (! array_key_exists($key, $template)) {
                $differences[$key] = $value;

                continue;
            }

            $templateValue = $template[$key];

            if (is_array($value)
                && is_array($templateValue)
                && ! array_is_list($value)
                && ! array_is_list($templateValue)
            ) {
                $nested = $this->payloadDifferences(
                    resolved: $value,
                    template: $templateValue,
                );

                if ($nested !== []) {
                    $differences[$key] = $nested;
                }

                continue;
            }

            if ($value !== $templateValue) {
                $differences[$key] = $value;
            }
        }

        return $differences;
    }

    /**
     * @return array<string, mixed>
     */
    private function horizonPayload(
        ScheduledMessage $scheduledMessage,
        Carbon $sendAt,
        ?Model $context,
    ): array {
        return array_filter([
            'scheduled_message_id' => $scheduledMessage->id,
            'message_template_version_id' => $scheduledMessage->message_template_version_id,
            'message_chain_enrollment_id' => $scheduledMessage->message_chain_enrollment_id,
            'message_chain_step_variant_id' => $scheduledMessage->message_chain_step_variant_id,
            'recipient_type' => class_basename(
                (string) $scheduledMessage->recipient_type,
            ),
            'recipient_id' => $scheduledMessage->recipient_id,
            'channel' => $scheduledMessage->channel,
            'purpose' => $scheduledMessage->purpose,
            'scope' => $scheduledMessage->scope,
            'message_type' => $scheduledMessage->message_type,
            'queue' => $scheduledMessage->queue,
            'send_at' => $sendAt->toIso8601String(),
            'context_type' => $context ? class_basename($context) : null,
            'context_id' => $context?->getKey(),
            'behavior_owner_type' => $scheduledMessage->behavior_owner_type
                ? class_basename(
                    (string) $scheduledMessage->behavior_owner_type,
                )
                : null,
            'behavior_owner_id' => $scheduledMessage->behavior_owner_id,
            'dispatch_keys' => $scheduledMessage->dispatch_keys,
            'definition_config_path' => $scheduledMessage->definition_config_path,
            'campaign_key' => $scheduledMessage->meta['campaign_key'] ?? null,
            'campaign_step' => $scheduledMessage->meta['campaign_step'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function chainReferences(
        ?MessageChainEnrollment $enrollment,
        ?MessageChainStepVariant $variant,
    ): array {
        if ($enrollment === null && $variant === null) {
            return [null, null];
        }

        if ($enrollment === null || $variant === null) {
            throw new InvalidArgumentException(
                'Scheduled chain messages require both enrollment and step-variant references.',
            );
        }

        $step = $variant->relationLoaded('messageChainStep')
            ? $variant->getRelation('messageChainStep')
            : $variant->messageChainStep()->first();

        if (! $step instanceof MessageChainStep) {
            throw new InvalidArgumentException(
                "MessageChainStepVariant [{$variant->getKey()}] has no resolvable step.",
            );
        }

        if (
            (int) $step->message_chain_version_id
            !== (int) $enrollment->message_chain_version_id
        ) {
            throw new InvalidArgumentException(
                'Scheduled chain message references do not belong to the same immutable chain version.',
            );
        }

        return [
            (int) $enrollment->getKey(),
            (int) $variant->getKey(),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDispatchKeys(mixed $dispatchKeys): array
    {
        if (! is_array($dispatchKeys)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== ''
                ? $this->normalizeSegment($dispatchKey)
                : null,
            $dispatchKeys,
        ))));
    }

    private function normalizeEnumValue(MessageChannel|MessagePurpose|string $value): string
    {
        return $value instanceof MessageChannel || $value instanceof MessagePurpose
            ? $value->value
            : strtolower(trim($value));
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