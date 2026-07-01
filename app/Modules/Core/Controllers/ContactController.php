<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Requests\StoreContactRequest;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Core\Support\Contacts\ContactShowDataRegistry;
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

    public function show(
        Contact $contact,
        ContactPanelRegistry $contactPanelRegistry,
        ContactShowDataRegistry $contactShowDataRegistry,
    ): View {
        $relations = [
            'notes' => fn ($query) => $query->latest(),
        ];

        if (module_enabled('workflow')) {
            $relations[] = 'workflowProfile.contactStatus';
        }

        $contact->load($relations);

        $contactPanels = $contactPanelRegistry->panelsFor($contact);

        return view('crm.contacts.show', array_replace_recursive([
            'contact' => $contact,
            'contactPanels' => $contactPanels,

            'contactVisibilitySections' => [],

            'scheduledMessages' => null,
            'messageConsents' => collect(),
            'consentRevocations' => collect(),

            'teamMembers' => collect(),
            'currentTeamMember' => null,
            'taskView' => request('task_view') === 'archived' ? 'archived' : 'active',
            'tasks' => collect(),
            'archivedTasks' => collect(),
        ], $contactShowDataRegistry->dataFor($contact)));
    }

    public function import(): View
    {
        return view('crm.contacts.import');
    }

    public function previewImport(
        Request $request,
        ContactImportRegistry $contactImportRegistry,
    ): View|RedirectResponse {
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
            'importSections' => $contactImportRegistry->sections(),
        ]);
    }

    public function processImport(
        Request $request,
        CreateOrUpdateContactAction $createOrUpdateContact,
        ContactImportRegistry $contactImportRegistry,
    ): RedirectResponse {
        $rules = [
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
        ];

        foreach ($contactImportRegistry->requiredFieldKeys() as $field) {
            $rules["mapping.{$field}"] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        $csvPath = $validated['csv_path'];

        $mapping = collect($validated['mapping'])
            ->filter()
            ->only($contactImportRegistry->fieldKeys())
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

            $contactData = [];

            foreach ($contactImportRegistry->contactAttributeFields() as $field) {
                $value = $contactImportRegistry->mappedValue(
                    row: $data,
                    mapping: $mapping,
                    field: $field->key,
                );

                if ($value !== null) {
                    $contactData[$field->contactAttribute] = $value;
                }
            }

            $email = $contactData['email'] ?? null;

            if ($email === null) {
                $skipped++;

                continue;
            }

            if (array_key_exists('phone', $mapping)
                && $contactImportRegistry->mappedValue(row: $data, mapping: $mapping, field: 'phone') === null
            ) {
                $phoneWarnings++;
            }

            $existingContact = Contact::query()
                ->where('email', $email)
                ->first(['id', 'meta']);

            $wasExisting = $existingContact !== null;

            $existingImportedAt = is_array($existingContact?->meta)
                ? data_get($existingContact->meta, 'imported_at')
                : null;
                
            $importedAt = now();

            $originalSource = $contactData['source'] ?? null;
            $originalSubsource = $contactData['subsource'] ?? null;
            $originalStatus = $contactImportRegistry->mappedValue(
                row: $data,
                mapping: $mapping,
                field: 'import_status',
            );

            $contact = $createOrUpdateContact->handle(
                data: [
                    ...$contactData,
                    'source' => 'import',
                    'meta' => array_replace_recursive(
                        $contactData['meta'] ?? [],
                        [
                            'imported' => true,
                            'imported_at' => $existingImportedAt ?? $importedAt->toISOString(),
                            'import' => [
                                'original_source' => $originalSource,
                                'original_subsource' => $originalSubsource,
                                'original_status' => $originalStatus,
                            ],
                        ],
                    ),
                ],
                statusKey: null,
                statusChangeReason: 'crm_import',
            );

            $contactImportRegistry->handleModuleImports(
                contact: $contact,
                row: $data,
                mapping: $mapping,
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
}