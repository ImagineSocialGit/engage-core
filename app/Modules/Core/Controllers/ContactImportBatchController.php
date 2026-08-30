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
            ->with('run')
            ->latest('imported_at')
            ->latest()
            ->paginate(20);

        foreach ($importBatches as $importBatch) {
            $importBatch->setAttribute(
                'contacts_count',
                $importBatch->importedContactsQuery()->count(),
            );
        }

        return view('crm.contacts.import-batches.index', [
            'importBatches' => $importBatches,
        ]);
    }

    public function show(ContactImportBatch $contactImportBatch): View
    {
        $contactImportBatch->load('run');

        $contacts = $contactImportBatch->importedContactsQuery()
            ->latest()
            ->paginate(50);

        $contactImportBatch->setAttribute(
            'contacts_count',
            $contacts->total(),
        );

        return view('crm.contacts.import-batches.show', [
            'importBatch' => $contactImportBatch,
            'contacts' => $contacts,
        ]);
    }
}