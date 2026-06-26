<?php

namespace App\Modules\InternalNotifications\Services;

use App\Modules\InternalNotifications\Contracts\InternalNotificationPreferenceResolver;
use App\Modules\Messaging\Enums\MessageChannel;

class InternalNotificationChannelResolver
{
    /**
     * @param iterable<int, InternalNotificationPreferenceResolver> $preferenceResolvers
     */
    public function __construct(
        private readonly iterable $preferenceResolvers,
    ) {}

    /**
     * @param array<int, MessageChannel|string> $allowedChannels
     */
    public function resolve(
        InternalNotificationRecipient $recipient,
        ?string $notificationType = null,
        array $allowedChannels = [MessageChannel::Email, MessageChannel::Sms],
    ): ?MessageChannel {
        if (! $recipient->preferenceOwner) {
            return null;
        }

        foreach ($allowedChannels as $channel) {
            $channel = $this->normalizeChannel($channel);

            if (! $channel) {
                continue;
            }

            if (! $this->recipientHasDestinationForChannel($recipient, $channel)) {
                continue;
            }

            if ($this->preferencesAllow($recipient, $channel, $notificationType)) {
                return $channel;
            }
        }

        return null;
    }

    private function preferencesAllow(
        InternalNotificationRecipient $recipient,
        MessageChannel $channel,
        ?string $notificationType,
    ): bool {
        foreach ($this->preferenceResolvers as $resolver) {
            if (! $resolver->supports($recipient->preferenceOwner)) {
                continue;
            }

            return $resolver->allows(
                preferenceOwner: $recipient->preferenceOwner,
                channel: $channel,
                notificationType: $notificationType,
            );
        }

        return false;
    }

    private function recipientHasDestinationForChannel(
        InternalNotificationRecipient $recipient,
        MessageChannel $channel,
    ): bool {
        return match ($channel) {
            MessageChannel::Email => filled($recipient->email),
            MessageChannel::Sms => filled($recipient->phone),
        };
    }

    private function normalizeChannel(MessageChannel|string $channel): ?MessageChannel
    {
        if ($channel instanceof MessageChannel) {
            return $channel;
        }

        return MessageChannel::tryFrom(strtolower(trim($channel)));
    }
}