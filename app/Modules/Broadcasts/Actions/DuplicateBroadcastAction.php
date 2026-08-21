<?php

namespace App\Modules\Broadcasts\Actions;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DuplicateBroadcastAction
{
    public function handle(Broadcast $broadcast, ?User $actor = null): Broadcast
    {
        if (! $broadcast->isRegularBroadcast()) {
            throw new InvalidArgumentException('Only regular Broadcasts can be duplicated.');
        }

        return Broadcast::query()->create([
            'user_id' => $actor?->getKey(),
            'name' => Str::limit('Copy of '.$broadcast->name, 255, ''),
            'channel' => $broadcast->channel,
            'purpose' => $broadcast->purpose,
            'scope' => $broadcast->scope,
            'dispatch_key' => $broadcast->dispatch_key,
            'message_type' => $broadcast->message_type,
            'payload_class' => $broadcast->payload_class,
            'queue' => $broadcast->queue,
            'status' => Broadcast::STATUS_DRAFT,
            'send_at' => null,
            'payload' => is_array($broadcast->payload) ? $broadcast->payload : [],
            'recipient_filter' => [
                'type' => 'criteria',
                'criteria' => [],
            ],
            'recipient_count' => 0,
            'scheduled_count' => 0,
            'cancelled_at' => null,
            'completed_at' => null,
            'meta' => [
                'created_from' => 'crm',
                'broadcast_type' => Broadcast::BROADCAST_TYPE_REGULAR,
            ],
        ]);
    }
}