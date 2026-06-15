<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\WebinarRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index()
    {

        $contacts = Contact::latest()->paginate(20);

        return view('crm.contacts.index', compact('contacts'));
    }

    public function show(Contact $contact)
    {
        $contact->load([
            'registrations.webinar',
            'notes' => fn ($query) => $query->latest(),
            'tasks' => fn ($query) => $query->latest(),
        ]);

        return view('crm.contacts.show', compact('contact'));
    }

    public function markConverted(Contact $contact, WebinarRegistration $registration): RedirectResponse
    {
        // safety: ensure registration belongs to this contact
        if ($registration->contact_id !== $contact->id) {
            abort(404);
        }

        $contact->update([
            'converted_at' => now(),
        ]);

        return back();
    }

    public function import(): View
    {
        return view('crm.contacts.import');
    }

    public function previewImport(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $path = $validated['csv']->getRealPath();

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return back()
                ->withErrors(['csv' => 'Unable to read the uploaded CSV file.'])
                ->withInput();
        }

        $headers = fgetcsv($handle);

        fclose($handle);

        if (! is_array($headers) || $headers === []) {
            return back()
                ->withErrors(['csv' => 'The uploaded CSV does not contain a valid header row.'])
                ->withInput();
        }

        $headers = collect($headers)
            ->map(fn ($header) => trim((string) $header))
            ->filter()
            ->values();

        if ($headers->isEmpty()) {
            return back()
                ->withErrors(['csv' => 'The uploaded CSV header row is empty.'])
                ->withInput();
        }

        $storedPath = $validated['csv']->store(
            'imports',
            'local',
        );

        return view('crm.contacts.import-preview', [
            'headers' => $headers,
            'csvPath' => $storedPath,
        ]);
    }

    public function processImport(Request $request): RedirectResponse
    {
        $request->validate([
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
        ]);

        // next step:
        // parse csv
        // apply mapping
        // upsert contacts
        // create stages
        // create mortgage profiles

        return redirect()
            ->route('crm.contacts.index')
            ->with(
                'success',
                'Import processing not implemented yet.'
            );
    }

}
