<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\TeamMember;
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
        $scheduledMessages = $contact->scheduledMessages()
            ->where('status', 'sent')
            ->latest('send_at')
            ->paginate(10, ['*'], 'messages_page')
            ->withQueryString();

        $contact->load([
            'registrations.webinar',
            'notes' => fn ($query) => $query->latest(),
            'tasks' => fn ($query) => $query
                ->with('assignedTo')
                ->latest(),
            'messageConsents',
            'consentRevocations',
        ]);

        $teamMembers = TeamMember::active()
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('crm.contacts.show', compact(
            'contact',
            'scheduledMessages',
            'teamMembers',
        ));
    }

    public function markConverted(Contact $contact, WebinarRegistration $registration): RedirectResponse
    {
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

        return redirect()
            ->route('crm.contacts.index')
            ->with(
                'success',
                'Import processing not implemented yet.'
            );
    }
}