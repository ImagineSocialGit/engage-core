<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\CreateOrUpdateContactAction;
use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Data\Contacts\ContactImportContext;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportOccurrence;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Requests\StoreContactRequest;
use App\Modules\Core\Services\Contacts\ContactImportProfileRegistry;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Core\Support\Contacts\ContactShowDataRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $originalFilename = $validated['csv']->getClientOriginalName();
        $storedPath = $validated['csv']->store('imports', 'local');

        $request->session()->put(
            $this->importOriginalFilenameSessionKey($storedPath),
            $originalFilename,
        );

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

        $importProfile = $contactImportProfileRegistry->findByFilename($originalFilename);
        $suggestedMapping = $importProfile !== null
            ? $contactImportProfileRegistry->suggestedMapping($importProfile, $headers->all())
            : [];

        if ($importProfile !== null) {
            $request->session()->put(
                $this->importProfileKeySessionKey($storedPath),
                $importProfile->key,
            );
        } else {
            $request->session()->forget($this->importProfileKeySessionKey($storedPath));
        }

        $rows = [];
        $columnProfiles = [];

        foreach ($headers as $header) {
            $columnProfiles[$header] = [
                'blank_count' => 0,
                'other_count' => 0,
                'truncated' => false,
                'counts' => [],
            ];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $row = array_pad($row, $headers->count(), null);
            $data = array_combine(
                $headers->all(),
                array_slice($row, 0, $headers->count()),
            );

            if (! is_array($data)) {
                continue;
            }

            if (count($rows) < 20) {
                $rows[] = $data;
            }

            foreach ($headers as $header) {
                $value = $this->nonEmptyString($data[$header] ?? null);

                if ($value === null) {
                    $columnProfiles[$header]['blank_count']++;
                    continue;
                }

                if (isset($columnProfiles[$header]['counts'][$value])) {
                    $columnProfiles[$header]['counts'][$value]++;
                    continue;
                }

                if (count($columnProfiles[$header]['counts']) < 100) {
                    $columnProfiles[$header]['counts'][$value] = 1;
                    continue;
                }

                $columnProfiles[$header]['truncated'] = true;
                $columnProfiles[$header]['other_count']++;
            }
        }

        fclose($handle);

        foreach ($columnProfiles as $header => $profile) {
            $values = [];

            foreach ($profile['counts'] as $value => $count) {
                $values[] = [
                    'token' => substr(hash('sha256', $header."\0".$value), 0, 20),
                    'value' => $value,
                    'count' => $count,
                ];
            }

            usort(
                $values,
                static fn (array $left, array $right): int => [
                    -$left['count'],
                    $left['value'],
                ] <=> [
                    -$right['count'],
                    $right['value'],
                ],
            );

            $columnProfiles[$header] = [
                'blank_count' => $profile['blank_count'],
                'other_count' => $profile['other_count'],
                'truncated' => $profile['truncated'],
                'values' => $values,
            ];
        }

        return view('crm.contacts.import-preview', [
            'headers' => $headers,
            'rows' => $rows,
            'columnProfiles' => $columnProfiles,
            'csvPath' => $storedPath,
            'importSections' => $contactImportRegistry->sections(),
            'treatmentDefinitions' => $treatmentRegistry->definitions(),
            'importProfile' => $importProfile,
            'suggestedMapping' => $suggestedMapping,
        ]);
    }

    public function processImport(
        Request $request,
        CreateOrUpdateContactAction $createOrUpdateContact,
        ContactImportRegistry $contactImportRegistry,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
    ): RedirectResponse {
        $rules = [
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'treatments' => ['nullable', 'array'],
        ];

        foreach ($contactImportRegistry->fieldKeys() as $field) {
            $rules["mapping.{$field}"] = in_array($field, $contactImportRegistry->requiredFieldKeys(), true)
                ? ['required', 'string']
                : ['nullable', 'string'];
        }

        $validated = $request->validate($rules);
        $csvPath = $validated['csv_path'];

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

        $profileKey = $request->session()->get($this->importProfileKeySessionKey($csvPath));
        $importProfile = is_string($profileKey) && trim($profileKey) !== ''
            ? $contactImportProfileRegistry->get($profileKey)
            : null;
        $profileDefaults = $importProfile?->defaults ?? [];

        $allowedMappingFields = array_values(array_unique([
            ...$contactImportRegistry->fieldKeys(),
            'import_status',
        ]));

        $mapping = collect($validated['mapping'])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->only($allowedMappingFields)
            ->toArray();

        $treatmentSelections = $treatmentRegistry->normalizeSubmitted(
            submitted: is_array($validated['treatments'] ?? null)
                ? $validated['treatments']
                : [],
            headers: $headers,
        );
        $treatmentStats = $this->initializeTreatmentStats($treatmentSelections);

        $importedAt = now();
        $originalFilename = $request->session()->pull(
            $this->importOriginalFilenameSessionKey($csvPath),
        );

        $importBatch = ContactImportBatch::query()->create([
            'name' => 'Contact import '.$importedAt->format('M j, Y g:i A'),
            'source' => 'crm_csv',
            'original_filename' => is_string($originalFilename) && trim($originalFilename) !== ''
                ? basename(trim($originalFilename))
                : basename($csvPath),
            'status' => ContactImportBatch::STATUS_PROCESSING,
            'imported_at' => $importedAt,
            'contact_count' => 0,
            'successful_count' => 0,
            'failed_count' => 0,
            'meta' => [
                'csv_path' => $csvPath,
                'mapping' => $mapping,
                'headers' => $headers,
                'profile_key' => $importProfile?->key,
                'profile_defaults' => $profileDefaults,
                'treatment_selections' => $treatmentRegistry->selectionsMeta($treatmentSelections),
            ],
        ]);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $phoneWarnings = 0;
        $rowNumber = 1;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $row = array_pad($row, count($headers), null);

                $data = array_combine(
                    $headers,
                    array_slice($row, 0, count($headers)),
                );

                if (! is_array($data)) {
                    $skipped++;
                    continue;
                }

                $treatmentResolution = $treatmentRegistry->resolveRow(
                    row: $data,
                    selections: $treatmentSelections,
                );
                $contactData = [];

                foreach ($contactImportRegistry->contactAttributeFields() as $field) {
                    $value = $contactImportRegistry->value(
                        row: $data,
                        mapping: $mapping,
                        defaults: $profileDefaults,
                        field: $field->key,
                        overrides: $treatmentResolution->fieldOverrides,
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

                $email = strtolower(trim((string) $email));

                if ($email === '') {
                    $skipped++;
                    continue;
                }

                $contactData['email'] = $email;
                $this->recordTreatmentStats($treatmentStats, $treatmentResolution->targets);

                if (array_key_exists('phone', $mapping)
                    && $contactImportRegistry->mappedValue(row: $data, mapping: $mapping, field: 'phone') === null
                ) {
                    $phoneWarnings++;
                }

                $existingContact = Contact::query()
                    ->where('email', $email)
                    ->first(['id', 'source', 'subsource', 'meta']);

                $wasExisting = $existingContact !== null;

                $existingImportedAt = is_array($existingContact?->meta)
                    ? data_get($existingContact->meta, 'imported_at')
                    : null;

                $originalSource = $contactImportRegistry->value(
                    row: $data,
                    mapping: $mapping,
                    defaults: $profileDefaults,
                    field: 'source',
                );
                $originalSubsource = $contactImportRegistry->value(
                    row: $data,
                    mapping: $mapping,
                    defaults: $profileDefaults,
                    field: 'subsource',
                );
                $originalStatus = $this->mappedImportStatusValue(
                    row: $data,
                    mapping: $mapping,
                    contactImportRegistry: $contactImportRegistry,
                    defaults: $profileDefaults,
                );

                $sourceValues = $this->contactImportSourceValues(
                    existingContact: $existingContact,
                    originalSource: $originalSource,
                    originalSubsource: $originalSubsource,
                );

                $contact = $createOrUpdateContact->handle(
                    data: array_filter([
                        ...$contactData,
                        ...$sourceValues,
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
                                    'treatments' => $treatmentResolution->toMeta(),
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

                $occurrence = ContactImportOccurrence::query()->create([
                    'contact_import_batch_id' => $importBatch->id,
                    'contact_id' => $contact->id,
                    'row_number' => $rowNumber,
                    'outcome' => $wasExisting
                        ? ContactImportOccurrence::OUTCOME_UPDATED
                        : ContactImportOccurrence::OUTCOME_CREATED,
                    'identity_type' => 'email',
                    'identity_value' => $email,
                    'original_source' => $originalSource,
                    'original_subsource' => $originalSubsource,
                    'original_status' => $originalStatus,
                    'row_fingerprint' => hash('sha256', serialize($data)),
                    'meta' => [
                        'treatments' => $treatmentResolution->toMeta(),
                    ],
                ]);

                $contactImportRegistry->handleModuleImports(
                    new ContactImportContext(
                        contact: $contact,
                        batch: $importBatch,
                        occurrence: $occurrence,
                        row: $data,
                        mapping: $mapping,
                        defaults: $profileDefaults,
                        overrides: $treatmentResolution->fieldOverrides,
                    ),
                );

                $treatmentRegistry->apply(
                    resolution: $treatmentResolution,
                    contact: $contact,
                    batch: $importBatch,
                    occurrence: $occurrence,
                    actor: $request->user(),
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
                    'treatments' => $treatmentRegistry->batchMeta(
                        stats: $treatmentStats,
                        selections: $treatmentSelections,
                    ),
                ]),
            ])->save();

            throw $exception;
        } finally {
            fclose($handle);
        }

        $treatmentMeta = $treatmentRegistry->batchMeta(
            stats: $treatmentStats,
            selections: $treatmentSelections,
        );

        $request->session()->forget($this->importProfileKeySessionKey($csvPath));

        $importBatch->forceFill([
            'status' => ContactImportBatch::STATUS_COMPLETED,
            'contact_count' => $created + $updated + $skipped,
            'successful_count' => $created + $updated,
            'failed_count' => $skipped,
            'meta' => array_replace_recursive($importBatch->meta ?? [], [
                'treatments' => $treatmentMeta,
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
                $this->treatmentReviewRequired($treatmentMeta) ? ', treatment review needed for unmapped values' : '',
            ));
    }

    /**
     * Preserve an existing meaningful acquisition source across overlapping
     * imports while allowing a generic/blank import source to be enriched.
     *
     * @return array{source: string, subsource: ?string}
     */
    private function contactImportSourceValues(
        ?Contact $existingContact,
        ?string $originalSource,
        ?string $originalSubsource,
    ): array {
        $currentSource = $this->nonEmptyString($existingContact?->source);
        $currentSubsource = $this->nonEmptyString($existingContact?->subsource);

        if ($currentSource !== null && strtolower($currentSource) !== 'import') {
            $subsource = $currentSubsource;

            if ($subsource === null
                && $originalSource !== null
                && strcasecmp($currentSource, $originalSource) === 0
            ) {
                $subsource = $originalSubsource;
            }

            return [
                'source' => $currentSource,
                'subsource' => $subsource,
            ];
        }

        return [
            'source' => $originalSource ?? $currentSource ?? 'import',
            'subsource' => $originalSubsource ?? $currentSubsource,
        ];
    }

    /**
     * @param array<string, \App\Modules\Core\Data\Contacts\ContactImportTreatmentSelection> $selections
     * @return array<string, array{applied_count: int, unmapped_count: int, missing_count: int}>
     */
    private function initializeTreatmentStats(array $selections): array
    {
        $stats = [];

        foreach (array_keys($selections) as $targetKey) {
            $stats[$targetKey] = [
                'applied_count' => 0,
                'unmapped_count' => 0,
                'missing_count' => 0,
            ];
        }

        return $stats;
    }

    /**
     * @param array<string, array{applied_count: int, unmapped_count: int, missing_count: int}> $stats
     * @param array<string, array{state: string, source_column: ?string, source_value: ?string, values: array<int, string>}> $targets
     */
    private function recordTreatmentStats(array &$stats, array $targets): void
    {
        foreach ($targets as $targetKey => $resolved) {
            $counter = match ($resolved['state']) {
                'applied' => 'applied_count',
                'unmapped' => 'unmapped_count',
                'missing' => 'missing_count',
                default => null,
            };

            if ($counter !== null && isset($stats[$targetKey][$counter])) {
                $stats[$targetKey][$counter]++;
            }
        }
    }

    /**
     * @param array<string, mixed> $treatmentMeta
     */
    private function treatmentReviewRequired(array $treatmentMeta): bool
    {
        foreach ($treatmentMeta as $meta) {
            if (is_array($meta) && ($meta['review_required'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function importOriginalFilenameSessionKey(string $csvPath): string
    {
        return 'contact_imports.'.hash('sha256', $csvPath).'.original_filename';
    }

    private function importProfileKeySessionKey(string $csvPath): string
    {
        return 'contact_imports.'.hash('sha256', $csvPath).'.profile_key';
    }

    private function mappedImportStatusValue(
        array $row,
        array $mapping,
        ContactImportRegistry $contactImportRegistry,
        array $defaults = [],
    ): ?string {
        return $contactImportRegistry->value(
            row: $row,
            mapping: $mapping,
            defaults: $defaults,
            field: 'import_status',
        );
    }
}