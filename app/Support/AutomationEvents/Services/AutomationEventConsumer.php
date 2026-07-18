<?php

namespace App\Support\AutomationEvents\Services;

use App\Support\AutomationEvents\Models\AutomationEventConsumerReceipt;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AutomationEventConsumer
{
    public function consume(
        string $eventId,
        string $consumer,
        Closure $effect,
    ): bool {
        $eventId = trim($eventId);
        $consumer = trim($consumer);

        if (! Str::isUuid($eventId)) {
            throw new InvalidArgumentException('A durable automation event must have a UUID event identity.');
        }

        if ($consumer === '') {
            throw new InvalidArgumentException('An automation event consumer name is required.');
        }

        return DB::transaction(function () use ($eventId, $consumer, $effect): bool {
            AutomationEventConsumerReceipt::query()->insertOrIgnore([
                'event_id' => $eventId,
                'consumer' => $consumer,
                'completed_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $receipt = AutomationEventConsumerReceipt::query()
                ->where('event_id', $eventId)
                ->where('consumer', $consumer)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->completed_at !== null) {
                return false;
            }

            $effect();

            $receipt->forceFill([
                'completed_at' => now(),
            ])->save();

            return true;
        }, 3);
    }
}