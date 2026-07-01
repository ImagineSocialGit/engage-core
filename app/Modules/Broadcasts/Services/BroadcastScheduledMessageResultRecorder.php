<?php

namespace App\Modules\Broadcasts\Services;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Models\ScheduledMessage;

class BroadcastScheduledMessageResultRecorder
{
    public function recordSent(ScheduledMessage $scheduledMessage): void
    {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_SENT) {
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
            $sentAt = $scheduledMessage->sent_at ?? now();

            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_SENT,
                'sent_at' => $sentAt,
                'skip_reason' => null,
                'meta' => array_replace_recursive($recipient->meta ?? [], [
                    'delivery' => [
                        'sent_at' => $sentAt->toISOString(),
                        'scheduled_message_id' => $scheduledMessage->getKey(),
                    ],
                ]),
            ])->save();
        }

        $this->completeBroadcastWhenFinished($broadcast);
    }

    public function recordSkipped(ScheduledMessage $scheduledMessage): void
    {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_SKIPPED) {
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
            $skippedAt = $scheduledMessage->skipped_at ?? now();
            $skipReason = $scheduledMessage->skip_reason ?: 'scheduled_message_skipped';

            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_SKIPPED,
                'skip_reason' => $skipReason,
                'meta' => array_replace_recursive($recipient->meta ?? [], [
                    'delivery' => [
                        'skipped_at' => $skippedAt->toISOString(),
                        'scheduled_message_id' => $scheduledMessage->getKey(),
                        'skip_reason' => $skipReason,
                    ],
                ]),
            ])->save();
        }

        $this->completeBroadcastWhenFinished($broadcast);
    }

    public function recordFailed(ScheduledMessage $scheduledMessage): void
    {
        if ($scheduledMessage->status !== ScheduledMessage::STATUS_FAILED) {
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
            $failedAt = $scheduledMessage->failed_at ?? now();

            $recipient->forceFill([
                'status' => BroadcastRecipient::STATUS_FAILED,
                'skip_reason' => null,
                'meta' => array_replace_recursive($recipient->meta ?? [], [
                    'delivery' => [
                        'failed_at' => $failedAt->toISOString(),
                        'scheduled_message_id' => $scheduledMessage->getKey(),
                        'failure_reason' => $scheduledMessage->failure_reason,
                    ],
                ]),
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