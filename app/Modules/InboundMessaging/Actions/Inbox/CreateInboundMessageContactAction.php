<?php

namespace App\Modules\InboundMessaging\Actions\Inbox;

use App\Modules\Core\Actions\Contacts\ResolveContactByEmailAction;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use BackedEnum;

final class CreateInboundMessageContactAction
{
    public function __construct(
        private readonly ResolveContactByEmailAction $resolveContactByEmail,
        private readonly LinkInboundMessageContactAction $linkInboundMessageContact,
    ) {}

    public function handle(
        InboundMessage $message,
        string $email,
        ?string $name = null,
        ?string $phone = null,
    ): Contact {
        $contact = $this->resolveContactByEmail->handle(
            email: $email,
            name: $name,
            phone: $phone,
            source: 'inbound_messaging',
            subsource: $message->inbound_email_route_key
                ?: $this->channelValue($message->channel),
        );

        $this->linkInboundMessageContact->handle(
            message: $message,
            contact: $contact,
        );

        return $contact;
    }

    private function channelValue(mixed $channel): string
    {
        return $channel instanceof BackedEnum
            ? (string) $channel->value
            : trim((string) $channel);
    }
}