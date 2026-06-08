<?php

namespace App\Actions\Messaging;

use App\Jobs\Messaging\SendScheduledMessageJob;
use App\Models\ScheduledMessage;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DispatchMessageAction
{
    public function handle(
        Contact $contact,
        string $channel,
        string $messageType,
        string $purpose,
        string $scope,
        string $payloadClass,
        array $payload,
        Carbon|string|null $sendAt = null,
        ?Model $context = null,
        ?string $dedupeKey = null,
        ?array $meta = null,
    ): ScheduledMessage {
        $sendAt = $sendAt ? Carbon::parse($sendAt) : now();

        $attributes = [
            'contact_id' => $contact->getKey(),
            'channel' => $channel,
            'message_type' => $messageType,
            'purpose' => $purpose,
            'scope' => $scope,
            'payload_class' => $payloadClass,
            'payload' => $payload,
            'send_at' => $sendAt,
            'status' => 'pending',
            'meta' => $meta,
        ];

        if ($context) {
            $attributes['context_type'] = $context->getMorphClass();
            $attributes['context_id'] = $context->getKey();
        }

        if ($dedupeKey) {
            $scheduledMessage = ScheduledMessage::query()->firstOrCreate(
                ['dedupe_key' => $dedupeKey],
                $attributes + ['dedupe_key' => $dedupeKey],
            );
        } else {
            $scheduledMessage = ScheduledMessage::query()->create($attributes);
        }

        if ($scheduledMessage->wasRecentlyCreated) {
            $dispatch = SendScheduledMessageJob::dispatch($scheduledMessage->id)
                ->delay($sendAt);

            if ($queue = $meta['queue'] ?? null) {
                $dispatch->onQueue($queue);
            }
        }

        return $scheduledMessage;
    }
}