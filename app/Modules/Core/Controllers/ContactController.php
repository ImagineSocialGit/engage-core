<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Requests\StoreContactRequest;
use App\Modules\Core\Services\Contacts\ContactImportStatusMapper;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Core\Support\Contacts\ContactShowDataRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
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
            'contactStatuses' => module_enabled('workflow')
                ? ContactStatus::query()->active()->ordered()->get(['id', 'name'])
                : collect(),
        ], $contactShowDataRegistry->dataFor($contact)));
    }

    public function updateStatus(
        Request $request,
        Contact $contact,
        UpdatesContactStatus $updatesContactStatus,
    ): RedirectResponse {
        if (! module_enabled('workflow')) {
            return back()->with('error', 'Workflow is not enabled.');
        }

        $validated = $request->validate([
            'contact_status_id' => [
                'required',
                'integer',
                Rule::exists('contact_statuses', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)),
            ],
        ]);

        $status = ContactStatus::query()
            ->active()
            ->findOrFail($validated['contact_status_id']);

        $updatesContactStatus->handle(
            contact: $contact,
            status: $status,
            reason: 'crm_manual_status_update',
            source: 'crm',
            actor: $request->user(),
            meta: [
                'source' => 'contact_show_status_form',
            ],
            force: true,
        );

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', config('contacts.labels.singular').' status updated.');
    }

    public function import(): View
    {
        return view('crm.contacts.import');
    }

    public function previewImport(
        Request $request,
        ContactImportRegistry $contactImportRegistry,
        ContactImportStatusMapper $contactImportStatusMapper,
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

        $contactStatuses = ContactStatus::query()
            ->active()
            ->ordered()
            ->get(['id', 'name']);

        return view('crm.contacts.import-preview', [
            'headers' => $headers,
            'rows' => $rows,
            'csvPath' => $storedPath,
            'importSections' => $contactImportRegistry->sections(),
            'contactStatuses' => $contactStatuses,
            'statusPreviewValues' => collect(),
        ]);
    }

    public function processImport(
        Request $request,
        CreateOrUpdateContactAction $createOrUpdateContact,
        ContactImportRegistry $contactImportRegistry,
        ContactImportStatusMapper $contactImportStatusMapper,
    ): RedirectResponse {
        $rules = [
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'status_mapping' => ['nullable', 'array'],
        ];

        foreach ($contactImportRegistry->fieldKeys() as $field) {
            $rules["mapping.{$field}"] = in_array($field, $contactImportRegistry->requiredFieldKeys(), true)
                ? ['required', 'string']
                : ['nullable', 'string'];
        }

        foreach ($contactImportRegistry->requiredFieldKeys() as $field) {
            $rules["mapping.{$field}"] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        $csvPath = $validated['csv_path'];

        $allowedMappingFields = array_values(array_unique([
            ...$contactImportRegistry->fieldKeys(),
            'import_status',
        ]));

        $mapping = collect($validated['mapping'])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->only($allowedMappingFields)
            ->toArray();

        $submittedStatusMapping = $contactImportStatusMapper->normalizeSubmittedMapping(
            $validated['status_mapping'] ?? [],
        );

        if ($submittedStatusMapping !== [] && ! App::bound(UpdatesContactStatus::class)) {
            throw ValidationException::withMessages([
                'status_mapping' => 'Status mapping requires Workflow because current contact status is stored in Workflow profiles.',
            ]);
        }

        $statusesById = $contactImportStatusMapper->validateActiveStatusMapping($submittedStatusMapping);

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

        $importedAt = now();

        $importBatch = ContactImportBatch::query()->create([
            'name' => 'Contact import '.$importedAt->format('M j, Y g:i A'),
            'source' => 'crm_csv',
            'original_filename' => basename($csvPath),
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => $importedAt,
            'contact_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [
                'csv_path' => $csvPath,
                'mapping' => $mapping,
                'headers' => $headers,
            ],
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $phoneWarnings = 0;
        $statusMappingResults = [];

        try {
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

                $originalSource = $contactData['source'] ?? null;
                $originalSubsource = $contactData['subsource'] ?? null;
                $originalStatus = $this->mappedImportStatusValue(
                    row: $data,
                    mapping: $mapping,
                    contactImportRegistry: $contactImportRegistry,
                );

                $statusMappingResult = $contactImportStatusMapper->resolve(
                    originalStatus: $originalStatus,
                    mapping: $submittedStatusMapping,
                    statusesById: $statusesById,
                );

                $statusMappingResults[] = $statusMappingResult;

                $contact = $createOrUpdateContact->handle(
                    data: array_filter([
                        ...$contactData,
                        'source' => 'import',
                        'contact_status_id' => $statusMappingResult->isMapped()
                            ? $statusMappingResult->contactStatusId
                            : null,
                        'meta' => array_replace_recursive(
                            $contactData['meta'] ?? [],
                            [
                                'imported' => true,
                                'imported_at' => $existingImportedAt ?? $importedAt->toISOString(),
                                'import' => [
                                    'batch_id' => $importBatch->id,
                                    'original_source' => $originalSource,
                                    'original_subsource' => $originalSubsource,
                                    'original_status' => $originalStatus,
                                    'status_mapping' => $statusMappingResult->toMeta(),
                                ],
                            ],
                        ),
                    ], fn (mixed $value): bool => $value !== null),
                    statusKey: null,
                    statusChangeReason: 'crm_import',
                );

                $contact->forceFill([
                    'contact_import_batch_id' => $importBatch->id,
                ])->save();

                $contactImportRegistry->handleModuleImports(
                    contact: $contact,
                    row: $data,
                    mapping: $mapping,
                );

                $wasExisting ? $updated++ : $created++;
            }
        } catch (\Throwable $exception) {
            $importBatch->forceFill([
                'status' => ContactImportBatch::STATUS_FAILED,
                'contact_count' => $created + $updated,
                'successful_count' => $created + $updated,
                'failed_count' => $skipped,
                'meta' => array_replace_recursive($importBatch->meta ?? [], [
                    'failed_at' => now()->toISOString(),
                    'failure' => [
                        'message' => $exception->getMessage(),
                    ],
                    'status_mapping' => $contactImportStatusMapper->batchMeta(
                        sourceColumn: $mapping['import_status'] ?? null,
                        mapping: $submittedStatusMapping,
                        results: $statusMappingResults,
                    ),
                ]),
            ])->save();

            throw $exception;
        } finally {
            fclose($handle);
        }

        $statusMappingMeta = $contactImportStatusMapper->batchMeta(
            sourceColumn: $mapping['import_status'] ?? null,
            mapping: $submittedStatusMapping,
            results: $statusMappingResults,
        );

        $importBatch->forceFill([
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'contact_count' => $created + $updated + $skipped,
            'successful_count' => $created + $updated,
            'failed_count' => $skipped,
            'meta' => array_replace_recursive($importBatch->meta ?? [], [
                'status_mapping' => $statusMappingMeta,
            ]),
        ])->save();

        return redirect()
            ->route('crm.contacts.index')
            ->with('success', sprintf(
                'Import complete. %d created, %d updated, %d skipped%s%s.',
                $created,
                $updated,
                $skipped,
                $phoneWarnings > 0 ? ", {$phoneWarnings} phone values were ignored" : '',
                $statusMappingMeta['review_required'] ? ', status review needed for unmapped values' : ''
            ));
    }

    private function mappedImportStatusValue(
        array $row,
        array $mapping,
        ContactImportRegistry $contactImportRegistry,
    ): ?string {
        $value = $contactImportRegistry->mappedValue(
            row: $row,
            mapping: $mapping,
            field: 'import_status',
        );

        if ($value !== null) {
            return $value;
        }

        $header = $mapping['import_status'] ?? null;

        if (! is_string($header) || trim($header) === '') {
            return null;
        }

        $value = $row[$header] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}