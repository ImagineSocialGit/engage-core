<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Actions\SendContactDirectMessageAction;
use App\Modules\Messaging\Requests\SendContactDirectMessageRequest;
use Illuminate\Http\RedirectResponse;

final class ContactDirectMessageController extends Controller
{
    public function store(
        SendContactDirectMessageRequest $request,
        Contact $contact,
        SendContactDirectMessageAction $sendContactDirectMessage,
    ): RedirectResponse {
        $sendContactDirectMessage->handle(
            contact: $contact,
            requestKey: $request->requestKey(),
            channel: $request->channel(),
            purpose: $request->purpose(),
            subject: $request->subject(),
            body: $request->body(),
            message: $request->message(),
            templatePresetId: $request->templatePresetId(),
            mediaSubmitted: $request->hasMessageMediaSubmission('direct_message'),
            mediaUpload: $request->messageMediaUpload('direct_message'),
            mediaAssetUuid: $request->messageMediaAssetUuid('direct_message'),
            mediaPosterAssetUuid: $request->messageMediaPosterAssetUuid('direct_message'),
            mediaTitle: $request->messageMediaTitle('direct_message'),
            mediaSize: $request->messageMediaSize('direct_message'),
            attachmentValues: $request->messageAttachmentValues('direct_message'),
            attachmentUpload: $request->messageAttachmentUpload('direct_message'),
            attachmentUploadSource: $request->messageAttachmentUploadSource('direct_message'),
            actor: $request->user(),
        );

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('status', 'Message queued to send.');
    }
}