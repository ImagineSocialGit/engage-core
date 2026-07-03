<?php

namespace App\Modules\Messaging\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Messaging\Actions\CreateContactPermissionInvitationsForImportBatchAction;
use App\Modules\Messaging\Actions\SkipScheduledMessagesAction;
use Illuminate\Http\RedirectResponse;

class ContactImportBatchPermissionInvitationController extends Controller
{
    public function __invoke(
        ContactImportBatch $contactImportBatch,
        CreateContactPermissionInvitationsForImportBatchAction $createInvitations,
    ): RedirectResponse {
        $result = $createInvitations->handle($contactImportBatch);

        return redirect()
            ->route('crm.contacts.import-batches.show', $contactImportBatch)
            ->with(
                'success',
                "{$result['scheduled']} permission invitation message(s) scheduled. {$result['skipped']} contact(s) skipped.",
            );
    }

    public function destroy(
        ContactImportBatch $contactImportBatch,
        SkipScheduledMessagesAction $skipScheduledMessages,
    ): RedirectResponse {
        $skipped = $skipScheduledMessages->importedContactPermissionInvitationsForImportBatch(
            importBatch: $contactImportBatch,
            reason: 'permission_invitation_cancelled',
        );

        return redirect()
            ->route('crm.contacts.import-batches.show', $contactImportBatch)
            ->with(
                'success',
                "{$skipped} pending permission invitation message(s) cancelled.",
            );
    }
}