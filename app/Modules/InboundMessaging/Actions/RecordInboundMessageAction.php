<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use Illuminate\Database\Eloquent\Model;

class RecordInboundMessageAction
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?Model $sender = null): InboundMessage
    {
        $inboundMessage = new InboundMessage([
            'client_key' => $data['client_key'] ?? config('client.key'),
            'channel' => $data['channel'],
            'provider' => $data['provider'],

            'provider_event_id' => $data['provider_event_id'] ?? null,
            'provider_message_id' => $data['provider_message_id'] ?? null,
            'provider_context_id' => $data['provider_context_id'] ?? null,

            'from_type' => $data['from_type'] ?? null,
            'from_value' => $data['from_value'] ?? null,
            'to_type' => $data['to_type'] ?? null,
            'to_value' => $data['to_value'] ?? null,

            'body' => $data['body'] ?? null,

            'classification' => $data['classification'],
            'purpose' => $data['purpose'] ?? null,
            'scope' => $data['scope'] ?? null,

            'received_at' => $data['received_at'] ?? null,
            'processed_at' => $data['processed_at'] ?? null,

            'meta' => $data['meta'] ?? null,
        ]);

        if ($sender) {
            $inboundMessage->sender()->associate($sender);
        }

        $inboundMessage->save();

        event(new InboundMessageReceived($inboundMessage));

        return $inboundMessage;
    }
}