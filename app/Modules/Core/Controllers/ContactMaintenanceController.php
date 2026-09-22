<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\NormalizeContactsAction;
use App\Modules\Core\Services\Contacts\ContactDuplicateInspector;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

final class ContactMaintenanceController extends Controller
{
    public function index(
        NormalizeContactsAction $normalizeContacts,
        ContactDuplicateInspector $duplicates,
    ): View {
        return view('crm.settings.contact-maintenance', [
            'normalization' => $normalizeContacts->inspect(),
            'duplicates' => $duplicates->inspect(),
        ]);
    }

    public function normalize(
        NormalizeContactsAction $normalizeContacts,
    ): RedirectResponse {
        $result = $normalizeContacts->handle();

        return redirect()
            ->route('crm.settings.contact-maintenance.index')
            ->with('status', sprintf(
                'Contact normalization finished. %d contacts changed, %d phone numbers normalized, and %d invalid phone numbers were left unchanged.',
                $result['contacts_changed'],
                $result['phone_numbers_changed'],
                $result['invalid_phone_numbers'],
            ));
    }
}