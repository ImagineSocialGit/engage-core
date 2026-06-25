<?php

namespace App\Actions\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ScheduleMessageAction
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $meta
     */
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
        ?string $dedupeKey = null,
        ?array $meta = null,
    ): ScheduledMessage {
        $channel = $this->normalizeEnumValue($channel);
        $purpose = $this->normalizeEnumValue($purpose);
        $scope = $this->normalizeSegment($scope);
        $messageType = $this->normalizeSegment($messageType);
        $sendAt = $sendAt ? Carbon::parse($sendAt) : now();
        $meta ??= [];

        $attributes = [
            'recipient_type' => $recipient->getMorphClass(),
            'recipient_id' => $recipient->getKey(),
            'channel' => $channel,
            'message_type' => $messageType,
            'purpose' => $purpose,
            'scope' => $scope,
            'payload_class' => $payloadClass,
            'payload' => $payload,
            'send_at' => $sendAt,
            'status' => ScheduledMessage::STATUS_PENDING,
            'meta' => $meta,
        ];

        if ($context) {
            $attributes['context_type'] = $context->getMorphClass();
            $attributes['context_id'] = $context->getKey();
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
                horizon: $this->horizonPayload(
                    scheduledMessage: $scheduledMessage,
                    sendAt: $sendAt,
                    context: $context,
                    meta: $meta,
                ),
            )
                ->delay($sendAt)
                ->afterCommit();

            if ($queue = $meta['queue'] ?? null) {
                $dispatch->onQueue($queue);
            }
        }

        return $scheduledMessage;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function horizonPayload(
        ScheduledMessage $scheduledMessage,
        Carbon $sendAt,
        ?Model $context,
        array $meta,
    ): array {
        return array_filter([
            'scheduled_message_id' => $scheduledMessage->id,
            'recipient_type' => class_basename((string) $scheduledMessage->recipient_type),
            'recipient_id' => $scheduledMessage->recipient_id,
            'channel' => $scheduledMessage->channel,
            'purpose' => $scheduledMessage->purpose,
            'scope' => $scheduledMessage->scope,
            'message_type' => $scheduledMessage->message_type,
            'queue' => $meta['queue'] ?? null,
            'send_at' => $sendAt->toDateTimeString(),
            'context_type' => $context ? class_basename($context) : null,
            'context_id' => $context?->getKey(),
            'dispatch_keys' => $meta['dispatch_keys'] ?? null,
            'definition_config_path' => $meta['definition_config_path'] ?? null,
            'campaign_key' => $meta['campaign_key'] ?? null,
            'campaign_step' => $meta['campaign_step'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
}