<?php

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Jobs\SendScheduledMessageJob;
use App\Modules\Messaging\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleMessageAction
{
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
    ): ScheduledMessage {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);
        $messageType = $this->normalizeSegment($messageType);
        $sendAt = $sendAt ? Carbon::parse($sendAt) : now();
        $meta ??= [];

        $queue = $this->nullableString($meta['queue'] ?? null);
        $definitionConfigPath = $this->nullableString($meta['definition_config_path'] ?? null);
        $dispatchKeys = $this->normalizeDispatchKeys($meta['dispatch_keys'] ?? []);

        $attributes = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
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

        if ($scheduledMessage->wasRecentlyCreated) {
            $dispatch = SendScheduledMessageJob::dispatch(
                scheduledMessageId: $scheduledMessage->id,
                horizon: $this->horizonPayload($scheduledMessage, $sendAt, $context),
            )->delay($sendAt)->afterCommit();

            if ($queue !== null) {
                $dispatch->onQueue($queue);
            }
        }

        return $scheduledMessage;
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
            'recipient_type' => class_basename((string) $scheduledMessage->recipient_type),
            'recipient_id' => $scheduledMessage->recipient_id,
            'channel' => $scheduledMessage->channel,
            'purpose' => $scheduledMessage->purpose,
            'scope' => $scheduledMessage->scope,
            'message_type' => $scheduledMessage->message_type,
            'queue' => $scheduledMessage->queue,
            'send_at' => $sendAt->toDateTimeString(),
            'context_type' => $context ? class_basename($context) : null,
            'context_id' => $context?->getKey(),
            'behavior_owner_type' => $scheduledMessage->behavior_owner_type
                ? class_basename((string) $scheduledMessage->behavior_owner_type)
                : null,
            'behavior_owner_id' => $scheduledMessage->behavior_owner_id,
            'dispatch_keys' => $scheduledMessage->dispatch_keys,
            'definition_config_path' => $scheduledMessage->definition_config_path,
            'campaign_key' => $scheduledMessage->meta['campaign_key'] ?? null,
            'campaign_step' => $scheduledMessage->meta['campaign_step'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
