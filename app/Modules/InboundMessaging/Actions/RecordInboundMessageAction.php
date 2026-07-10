<?php

namespace App\Modules\InboundMessaging\Actions;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Events\InboundMessageReceived;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Events\AutomationEventRecorded;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;

class RecordInboundMessageAction
{
    public const NORMAL_REPLY_AUTOMATION_EVENT_KEY = 'inbound_message.normal_reply';

    /**
     * @param array<string, mixed> $data
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

        $this->emitNormalReplyAutomationEvent(
            inboundMessage: $inboundMessage,
            sender: $sender,
        );

        return $inboundMessage;
    }

    private function emitNormalReplyAutomationEvent(
        InboundMessage $inboundMessage,
        ?Model $sender,
    ): void {
        if (! $sender instanceof Contact
            || $inboundMessage->classification !== InboundMessage::CLASSIFICATION_NORMAL_REPLY
        ) {
            return;
        }

        event(new AutomationEventRecorded(
            AutomationEventData::forSubject(
                eventKey: self::NORMAL_REPLY_AUTOMATION_EVENT_KEY,
                subject: $inboundMessage,
                contactId: $sender->getKey(),
                occurredAt: $inboundMessage->received_at,
                payload: [
                    'inbound_message' => [
                        'id' => $inboundMessage->getKey(),
                        'channel' => $this->value($inboundMessage->channel),
                        'classification' => $inboundMessage->classification,
                        'purpose' => $this->value($inboundMessage->purpose),
                        'scope' => $inboundMessage->scope,
                        'received_at' => $inboundMessage->received_at?->toISOString(),
                    ],
                ],
                meta: [
                    'source_module' => 'inbound_messaging',
                    'source' => 'inbound_message_received',
                ],
            ),
        ));
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }
}
