<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\ContactImportBatch;
use Illuminate\View\View;

class ContactImportBatchController extends Controller
{
    public function index(): View
    {
        $importBatches = ContactImportBatch::query()
            ->withCount('contacts')
            ->latest('imported_at')
            ->latest()
            ->paginate(20);

        return view('crm.contacts.import-batches.index', [
            'importBatches' => $importBatches,
        ]);
    }

    public function show(ContactImportBatch $contactImportBatch): View
    {
        $contactImportBatch->loadCount('contacts');

        $contactsQuery = $contactImportBatch->contacts()
            ->latest();

        if (module_enabled('messaging')) {
            $contactsQuery->with([
                'messageConsents',
                'permissionInvitations',
                'scheduledMessages',
            ]);
        }

        $contacts = $contactsQuery->paginate(50);

        return view('crm.contacts.import-batches.show', [
            'importBatch' => $contactImportBatch,
            'contacts' => $contacts,
        ]);
    }
}