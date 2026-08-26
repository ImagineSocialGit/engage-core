<?php

namespace App\Modules\InboundMessaging\Actions\Inbox;

use App\Modules\InboundMessaging\Models\InboundMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdateInboundMessageInboxStateAction
{
    public function handle(
        InboundMessage $message,
        string $status,
    ): InboundMessage {
        if (! in_array($status, InboundMessage::inboxStatuses(), true)) {
            throw new InvalidArgumentException(
                "Unsupported inbound Inbox status [{$status}].",
            );
        }

        return DB::transaction(function () use ($message, $status): InboundMessage {
            $message = InboundMessage::query()
                ->lockForUpdate()
                ->findOrFail($message->getKey());

            $now = now();

            $message->forceFill(match ($status) {
                InboundMessage::INBOX_STATUS_NEW => [
                    'inbox_status' => $status,
                    'reviewed_at' => null,
                    'completed_at' => null,
                ],
                InboundMessage::INBOX_STATUS_REVIEWED => [
                    'inbox_status' => $status,
                    'reviewed_at' => $message->reviewed_at ?? $now,
                    'completed_at' => null,
                ],
                InboundMessage::INBOX_STATUS_DONE => [
                    'inbox_status' => $status,
                    'reviewed_at' => $message->reviewed_at ?? $now,
                    'completed_at' => $now,
                ],
            })->save();

            return $message->refresh();
        }, 3);
    }
}