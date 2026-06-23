<?php

namespace App\Actions\Messaging\Internal;

use App\Actions\Messaging\ScheduleMessageAction;
use App\Enums\MessageChannel;
use App\Messaging\Payloads\Internal\InternalEmailNotificationPayload;
use App\Messaging\Payloads\Internal\InternalSmsNotificationPayload;
use App\Models\ScheduledMessage;
use App\Services\Messaging\InternalNotificationChannelResolver;
use App\Services\Messaging\InternalNotificationRecipient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ScheduleInternalNotificationAction
{
    public function __construct(
        private readonly InternalNotificationChannelResolver $channelResolver,
        private readonly ScheduleMessageAction $scheduleMessageAction,
    ) {}

    /**
     * @param array<string, mixed> $content
     * @param array<string, mixed>|null $meta
     * @param array<int, MessageChannel|string> $allowedChannels
     */
    public function handle(
        InternalNotificationRecipient $recipient,
        string $scope,
        string $messageType,
        array $content,
        ?Model $context = null,
        Carbon|string|null $sendAt = null,
        ?string $dedupeKey = null,
        ?array $meta = null,
        array $allowedChannels = [MessageChannel::Email, MessageChannel::Sms],
    ): ?ScheduledMessage {
        $channel = $this->channelResolver->resolve(
            recipient: $recipient,
            notificationType: $recipient->notificationType,
            allowedChannels: $allowedChannels,
        );

        if (! $channel) {
            return null;
        }

        $destination = $this->destinationForChannel($recipient, $channel);

        if (! $destination) {
            return null;
        }

        $payload = array_replace_recursive(
            $content,
            [
                'to' => $destination,
                'channel' => $channel->value,
                'purpose' => 'internal',
                'scope' => $scope,
                'message_type' => $messageType,
                'notification_type' => $recipient->notificationType,
            ],
        );

        $meta = array_replace_recursive(
            [
                'queue' => 'notifications',
                'notification_type' => $recipient->notificationType,
                'recipient_source_type' => $recipient->source->getMorphClass(),
                'recipient_source_id' => $recipient->source->getKey(),
            ],
            $meta ?? [],
        );

        return $this->scheduleMessageAction->handle(
            recipient: $recipient->source,
            channel: $channel,
            purpose: 'internal',
            scope: $scope,
            messageType: $messageType,
            payloadClass: $this->payloadClassForChannel($channel),
            payload: $payload,
            sendAt: $sendAt,
            context: $context,
            dedupeKey: $dedupeKey,
            meta: $meta,
        );
    }

    private function destinationForChannel(
        InternalNotificationRecipient $recipient,
        MessageChannel $channel,
    ): ?string {
        return match ($channel) {
            MessageChannel::Email => $recipient->email,
            MessageChannel::Sms => $recipient->phone,
        };
    }

    private function payloadClassForChannel(MessageChannel $channel): string
    {
        return match ($channel) {
            MessageChannel::Email => InternalEmailNotificationPayload::class,
            MessageChannel::Sms => InternalSmsNotificationPayload::class,
            default => throw new InvalidArgumentException("Unsupported internal notification channel [{$channel->value}]."),
        };
    }
}