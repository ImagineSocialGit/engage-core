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
        $flowRoute = $this->flowRouteProvenance($meta);

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
            ...$flowRoute,
        ];

        if ($context) {
            $attributes['context_type'] = $context->getMorphClass();
            $attributes['context_id'] = $context->getKey();
        }

        $scheduledMessage = $dedupeKey
            ? ScheduledMessage::query()->firstOrCreate(['dedupe_key' => $dedupeKey], $attributes + ['dedupe_key' => $dedupeKey])
            : ScheduledMessage::query()->create($attributes);

        if ($scheduledMessage->wasRecentlyCreated) {
            $dispatch = SendScheduledMessageJob::dispatch(
                scheduledMessageId: $scheduledMessage->id,
                horizon: $this->horizonPayload($scheduledMessage, $sendAt, $context),
            )->delay($sendAt)->afterCommit();

            if ($queue !== null) $dispatch->onQueue($queue);
        }

        return $scheduledMessage;
    }

    private function flowRouteProvenance(array $meta): array
    {
        $flowRoute = is_array($meta['flow_route'] ?? null) ? $meta['flow_route'] : [];
        return [
            'flow_route_progress_id' => $this->nullableInt($flowRoute['flow_route_progress_id'] ?? null),
            'flow_route_plan_id' => $this->nullableInt($flowRoute['flow_route_plan_id'] ?? null),
            'flow_route_plan_item_id' => $this->nullableInt($flowRoute['flow_route_plan_item_id'] ?? null),
            'flow_route_progress_item_id' => $this->nullableInt($flowRoute['flow_route_progress_item_id'] ?? null),
            'flow_route_id' => $this->nullableInt($flowRoute['flow_route_id'] ?? null),
            'flow_route_point_id' => $this->nullableInt($flowRoute['flow_route_point_id'] ?? null),
            'flow_route_capability_id' => $this->nullableInt($flowRoute['flow_route_capability_id'] ?? null),
        ];
    }

    private function horizonPayload(ScheduledMessage $scheduledMessage, Carbon $sendAt, ?Model $context): array
    {
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
            'dispatch_keys' => $scheduledMessage->dispatch_keys,
            'definition_config_path' => $scheduledMessage->definition_config_path,
            'campaign_key' => $scheduledMessage->meta['campaign_key'] ?? null,
            'campaign_step' => $scheduledMessage->meta['campaign_step'] ?? null,
            'flow_route_progress_id' => $scheduledMessage->flow_route_progress_id,
            'flow_route_plan_id' => $scheduledMessage->flow_route_plan_id,
            'flow_route_plan_item_id' => $scheduledMessage->flow_route_plan_item_id,
            'flow_route_progress_item_id' => $scheduledMessage->flow_route_progress_item_id,
            'flow_route_id' => $scheduledMessage->flow_route_id,
            'flow_route_point_id' => $scheduledMessage->flow_route_point_id,
            'flow_route_capability_id' => $scheduledMessage->flow_route_capability_id,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function normalizeDispatchKeys(mixed $dispatchKeys): array
    {
        if (! is_array($dispatchKeys)) return [];
        return array_values(array_unique(array_filter(array_map(
            fn (mixed $dispatchKey): ?string => is_string($dispatchKey) && trim($dispatchKey) !== '' ? $this->normalizeSegment($dispatchKey) : null,
            $dispatchKeys,
        ))));
    }

    private function normalizeEnumValue(MessageChannel|MessagePurpose|string $value): string { return $value instanceof MessageChannel || $value instanceof MessagePurpose ? $value->value : strtolower(trim($value)); }
    private function normalizeSegment(string $value): string { return str_replace('-', '_', strtolower(trim($value))); }
    private function nullableString(mixed $value): ?string { return is_string($value) && trim($value) !== '' ? trim($value) : null; }
    private function nullableInt(mixed $value): ?int { return is_numeric($value) ? (int) $value : null; }
}

