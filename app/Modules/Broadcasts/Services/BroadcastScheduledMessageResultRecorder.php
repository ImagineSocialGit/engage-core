<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Data\Delivery\ScheduledMessageTerminalResult;
use App\Modules\Messaging\Models\ScheduledMessage;

class BroadcastScheduledMessageResultRecorder
{
    public function recordSent(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageTerminalResult $terminalResult,
    ): void {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_SENT
            || ! $terminalResult->isSent()
        ) {
            return;
        }

        $broadcast = $this->resolveBroadcast($scheduledMessage);

        if (! $broadcast) {
            return;
        }

        $recipient = $this->resolveRecipient($broadcast, $scheduledMessage);

        if (! $recipient) {
            return;
        }

        if ($this->isOpenRecipient($recipient)) {
            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_SENT,
                'sent_at' => $terminalResult->occurredAt,
                'terminal_reason' => null,
                'meta' => $this->withoutDeliverySnapshot($recipient->meta ?? []),
            ])->save();
        }

        $this->completeBroadcastWhenFinished($broadcast);
    }

    public function recordSkipped(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageTerminalResult $terminalResult,
    ): void {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_SKIPPED
            || ! $terminalResult->isSkipped()
        ) {
            return;
        }

        $broadcast = $this->resolveBroadcast($scheduledMessage);

        if (! $broadcast) {
            return;
        }

        $recipient = $this->resolveRecipient($broadcast, $scheduledMessage);

        if (! $recipient) {
            return;
        }

        if ($this->isOpenRecipient($recipient)) {
            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_SKIPPED,
                'sent_at' => null,
                'terminal_reason' => $this->terminalReason(
                    terminalResult: $terminalResult,
                    fallback: 'scheduled_message_skipped',
                ),
                'meta' => $this->withoutDeliverySnapshot($recipient->meta ?? []),
            ])->save();
        }

        $this->completeBroadcastWhenFinished($broadcast);
    }

    public function recordFailed(
        ScheduledMessage $scheduledMessage,
        ScheduledMessageTerminalResult $terminalResult,
    ): void {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_FAILED
            || ! $terminalResult->isFailed()
        ) {
            return;
        }

        $broadcast = $this->resolveBroadcast($scheduledMessage);

        if (! $broadcast) {
            return;
        }

        $recipient = $this->resolveRecipient($broadcast, $scheduledMessage);

        if (! $recipient) {
            return;
        }

        if ($this->isOpenRecipient($recipient)) {
            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_FAILED,
                'sent_at' => null,
                'terminal_reason' => $this->terminalReason(
                    terminalResult: $terminalResult,
                    fallback: 'scheduled_message_failed',
                ),
                'meta' => $this->withoutDeliverySnapshot($recipient->meta ?? []),
            ])->save();
        }

        $this->completeBroadcastWhenFinished($broadcast);
    }

    private function resolveBroadcast(ScheduledMessage $scheduledMessage): ?Broadcast
    {
        if ($scheduledMessage->context_type !== (new Broadcast())->getMorphClass()) {
            return null;
        }

        if ($scheduledMessage->recipient_type !== (new Contact())->getMorphClass()) {
            return null;
        }

        return Broadcast::query()->find($scheduledMessage->context_id);
    }

    private function resolveRecipient(
        Broadcast $broadcast,
        ScheduledMessage $scheduledMessage,
    ): ?BroadcastRecipient {
        $broadcastRecipientId = $scheduledMessage->meta['broadcast_recipient_id'] ?? null;

        if (is_numeric($broadcastRecipientId)) {
            $recipient = BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->whereKey((int) $broadcastRecipientId)
                ->first();

            if ($recipient) {
                return $recipient;
            }
        }

        return BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->getKey())
            ->where('contact_id', $scheduledMessage->recipient_id)
            ->first();
    }

    private function isOpenRecipient(BroadcastRecipient $recipient): bool
    {
        return in_array($recipient->status, [
            BroadcastRecipient::STATUS_PENDING,
            BroadcastRecipient::STATUS_SCHEDULED,
        ], true);
    }

    private function terminalReason(
        ScheduledMessageTerminalResult $terminalResult,
        string $fallback,
    ): string {
        $reason = is_string($terminalResult->reason)
            ? trim($terminalResult->reason)
            : '';

        if ($reason !== '') {
            return mb_substr($reason, 0, 255);
        }

        $reasonCode = is_string($terminalResult->reasonCode)
            ? trim($terminalResult->reasonCode)
            : '';

        return mb_substr(
            $reasonCode !== '' ? $reasonCode : $fallback,
            0,
            255,
        );
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function withoutDeliverySnapshot(array $meta): array
    {
        unset($meta['delivery']);

        return $meta;
    }

    private function completeBroadcastWhenFinished(Broadcast $broadcast): void
    {
        if (! in_array($broadcast->status, [
            Broadcast::STATUS_SCHEDULED,
            Broadcast::STATUS_SENDING,
        ], true)) {
            return;
        }

        $hasOpenRecipients = BroadcastRecipient::query()
            ->where('broadcast_id', $broadcast->getKey())
            ->whereIn('status', [
                BroadcastRecipient::STATUS_PENDING,
                BroadcastRecipient::STATUS_SCHEDULED,
            ])
            ->exists();

        if ($hasOpenRecipients) {
            return;
        }

        $broadcast->forceFill([
            'status' => Broadcast::STATUS_COMPLETED,
            'completed_at' => now(),
        ])->save();
    }
}