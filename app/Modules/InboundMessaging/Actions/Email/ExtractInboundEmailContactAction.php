<?php

namespace App\Modules\InboundMessaging\Actions\Email;

use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Services\Email\InboundEmailContactExtractor;
use Illuminate\Support\Facades\DB;

final class ExtractInboundEmailContactAction
{
    public function __construct(
        private readonly InboundEmailContactExtractor $extractor,
        private readonly ResolveContactByEmailAction $resolveContactByEmail,
        private readonly CreateOrUpdateContactAction $createOrUpdateContact,
        private readonly RecordInboundEmailRouteAutomationEventAction $recordRouteAutomationEvent,
    ) {}

    public function handle(
        InboundMessage $message,
        InboundEmailRoute $route,
    ): InboundMessage {
        if (! $route->contact_extraction_enabled) {
            return $message;
        }

        return DB::transaction(function () use ($message, $route): InboundMessage {
            $message = InboundMessage::query()
                ->lockForUpdate()
                ->findOrFail($message->getKey());

            if ($message->contact_extraction_status !== null) {
                $this->recordRouteAutomationEvent->handle(
                    message: $message,
                    contact: $message->relatedContact,
                    idempotencySuffix: 'contact_extraction',
                );

                return $message->refresh();
            }

            $definition = is_array($route->contact_extraction_definition)
                ? $route->contact_extraction_definition
                : [];
            $hash = $this->extractor->definitionHash($definition);
            $result = $this->extractor->extract(
                source: [
                    'sender_email' => $message->from_value,
                    'reply_to_email' => $message->reply_to_value,
                    'subject' => $message->subject,
                    'body' => $message->body,
                ],
                definition: $definition,
            );

            if (! $result['ok']) {
                $message->forceFill([
                    'contact_extraction_status' =>
                        InboundMessage::CONTACT_EXTRACTION_FAILED,
                    'contact_extraction_definition_hash' => $hash,
                    'contact_extraction_error' => mb_substr(
                        implode(' ', $result['errors']),
                        0,
                        500,
                    ),
                    'contact_extraction_attempted_at' => now(),
                ])->save();

                $this->recordRouteAutomationEvent->handle(
                    message: $message,
                    contact: null,
                    idempotencySuffix: 'contact_extraction',
                );

                return $message->refresh();
            }

            $values = $result['values'];
            $email = (string) $values['email'];
            $name = $values['name'] ?? null;
            $phone = $values['phone'] ?? null;

            $this->resolveContactByEmail->handle(
                email: $email,
                name: is_string($name) ? $name : null,
                phone: is_string($phone) ? $phone : null,
                source: 'inbound_messaging',
                subsource: (string) $route->key,
            );

            $contact = $this->createOrUpdateContact->handle([
                'email' => $email,
                'first_name' => $values['first_name'] ?? null,
                'last_name' => $values['last_name'] ?? null,
                'name' => $values['name'] ?? null,
                'phone' => $values['phone'] ?? null,
            ]);

            $message->forceFill([
                'related_contact_id' => $contact->getKey(),
                'contact_extraction_status' =>
                    InboundMessage::CONTACT_EXTRACTION_SUCCEEDED,
                'contact_extraction_definition_hash' => $hash,
                'contact_extraction_error' => null,
                'contact_extraction_attempted_at' => now(),
            ])->save();

            $this->recordRouteAutomationEvent->handle(
                message: $message,
                contact: $contact,
                idempotencySuffix: 'contact_extraction',
            );

            return $message->refresh();
        }, 3);
    }
}