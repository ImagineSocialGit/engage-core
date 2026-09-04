<?php

namespace App\Modules\InboundMessaging\Actions\Email;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\RecordInboundMessageAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Support\AutomationEvents\Data\AutomationEventData;
use App\Support\AutomationEvents\Services\AutomationEventOutbox;
use BackedEnum;

final class RecordInboundEmailRouteAutomationEventAction
{
    public function __construct(
        private readonly AutomationEventOutbox $automationEventOutbox,
    ) {}

    public function handle(
        InboundMessage $message,
        ?Contact $contact = null,
        ?string $idempotencySuffix = null,
    ): void {
        if ($this->value($message->channel) !== 'email'
            || $this->nullableString($message->inbound_email_route_key) === null
        ) {
            return;
        }

        $keyParts = [
            'inbound_messaging',
            RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY,
            $message->getKey(),
        ];

        if (is_string($idempotencySuffix)
            && trim($idempotencySuffix) !== ''
        ) {
            $keyParts[] = trim($idempotencySuffix);
        }

        $this->automationEventOutbox->record(
            event: AutomationEventData::forSubject(
                eventKey: RecordInboundMessageAction::ROUTED_EMAIL_AUTOMATION_EVENT_KEY,
                subject: $message,
                contactId: $contact?->getKey(),
                occurredAt: $message->received_at,
                payload: [
                    'inbound_message' => [
                        'id' => $message->getKey(),
                        'channel' => $this->value($message->channel),
                        'classification' => $message->classification,
                        'purpose' => $this->value($message->purpose),
                        'scope' => $message->scope,
                        'inbound_email_route_key' =>
                            $message->inbound_email_route_key,
                        'inbound_email_route_source' =>
                            $message->inbound_email_route_source,
                        'inbound_email_route_context' =>
                            $message->inbound_email_route_context,
                        'received_at' => $message->received_at?->toISOString(),
                    ],
                ],
                meta: [
                    'source_module' => 'inbound_messaging',
                    'source' => 'inbound_email_route',
                ],
            ),
            idempotencyKey: implode(':', $keyParts),
        );
    }

    private function value(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}