<?php

namespace App\Services\Messaging;

use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class MessageEligibilityGate
{
    public function __construct(
        private readonly MessageSuppressionService $messageSuppressionService,
    ) {}

    public function canSend(Lead $lead, MessageChannel|string $channel, MessagePurpose|string $purpose): bool
    {
        $channel = $this->normalizeChannel($channel);
        $purpose = $this->normalizePurpose($purpose);

        $destination = $this->destinationFor($lead, $channel);

        if (! $destination) {
            return false;
        }

        if (! $this->hasActiveConsent($lead, $channel, $purpose)) {
            return false;
        }

        if ($this->messageSuppressionService->isSuppressed($channel, $destination)) {
            return false;
        }

        return true;
    }

    private function hasActiveConsent(Lead $lead, string $channel, string $purpose): bool
    {
        $latestConsentAt = DB::table('message_consents')
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->value('consented_at');

        if (! $latestConsentAt) {
            return false;
        }

        return ! DB::table('consent_revocations')
            ->where('lead_id', $lead->id)
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->where('revoked_at', '>=', $latestConsentAt)
            ->exists();
    }

    private function destinationFor(Lead $lead, string $channel): ?string
    {
        return match ($channel) {
            MessageChannel::Sms->value => $lead->phone,
            MessageChannel::Email->value => $lead->email,
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