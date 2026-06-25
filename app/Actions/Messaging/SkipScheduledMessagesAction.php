<?php

namespace App\Actions\Messaging;

use App\Models\ScheduledMessage;
use Illuminate\Database\Eloquent\Model;

class SkipScheduledMessagesAction
{
    public function forContext(Model $context, ?string $reason = null): int
    {
        return ScheduledMessage::query()
            ->where('context_type', $context->getMorphClass())
            ->where('context_id', $context->getKey())
            ->where('status', ScheduledMessage::STATUS_PENDING)
            ->update([
                'status' => ScheduledMessage::STATUS_SKIPPED,
                'skipped_at' => now(),
                'failure_reason' => $reason,
            ]);
    }
}