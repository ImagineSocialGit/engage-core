<?php

namespace App\Modules\Broadcasts\Actions;

use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use Illuminate\Support\Facades\DB;

class CancelBroadcastAction
{
    public function __construct(
        private readonly SkipScheduledMessagesAction $skipScheduledMessagesAction,
    ) {}

    public function handle(Broadcast $broadcast, ?string $reason = null): Broadcast
    {
        return DB::transaction(function () use ($broadcast, $reason): Broadcast {
            $reason = $this->normalizeReason($reason);

            $skippedMessageCount = $this->skipScheduledMessagesAction->forContext(
                context: $broadcast,
                reason: $reason,
            );

            BroadcastRecipient::query()
                ->where('broadcast_id', $broadcast->getKey())
                ->whereIn('status', [
                    BroadcastRecipient::STATUS_PENDING,
                    BroadcastRecipient::STATUS_SCHEDULED,
                ])
                ->update([
                    'status' => BroadcastRecipient::STATUS_CANCELLED,
                    'skip_reason' => $reason,
                    'updated_at' => now(),
                ]);

            $broadcast->forceFill([
                'status' => Broadcast::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'meta' => array_replace_recursive($broadcast->meta ?? [], [
                    'cancellation' => [
                        'reason' => $reason,
                        'skipped_scheduled_message_count' => $skippedMessageCount,
                        'cancelled_at' => now()->toISOString(),
                    ],
                ]),
            ])->save();

            return $broadcast->refresh();
        });
    }

    private function normalizeReason(?string $reason): string
    {
        $reason = is_string($reason) ? trim($reason) : '';

        return $reason !== '' ? $reason : 'broadcast_cancelled';
    }
}