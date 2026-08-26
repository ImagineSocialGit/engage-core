<?php

namespace App\Modules\InboundMessaging\Actions\Inbox;

use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Models\InboundMessage;
use Illuminate\Support\Facades\DB;

final class LinkInboundMessageContactAction
{
    public function handle(
        InboundMessage $message,
        ?Contact $contact,
    ): InboundMessage {
        return DB::transaction(function () use ($message, $contact): InboundMessage {
            $message = InboundMessage::query()
                ->lockForUpdate()
                ->findOrFail($message->getKey());

            $message->forceFill([
                'related_contact_id' => $contact?->getKey(),
            ])->save();

            return $message->refresh();
        }, 3);
    }
}