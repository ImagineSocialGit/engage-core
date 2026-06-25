<?php

namespace App\Http\Controllers\CRM;

use App\Actions\CRM\Contacts\CreateOrUpdateContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreContactRequest;
use App\Models\Contact;
use App\Models\ContactStatus;
use App\Models\Task;
use App\Models\TeamMember;
use App\Support\CRM\Contacts\ContactPanelRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(): View
    {
        $contactsQuery = Contact::query();

        if (module_enabled('workflow')) {
            $contactsQuery->with('workflowProfile.contactStatus');
        }

        $contacts = $contactsQuery
            ->latest()
            ->paginate(20);

        $contactStatuses = ContactStatus::query()
            ->active()
            ->ordered()
            ->get(['id', 'name']);

        return view('crm.contacts.index', compact('contacts', 'contactStatuses'));
    }

    public function store(
        StoreContactRequest $request,
        CreateOrUpdateContactAction $createOrUpdateContact,
    ): RedirectResponse {
        $contact = $createOrUpdateContact->handle(
            data: [
                ...$request->validated(),
                'source' => $request->validated('source') ?? 'crm',
            ],
            statusKey: module_enabled('workflow') ? config('contacts.default_workflow_status_key') : null,
            statusChangeReason: 'crm_manual_create',
        );

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', config('contacts.labels.singular').' created.');
    }

    public function show(Contact $contact, ContactPanelRegistry $contactPanelRegistry): View
    {
        $scheduledMessages = null;

        if (module_enabled('messaging')) {
            $scheduledMessages = $contact->scheduledMessages()
                ->where('status', 'sent')
                ->latest('send_at')
                ->paginate(10, ['*'], 'messages_page')
                ->withQueryString();
        }

        $relations = [
            'notes' => fn ($query) => $query->latest(),
        ];

        if (module_enabled('workflow')) {
            $relations[] = 'workflowProfile.contactStatus';
        }

        if (module_enabled('messaging')) {
            $relations[] = 'messageConsents';
            $relations[] = 'consentRevocations';
        }

        $contact->load($relations);

        $taskView = request('task_view') === 'archived' ? 'archived' : 'active';

        $tasks = collect();
        $archivedTasks = collect();
        $teamMembers = collect();
        $currentTeamMember = null;

        if (module_enabled('tasks')) {
            $tasks = Task::query()
                ->with('assignedTo')
                ->where('related_type', $contact->getMorphClass())
                ->where('related_id', $contact->id)
                ->unarchived()
                ->latest()
                ->get();

            $archivedTasks = Task::query()
                ->with('assignedTo')
                ->where('related_type', $contact->getMorphClass())
                ->where('related_id', $contact->id)
                ->archived()
                ->latest('archived_at')
                ->get();

            $teamMembers = TeamMember::active()
                ->orderBy('name')
                ->get(['id', 'name', 'email']);

            $currentTeamMember = TeamMember::query()
                ->where('user_id', auth()->id())
                ->first();
        }

        $contactPanels = $contactPanelRegistry->panelsFor($contact);

        return view('crm.contacts.show', compact(
            'contact',
            'scheduledMessages',
            'teamMembers',
            'currentTeamMember',
            'taskView',
            'tasks',
            'archivedTasks',
            'contactPanels',
        ));
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

        $storedPath = $validated['csv']->store('imports', 'local');

        $handle = fopen(Storage::disk('local')->path($storedPath), 'r');

        if ($handle === false) {
            return back()
                ->withErrors(['csv' => 'Unable to read the uploaded CSV file.'])
                ->withInput();
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers) || $headers === []) {
            fclose($handle);

            return back()
                ->withErrors(['csv' => 'The uploaded CSV does not contain a valid header row.'])
                ->withInput();
        }

        $headers = collect($headers)
            ->map(fn ($header) => trim((string) $header))
            ->filter()
            ->values();

        if ($headers->isEmpty()) {
            fclose($handle);

            return back()
                ->withErrors(['csv' => 'The uploaded CSV header row is empty.'])
                ->withInput();
        }

        $rows = [];

        while (($row = fgetcsv($handle)) !== false && count($rows) < 20) {
            $row = array_pad($row, $headers->count(), null);

            $rows[] = array_combine(
                $headers->all(),
                array_slice($row, 0, $headers->count()),
            );
        }

        fclose($handle);

        return view('crm.contacts.import-preview', [
            'headers' => $headers,
            'rows' => $rows,
            'csvPath' => $storedPath,
        ]);
    }

    public function processImport(
        Request $request,
        CreateOrUpdateContactAction $createOrUpdateContact,
    ): RedirectResponse {
        $validated = $request->validate([
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'mapping.email' => ['required', 'string'],
        ]);

        $csvPath = $validated['csv_path'];
        $mapping = collect($validated['mapping'])->filter()->toArray();

        $allowedMappingFields = [
            'first_name',
            'last_name',
            'name',
            'email',
            'phone',
            'source',
            'subsource',
            'last_contacted_at',
            'last_activity_at',
        ];

        $mapping = collect($mapping)
            ->only($allowedMappingFields)
            ->toArray();

        if (! Storage::disk('local')->exists($csvPath)) {
            throw ValidationException::withMessages([
                'csv_path' => 'The uploaded CSV file could not be found.',
            ]);
        }

        $handle = fopen(Storage::disk('local')->path($csvPath), 'r');

        if ($handle === false) {
            throw ValidationException::withMessages([
                'csv_path' => 'The uploaded CSV file could not be opened.',
            ]);
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers) || $headers === []) {
            fclose($handle);

            throw ValidationException::withMessages([
                'csv_path' => 'The uploaded CSV does not contain a valid header row.',
            ]);
        }

        $headers = collect($headers)
            ->map(fn ($header) => trim((string) $header))
            ->filter()
            ->values()
            ->all();

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $phoneWarnings = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, count($headers), null);

            $data = array_combine(
                $headers,
                array_slice($row, 0, count($headers)),
            );

            if (! is_array($data)) {
                $skipped++;
                continue;
            }

            $email = $this->mappedValue($data, $mapping, 'email');

            if ($email === null) {
                $skipped++;
                continue;
            }

            $contactData = [
                'email' => $email,
            ];

            foreach (['first_name', 'last_name', 'name', 'phone', 'source', 'subsource', 'last_contacted_at', 'last_activity_at'] as $field) {
                $value = $this->mappedValue($data, $mapping, $field);

                if ($value !== null) {
                    $contactData[$field] = $value;
                }
            }

            if (array_key_exists('phone', $contactData) && $contactData['phone'] === null) {
                $phoneWarnings++;
            }

            $wasExisting = Contact::query()
                ->where('email', $email)
                ->exists();

            $createOrUpdateContact->handle(
                data: $contactData,
                statusKey: null,
                statusChangeReason: 'crm_import',
            );

            $wasExisting ? $updated++ : $created++;
        }

        fclose($handle);

        return redirect()
            ->route('crm.contacts.index')
            ->with('success', sprintf(
                'Import complete. %d created, %d updated, %d skipped%s.',
                $created,
                $updated,
                $skipped,
                $phoneWarnings > 0 ? ", {$phoneWarnings} phone values were ignored" : ''
            ));
    }

    private function mappedValue(array $data, array $mapping, string $field): ?string
    {
        $header = $mapping[$field] ?? null;

        if ($header === null || $header === '') {
            return null;
        }

        $value = $data[$header] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}