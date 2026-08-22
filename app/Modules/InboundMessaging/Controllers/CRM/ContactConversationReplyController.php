<?php

namespace App\Modules\InboundMessaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\InboundMessaging\Actions\SendContactConversationReplyAction;
use App\Modules\InboundMessaging\Models\InboundMessage;
use App\Modules\InboundMessaging\Requests\StoreContactConversationReplyRequest;
use Illuminate\Http\RedirectResponse;

class ContactConversationReplyController extends Controller
{
    public function __invoke(
        StoreContactConversationReplyRequest $request,
        Contact $contact,
        InboundMessage $inboundMessage,
        SendContactConversationReplyAction $sendReply,
    ): RedirectResponse {
        abort_unless($this->belongsToContact($inboundMessage, $contact), 404);
        abort_unless(
            $inboundMessage->classification === InboundMessage::CLASSIFICATION_NORMAL_REPLY,
            404,
        );

        $sendReply->handle(
            contact: $contact,
            inboundMessage: $inboundMessage->loadMissing('correlatedScheduledMessage'),
            body: (string) $request->validated('reply_body'),
            requestKey: (string) $request->validated('reply_request_key'),
            subject: $request->validated('reply_subject'),
        );

        return redirect()
            ->to(route('crm.contacts.show', $contact).'#contact-conversation')
            ->with('success', 'Reply queued for delivery.');
    }

    private function belongsToContact(
        InboundMessage $inboundMessage,
        Contact $contact,
    ): bool {
        return (int) $inboundMessage->sender_id === (int) $contact->getKey()
            && in_array($inboundMessage->sender_type, [
                Contact::class,
                $contact->getMorphClass(),
            ], true);
    }
}