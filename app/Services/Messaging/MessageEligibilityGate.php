<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\MessageConsent;
use App\Models\ConsentRevocation;
use Illuminate\Database\Eloquent\Model;

class MessageEligibilityGate
{
    public function __construct(
        private readonly MessageSuppressionService $messageSuppressionService,
    ) {}

    public function canSend(Model $recipient, MessageChannel|string $channel, MessagePurpose|string $purpose): bool
    {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizePurpose($purpose);

        $destination = $this->destinationFor($recipient, $channel);

        if (! $destination) {
            return false;
        }

        if (! $this->hasActiveConsent($recipient, $channel, $purpose)) {
            return false;
        }

        return ! $this->messageSuppressionService->isSuppressed($channel, $destination);
    }

    private function hasActiveConsent(Model $recipient, string $channel, string $purpose): bool
    {
        $latestConsent = MessageConsent::query()
            ->whereMorphedTo('recipient', $recipient)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->latest('consented_at')
            ->first();

        if (! $latestConsent) {
            return false;
        }

        return ! ConsentRevocation::query()
            ->whereMorphedTo('recipient', $recipient)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('revoked_at', '>=', $latestConsent->consented_at)
            ->exists();
    }

    private function destinationFor(Model $recipient, string $channel): ?string
    {
        return match ($channel) {
            MessageChannel::Sms->value => $recipient->phone ?? null,
            MessageChannel::Email->value => $recipient->email ?? null,
            default => null,
        };
    }

    private function normalizeChannel(MessageChannel|string $channel): string
    {
        return $channel instanceof MessageChannel
            ? $channel->value
            : strtolower(trim($channel));
    }

    private function normalizePurpose(MessagePurpose|string $purpose): string
    {
        return $purpose instanceof MessagePurpose
            ? $purpose->value
            : strtolower(trim($purpose));
    }
}