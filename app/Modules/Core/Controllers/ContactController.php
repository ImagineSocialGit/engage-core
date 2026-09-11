<?php

namespace App\Modules\Core\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Core\Actions\Contacts\CreateManualContactAction;
use App\Modules\Core\Actions\Contacts\UpdateContactAction;
use App\Modules\Core\Access\Actions\AssignContactOwnershipAction;
use App\Modules\Core\Access\Services\ContactVisibility;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Contracts\Contacts\UpdatesContactStatus;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Jobs\ProcessContactImportBatchChunkJob;
use App\Modules\Core\Models\ContactImportBatch;
use App\Modules\Core\Models\ContactImportRun;
use App\Modules\Core\Models\ContactStatus;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Data\Contacts\ContactResultAction;
use App\Modules\Core\Requests\StoreContactRequest;
use App\Modules\Core\Requests\UpdateContactRequest;
use App\Modules\Core\Services\Contacts\ContactImportHeaderMapper;
use App\Modules\Core\Services\Contacts\ContactImportProfileRegistry;
use App\Modules\Core\Services\Contacts\ContactIndexFilterService;
use App\Modules\Core\Support\Contacts\ContactImportPostProcessorRegistry;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use App\Modules\Core\Support\Contacts\ContactPanelRegistry;
use App\Modules\Core\Support\Contacts\ContactResultActionRegistry;
use App\Modules\Core\Support\Contacts\ContactShowDataRegistry;
use App\Support\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ContactController extends Controller
{
    private const IMPORT_MODE_ADD = 'add';
    private const IMPORT_MODE_UPDATE = 'update';

    public function index(
        Request $request,
        ContactIndexFilterService $contactIndexFilters,
        ContactVisibility $contactVisibility,
        ContactResultActionRegistry $contactResultActions,
    ): View {
        $contactFilters = $contactIndexFilters->state($request->query());
        $contactsQuery = $contactVisibility->apply(
            $contactIndexFilters->query($contactFilters),
            $request->user(),
        );

        if (module_enabled('workflow')) {
            $contactsQuery->with('workflowProfile.contactStatus');
        }

        $contacts = $contactsQuery
            ->reorder()
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $totalContacts = $contactVisibility
            ->apply(Contact::query(), $request->user())
            ->count();

        $contactStatuses = ContactStatus::query()
            ->active()
            ->ordered()
            ->get(['id', 'name']);

        $messagingAvailable = in_array(
            'messaging',
            app(ModuleManager::class)->enabledKeysWithDependencies(),
            true,
        );

        $contactFilters['active'] = collect($contactFilters['active'])
            ->map(function (array $activeFilter) use ($request): array {
                $query = $request->query();
                unset($query[$activeFilter['key']], $query['page']);

                return [
                    ...$activeFilter,
                    'remove_url' => route('crm.contacts.index', $query),
                ];
            })
            ->all();

        $contactRows = $contacts->getCollection()
            ->mapWithKeys(function (Contact $contact): array {
                $displayName = $contact->name
                    ?: trim($contact->first_name.' '.$contact->last_name)
                    ?: $contact->email
                    ?: str((string) config('contacts.labels.singular'))->title().' #'.$contact->id;

                return [
                    (int) $contact->getKey() => [
                        'display_name' => $displayName,
                        'contact_method' => collect([$contact->email, $contact->phone])
                            ->filter()
                            ->join(' · '),
                        'status_name' => module_enabled('workflow')
                            ? $contact->workflowProfile?->contactStatus?->name
                            : null,
                    ],
                ];
            })
            ->all();

        $contactResultPayload = $contactIndexFilters->resultPayload($contactFilters);
        $contactResultActions = $contactResultActions->actionsFor($request->user());
        $contactResultActionGroups = $this->contactResultActionGroups($contactResultActions);
        $contactResultCount = $contacts->total();
        $leadSingular = (string) config('contacts.labels.singular');
        $leadPlural = (string) config('contacts.labels.plural');

        return view('crm.contacts.index', compact(
            'contacts',
            'totalContacts',
            'contactFilters',
            'contactStatuses',
            'messagingAvailable',
            'contactRows',
            'contactResultPayload',
            'contactResultActions',
            'contactResultActionGroups',
            'contactResultCount',
            'leadSingular',
            'leadPlural',
        ));
    }

    /**
     * @param array<int, ContactResultAction> $actions
     * @return array<int, array{key: string, label: string, description: string, sort: int, actions: array<int, ContactResultAction>}>
     */
    private function contactResultActionGroups(array $actions): array
    {
        return collect($actions)
            ->groupBy(fn (ContactResultAction $action): string => $action->groupKey)
            ->map(function ($group): array {
                /** @var ContactResultAction $first */
                $first = $group->first();

                return [
                    'key' => $first->groupKey,
                    'label' => $first->groupLabel,
                    'description' => $first->groupDescription,
                    'sort' => $first->groupSort,
                    'actions' => $group
                        ->sortBy(fn (ContactResultAction $action): string => sprintf(
                            '%010d-%s-%s',
                            $action->sort,
                            $action->label,
                            $action->key,
                        ))
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy(fn (array $group): string => sprintf(
                '%010d-%s-%s',
                $group['sort'],
                $group['label'],
                $group['key'],
            ))
            ->values()
            ->all();
    }

    public function store(
        StoreContactRequest $request,
        CreateManualContactAction $createManualContact,
        UserAccessService $access,
        AssignContactOwnershipAction $assignOwnership,
    ): RedirectResponse {
        $validated = $request->validated();
        unset($validated['existing_relationship_confirmed']);

        $contact = $createManualContact->handle(
            data: [
                ...$validated,
                'source' => $validated['source'] ?? 'crm',
            ],
            statusKey: module_enabled('workflow') ? config('contacts.default_contact_status_key') : null,
            existingRelationshipConfirmed: $request->boolean('existing_relationship_confirmed'),
            actorUserId: $request->user()?->getKey(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        if ($request->user() !== null && ! $access->allows($request->user(), 'contacts.view_all')) {
            $contact = $assignOwnership->handle(
                contact: $contact,
                assignedUser: $request->user(),
                assignedTeam: null,
                actor: $request->user(),
            );
        }

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', config('contacts.labels.singular').' created.');
    }

    public function update(
        UpdateContactRequest $request,
        Contact $contact,
        UpdateContactAction $updateContact,
    ): RedirectResponse {
        $validated = $request->validated();
        unset($validated['contact_edit_context']);

        $updateContact->handle($contact, $validated);

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', config('contacts.labels.singular').' updated.');
    }

    public function show(
        Contact $contact,
        ContactPanelRegistry $contactPanelRegistry,
        ContactShowDataRegistry $contactShowDataRegistry,
    ): View {
        $relations = [
            'notes' => fn ($query) => $query->latest(),
            'tags' => fn ($query) => $query->orderBy('tag'),
        ];

        if (module_enabled('workflow')) {
            $relations[] = 'workflowProfile.contactStatus';
        }

        $contact->load($relations);

        $contactPanels = $contactPanelRegistry->panelsFor($contact);
        $configuredRailPanelKeys = collect(config('contacts.show.rail_panels', []))
            ->filter(fn (mixed $key): bool => is_string($key) && trim($key) !== '')
            ->map(fn (string $key): string => trim($key))
            ->values();
        $contactRailPanels = $contactPanels
            ->filter(fn (ContactPanel $panel): bool => $panel->placement === ContactPanel::PLACEMENT_RAIL
                || $configuredRailPanelKeys->contains($panel->key))
            ->values();
        $contactMainPanels = $contactPanels
            ->reject(fn (ContactPanel $panel): bool => $contactRailPanels->contains(
                fn (ContactPanel $railPanel): bool => $railPanel->key === $panel->key,
            ))
            ->values();
        $contactHasRail = module_enabled('inbound_messaging') || $contactRailPanels->isNotEmpty();

        $viewData = array_replace_recursive([
            'contact' => $contact,
            'contactPanels' => $contactPanels,
            'contactMainPanels' => $contactMainPanels,
            'contactRailPanels' => $contactRailPanels,
            'contactHasRail' => $contactHasRail,

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
        ], $contactShowDataRegistry->dataFor($contact));

        return view(
            'crm.contacts.show',
            $this->contactShowViewData($contact, $viewData),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function contactShowViewData(Contact $contact, array $data): array
    {
        $contactSingular = (string) config('contacts.labels.singular', 'contact');
        $contactName = $contact->name
            ?: trim($contact->first_name.' '.$contact->last_name)
            ?: $contact->email
            ?: str($contactSingular)->title().' #'.$contact->id;
        $currentStatus = module_enabled('workflow')
            ? $contact->workflowProfile?->contactStatus
            : null;

        $businessContext = is_array($data['contactBusinessContext'] ?? null)
            ? $data['contactBusinessContext']
            : ['primary' => null, 'relationships' => []];
        $primaryBusinessContext = is_array($businessContext['primary'] ?? null)
            ? $businessContext['primary']
            : null;
        $businessRelationships = collect($businessContext['relationships'] ?? [])
            ->filter(fn (mixed $relationship): bool => is_array($relationship))
            ->values();
        $businessLabel = filled($primaryBusinessContext['label'] ?? null)
            ? $primaryBusinessContext['label']
            : str($contactSingular)->title()->toString();
        $usesRelationshipStage = ($primaryBusinessContext['progression_mode'] ?? null) === 'relationship_stage';
        $showContactStatusProgression = module_enabled('workflow') && ! $usesRelationshipStage;
        $hasProgression = $usesRelationshipStage || module_enabled('workflow');
        $progressionType = $usesRelationshipStage ? 'Stage' : 'Status';
        $progressionLabel = $usesRelationshipStage
            ? ($primaryBusinessContext['stage_label'] ?? 'No stage')
            : ($currentStatus?->name ?? 'No status');
        $businessSource = filled($primaryBusinessContext['source'] ?? null)
            ? $primaryBusinessContext['source']
            : $contact->source;
        $businessSubsource = filled($primaryBusinessContext['subsource'] ?? null)
            ? $primaryBusinessContext['subsource']
            : $contact->subsource;

        $conversationItems = collect($data['conversationItems'] ?? [])
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();
        $latestInboundReply = is_array($data['latestInboundReply'] ?? null)
            ? $data['latestInboundReply']
            : null;
        $latestAutomatedResponse = is_array($data['latestAutomatedResponse'] ?? null)
            ? $data['latestAutomatedResponse']
            : null;
        $conversationReply = is_array($data['conversationReply'] ?? null)
            ? $data['conversationReply']
            : null;
        $primaryConversationItemIds = array_values(array_filter([
            $latestInboundReply['id'] ?? null,
            $latestAutomatedResponse['id'] ?? null,
        ]));
        $conversationTimeline = $conversationItems
            ->reject(fn (array $item): bool => in_array(
                $item['id'] ?? null,
                $primaryConversationItemIds,
                true,
            ))
            ->take($primaryConversationItemIds === [] ? 8 : 6)
            ->values();

        $contactTags = $contact->tags
            ->pluck('tag')
            ->filter(fn (mixed $tag): bool => filled($tag))
            ->values();
        $clientTimezone = (string) config('client.timezone', config('app.timezone', 'UTC'));
        $defaultContactTaskDueAt = now($clientTimezone)
            ->addDay()
            ->setTime(9, 0)
            ->format('Y-m-d\TH:i');
        $openTasks = collect($data['tasks'] ?? [])
            ->filter(fn ($task): bool => $task->status === 'open' && ! $task->archived_at)
            ->sortBy(fn ($task): string => sprintf(
                '%d-%012d-%012d-%012d',
                $task->due_at ? 0 : 1,
                $task->due_at?->timestamp ?? 999999999999,
                $task->created_at?->timestamp ?? 0,
                $task->id ?? 0,
            ))
            ->values();
        $hasGenericImportTreatments = is_array(data_get($contact->meta, 'import.treatments'));
        $importStatusTreatmentState = $hasGenericImportTreatments
            ? data_get($contact->meta, 'import.treatments.contact_status.state')
            : data_get($contact->meta, 'import.status_mapping.state');

        return [
            ...$data,
            'contactSingular' => $contactSingular,
            'contactName' => $contactName,
            'currentStatus' => $currentStatus,
            'primaryBusinessContext' => $primaryBusinessContext,
            'businessRelationships' => $businessRelationships,
            'businessLabel' => $businessLabel,
            'usesRelationshipStage' => $usesRelationshipStage,
            'showContactStatusProgression' => $showContactStatusProgression,
            'hasProgression' => $hasProgression,
            'progressionType' => $progressionType,
            'progressionLabel' => $progressionLabel,
            'businessSource' => $businessSource,
            'businessSubsource' => $businessSubsource,
            'conversationItems' => $conversationItems,
            'latestInboundReply' => $latestInboundReply,
            'latestAutomatedResponse' => $latestAutomatedResponse,
            'conversationReply' => $conversationReply,
            'conversationTimeline' => $conversationTimeline,
            'contactTags' => $contactTags,
            'clientTimezone' => $clientTimezone,
            'defaultContactTaskDueAt' => $defaultContactTaskDueAt,
            'openTasks' => $openTasks,
            'nextTask' => $openTasks->first(),
            'upNextTask' => $openTasks->skip(1)->first(),
            'defaultActivityTab' => $openTasks->isNotEmpty() && module_enabled('tasks') ? 'tasks' : 'notes',
            'contactPanelClass' => 'space-y-6 '.module_tone('core', 'panel'),
            'nextStepTone' => module_enabled('tasks') ? module_tone('tasks', 'item') : 'bg-slate-50 border-slate-200',
            'activityPanelClass' => 'space-y-4 '.(module_enabled('tasks') ? module_tone('tasks', 'panel') : module_tone('core', 'panel')),
            'messagesPanelClass' => 'space-y-4 '.module_tone('messaging', 'panel'),
            'importStatusTreatmentState' => $importStatusTreatmentState,
        ];
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
        ContactImportHeaderMapper $contactImportHeaderMapper,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
        ContactImportPostProcessorRegistry $postProcessorRegistry,
    ): View|RedirectResponse {
        $validated = $request->validate([
            'mode' => [
                'nullable',
                'string',
                Rule::in([self::IMPORT_MODE_ADD, self::IMPORT_MODE_UPDATE]),
            ],
            'csv' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
        ]);

        $importMode = $this->normalizeImportMode($validated['mode'] ?? null);
        $originalFilename = $validated['csv']->getClientOriginalName();
        $storedPath = $validated['csv']->store('imports', 'local');

        $request->session()->put(
            $this->importOriginalFilenameSessionKey($storedPath),
            $originalFilename,
        );
        $request->session()->put(
            $this->importModeSessionKey($storedPath),
            $importMode,
        );

        return $this->renderImportPreview(
            request: $request,
            storedPath: $storedPath,
            contactImportRegistry: $contactImportRegistry,
            contactImportHeaderMapper: $contactImportHeaderMapper,
            contactImportProfileRegistry: $contactImportProfileRegistry,
            treatmentRegistry: $treatmentRegistry,
            postProcessorRegistry: $postProcessorRegistry,
        );
    }

    public function reviewImport(
        Request $request,
        ContactImportRegistry $contactImportRegistry,
        ContactImportHeaderMapper $contactImportHeaderMapper,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
        ContactImportPostProcessorRegistry $postProcessorRegistry,
    ): View|RedirectResponse {
        $storedPath = $this->nonEmptyString($request->query('csv_path'));

        if ($storedPath === null
            || ! $this->stagedImportBelongsToSession($request, $storedPath)
        ) {
            return redirect()
                ->route('crm.contacts.import')
                ->withErrors([
                    'csv' => 'That import preview is no longer available. Upload the CSV again.',
                ]);
        }

        return $this->renderImportPreview(
            request: $request,
            storedPath: $storedPath,
            contactImportRegistry: $contactImportRegistry,
            contactImportHeaderMapper: $contactImportHeaderMapper,
            contactImportProfileRegistry: $contactImportProfileRegistry,
            treatmentRegistry: $treatmentRegistry,
            postProcessorRegistry: $postProcessorRegistry,
        );
    }

    private function renderImportPreview(
        Request $request,
        string $storedPath,
        ContactImportRegistry $contactImportRegistry,
        ContactImportHeaderMapper $contactImportHeaderMapper,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
        ContactImportPostProcessorRegistry $postProcessorRegistry,
    ): View|RedirectResponse {
        if (! Storage::disk('local')->exists($storedPath)) {
            return redirect()
                ->route('crm.contacts.import')
                ->withErrors([
                    'csv' => 'The staged CSV file could not be found. Upload the CSV again.',
                ]);
        }

        $originalFilename = $request->session()->get(
            $this->importOriginalFilenameSessionKey($storedPath),
        );

        if (! is_string($originalFilename) || trim($originalFilename) === '') {
            return redirect()
                ->route('crm.contacts.import')
                ->withErrors([
                    'csv' => 'The staged CSV no longer has its import context. Upload the CSV again.',
                ]);
        }

        $importMode = $this->normalizeImportMode(
            $request->session()->get($this->importModeSessionKey($storedPath)),
        );

        $handle = fopen(Storage::disk('local')->path($storedPath), 'r');

        if ($handle === false) {
            return redirect()
                ->route('crm.contacts.import')
                ->withErrors(['csv' => 'Unable to read the staged CSV file.']);
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers) || $headers === []) {
            fclose($handle);

            return redirect()
                ->route('crm.contacts.import')
                ->withErrors(['csv' => 'The uploaded CSV does not contain a valid header row.']);
        }

        $headers = collect($this->normalizeCsvHeaders($headers));

        if ($headers->isEmpty()) {
            fclose($handle);

            return redirect()
                ->route('crm.contacts.import')
                ->withErrors(['csv' => 'The uploaded CSV header row is empty.']);
        }

        $profileKey = $request->session()->get(
            $this->importProfileKeySessionKey($storedPath),
        );
        $importProfile = is_string($profileKey) && trim($profileKey) !== ''
            ? $contactImportProfileRegistry->get($profileKey)
            : $contactImportProfileRegistry->findByFilename($originalFilename);

        if ($importProfile !== null) {
            $request->session()->put(
                $this->importProfileKeySessionKey($storedPath),
                $importProfile->key,
            );
        } else {
            $request->session()->forget($this->importProfileKeySessionKey($storedPath));
        }

        $profileSuggestedMapping = $importProfile !== null
            ? $contactImportProfileRegistry->suggestedMapping($importProfile, $headers->all())
            : [];
        $suggestedMapping = $contactImportHeaderMapper->suggest(
            headers: $headers->all(),
            preferred: $profileSuggestedMapping,
        );
        $operatorPostImportConfig = $importMode === self::IMPORT_MODE_ADD
            ? $postProcessorRegistry->operatorInputConfig(
                $importProfile?->postImport ?? [],
            )
            : [];
        $postImportSummaries = $postProcessorRegistry->summaries(
            $operatorPostImportConfig,
        );
        $postImportInputs = $postProcessorRegistry->inputDefinitions(
            $operatorPostImportConfig,
        );

        $primaryImportFieldKeys = array_values(array_unique([
            ...$contactImportRegistry->requiredFieldKeys(),
            ...array_keys($suggestedMapping),
        ]));
        $hasAdvancedImportFields = count($primaryImportFieldKeys) < count($contactImportRegistry->fieldKeys());
        $treatmentDefinitions = $treatmentRegistry->definitions(
            allowedTargetKeys: $importProfile?->treatmentTargets,
        );
        $treatmentDefaults = [];

        foreach ($treatmentDefinitions as $definition) {
            $sourceColumn = $definition->suggestedSourceFieldKey !== null
                ? ($suggestedMapping[$definition->suggestedSourceFieldKey] ?? null)
                : null;

            $treatmentDefaults[$definition->key] = [
                'mode' => is_string($sourceColumn) && $sourceColumn !== ''
                    ? 'column'
                    : 'none',
                'source_column' => is_string($sourceColumn)
                    ? $sourceColumn
                    : '',
            ];
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
            'treatmentDefinitions' => $treatmentDefinitions,
            'treatmentDefaults' => $treatmentDefaults,
            'importProfile' => $importProfile,
            'suggestedMapping' => $suggestedMapping,
            'postImportSummaries' => $postImportSummaries,
            'postImportInputs' => $postImportInputs,
            'importMode' => $importMode,
            'primaryImportFieldKeys' => $primaryImportFieldKeys,
            'hasAdvancedImportFields' => $hasAdvancedImportFields,
        ]);
    }

    public function processImport(
        Request $request,
        ContactImportRegistry $contactImportRegistry,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
        ContactImportPostProcessorRegistry $postProcessorRegistry,
    ): RedirectResponse {
        try {
            return $this->processImportRequest(
                request: $request,
                contactImportRegistry: $contactImportRegistry,
                contactImportProfileRegistry: $contactImportProfileRegistry,
                treatmentRegistry: $treatmentRegistry,
                postProcessorRegistry: $postProcessorRegistry,
            );
        } catch (ValidationException $exception) {
            $csvPath = $this->nonEmptyString($request->input('csv_path'));

            $redirect = $csvPath !== null
                && $this->stagedImportBelongsToSession($request, $csvPath)
                && Storage::disk('local')->exists($csvPath)
                    ? redirect()->route('crm.contacts.import.review', [
                        'csv_path' => $csvPath,
                    ])
                    : redirect()->route('crm.contacts.import');

            return $redirect
                ->withErrors($exception->errors())
                ->withInput();
        }
    }

    private function processImportRequest(
        Request $request,
        ContactImportRegistry $contactImportRegistry,
        ContactImportProfileRegistry $contactImportProfileRegistry,
        ContactImportTreatmentRegistry $treatmentRegistry,
        ContactImportPostProcessorRegistry $postProcessorRegistry,
    ): RedirectResponse {
        $rules = [
            'csv_path' => ['required', 'string'],
            'mapping' => ['required', 'array'],
            'treatments' => ['nullable', 'array'],
            'post_import_inputs' => ['nullable', 'array'],
        ];

        foreach ($contactImportRegistry->fieldKeys() as $field) {
            $rules["mapping.{$field}"] = in_array(
                $field,
                $contactImportRegistry->requiredFieldKeys(),
                true,
            )
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

        try {
            $headers = fgetcsv($handle);

            if (! is_array($headers) || $headers === []) {
                throw ValidationException::withMessages([
                    'csv_path' => 'The uploaded CSV does not contain a valid header row.',
                ]);
            }

            $headers = $this->normalizeCsvHeaders($headers);
            $firstDataOffset = ftell($handle);

            if (! is_int($firstDataOffset)) {
                throw ValidationException::withMessages([
                    'csv_path' => 'The uploaded CSV could not be checkpointed for background processing.',
                ]);
            }

            $totalRows = 0;

            while (fgetcsv($handle) !== false) {
                $totalRows++;
            }
        } finally {
            fclose($handle);
        }

        $importMode = $this->normalizeImportMode(
            $request->session()->get($this->importModeSessionKey($csvPath)),
        );
        $profileKey = $request->session()->get(
            $this->importProfileKeySessionKey($csvPath),
        );
        $importProfile = is_string($profileKey) && trim($profileKey) !== ''
            ? $contactImportProfileRegistry->get($profileKey)
            : null;
        $profileDefaults = $importMode === self::IMPORT_MODE_ADD
            ? ($importProfile?->defaults ?? [])
            : [];
        $postImportConfig = $importMode === self::IMPORT_MODE_ADD
            ? $postProcessorRegistry->withSubmittedInputs(
                configured: $postProcessorRegistry->operatorInputConfig(
                    $importProfile?->postImport ?? [],
                ),
                submitted: is_array($validated['post_import_inputs'] ?? null)
                    ? $validated['post_import_inputs']
                    : [],
            )
            : [];
        $postImportSummaries = $postProcessorRegistry->summaries(
            $postImportConfig,
        );

        $allowedMappingFields = array_values(array_unique([
            ...$contactImportRegistry->fieldKeys(),
            'import_status',
        ]));

        $mapping = collect($validated['mapping'])
            ->filter(
                fn (mixed $value): bool => is_string($value)
                    && trim($value) !== '',
            )
            ->only($allowedMappingFields)
            ->toArray();

        $treatmentSelections = $treatmentRegistry->normalizeSubmitted(
            submitted: is_array($validated['treatments'] ?? null)
                ? $validated['treatments']
                : [],
            headers: $headers,
            allowedTargetKeys: $importProfile?->treatmentTargets,
        );
        $postImportStats = $this->initializePostImportStats(
            $postImportConfig,
        );

        $importedAt = now();
        $originalFilename = $request->session()->get(
            $this->importOriginalFilenameSessionKey($csvPath),
        );

        $importBatch = DB::transaction(function () use (
            $request,
            $csvPath,
            $importMode,
            $headers,
            $mapping,
            $importProfile,
            $profileDefaults,
            $treatmentRegistry,
            $treatmentSelections,
            $postImportConfig,
            $postImportSummaries,
            $postImportStats,
            $importedAt,
            $originalFilename,
            $totalRows,
            $firstDataOffset,
        ): ContactImportBatch {
            $treatmentSelections = $treatmentRegistry->materializeSelections(
                $treatmentSelections,
            );
            $treatmentStats = $this->initializeTreatmentStats(
                $treatmentSelections,
            );

            $importBatch = ContactImportBatch::query()->create([
                'name' => (
                    $importMode === self::IMPORT_MODE_UPDATE
                        ? 'Contact update import '
                        : 'Contact import '
                ).$importedAt->format('M j, Y g:i A'),
                'source' => 'crm_csv',
                'original_filename' => is_string($originalFilename)
                    && trim($originalFilename) !== ''
                        ? basename(trim($originalFilename))
                        : basename($csvPath),
                'status' => ContactImportBatch::STATUS_PENDING,
                'imported_at' => $importedAt,
                'contact_count' => $totalRows,
                'successful_count' => 0,
                'failed_count' => 0,
                'meta' => [
                    'import_mode' => $importMode,
                    'mapping' => $mapping,
                    'headers' => $headers,
                    'profile_key' => $importProfile?->key,
                    'profile_defaults' => $profileDefaults,
                    'treatment_selections' => $treatmentRegistry->selectionsMeta(
                        $treatmentSelections,
                    ),
                    'post_import_config' => $postImportConfig,
                    'post_import_summaries' => $postImportSummaries,
                ],
            ]);

            ContactImportRun::query()->create([
                'contact_import_batch_id' => $importBatch->getKey(),
                'status' => ContactImportRun::STATUS_PENDING,
                'csv_path' => $csvPath,
                'import_mode' => $importMode,
                'headers' => $headers,
                'mapping' => $mapping,
                'profile_key' => $importProfile?->key,
                'profile_defaults' => $profileDefaults,
                'treatment_selections' => $treatmentRegistry->selectionsMeta(
                    $treatmentSelections,
                ),
                'post_import_config' => $postImportConfig,
                'processing_stats' => [
                    'created_count' => 0,
                    'updated_count' => 0,
                    'skipped_count' => 0,
                    'phone_warning_count' => 0,
                    'update_not_found_count' => 0,
                    'treatment_stats' => $treatmentStats,
                    'post_import_stats' => $postImportStats,
                ],
                'actor_user_id' => $request->user()?->getKey(),
                'total_rows' => $totalRows,
                'processed_rows' => 0,
                'next_row_number' => 2,
                'next_byte_offset' => $firstDataOffset,
                'queued_at' => $importedAt,
            ]);

            ProcessContactImportBatchChunkJob::dispatch(
                $importBatch->getKey(),
            )->afterCommit();

            return $importBatch;
        }, 3);

        $request->session()->forget([
            $this->importOriginalFilenameSessionKey($csvPath),
            $this->importProfileKeySessionKey($csvPath),
            $this->importModeSessionKey($csvPath),
        ]);

        $importBatch->refresh();

        if ($importBatch->status === ContactImportBatch::STATUS_COMPLETED) {
            return redirect()
                ->route('crm.contacts.index')
                ->with(
                    'success',
                    $this->completedImportMessage($importBatch),
                );
        }

        if ($importBatch->status === ContactImportBatch::STATUS_FAILED) {
            return redirect()
                ->route('crm.contacts.import-batches.show', $importBatch)
                ->with(
                    'error',
                    data_get(
                        $importBatch->meta,
                        'failure.message',
                        'The contact import failed.',
                    ),
                );
        }

        return redirect()
            ->route('crm.contacts.import-batches.show', $importBatch)
            ->with(
                'success',
                'Import queued. Processing will continue in the background; you can leave this page safely.',
            );
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
     * @param array<string, array<string, mixed>> $config
     * @return array<string, array{applied_count: int, partial_count: int, skipped_count: int, blocked_count: int, failed_count: int}>
     */
    private function initializePostImportStats(array $config): array
    {
        $stats = [];

        foreach (array_keys($config) as $processorKey) {
            $stats[$processorKey] = [
                'applied_count' => 0,
                'partial_count' => 0,
                'skipped_count' => 0,
                'blocked_count' => 0,
                'failed_count' => 0,
            ];
        }

        return $stats;
    }

    private function completedImportMessage(
        ContactImportBatch $importBatch,
    ): string {
        $meta = is_array($importBatch->meta) ? $importBatch->meta : [];
        $importMode = $this->normalizeImportMode(
            $meta['import_mode'] ?? null,
        );
        $created = max(0, (int) ($meta['created_count'] ?? 0));
        $updated = max(0, (int) ($meta['updated_count'] ?? 0));
        $skipped = max(0, (int) $importBatch->failed_count);
        $phoneWarnings = max(
            0,
            (int) ($meta['phone_warning_count'] ?? 0),
        );
        $updateNotFound = max(
            0,
            (int) ($meta['update_not_found_count'] ?? 0),
        );
        $treatmentMeta = is_array($meta['treatments'] ?? null)
            ? $meta['treatments']
            : [];
        $postImportMeta = is_array($meta['post_import'] ?? null)
            ? $meta['post_import']
            : [];

        if ($importMode === self::IMPORT_MODE_UPDATE) {
            return sprintf(
                'Update import complete. %d updated, %d not found, %d other skipped%s%s.',
                $updated,
                $updateNotFound,
                max(0, $skipped - $updateNotFound),
                $phoneWarnings > 0
                    ? ", {$phoneWarnings} phone values were ignored"
                    : '',
                $this->treatmentReviewRequired($treatmentMeta)
                    ? ', treatment review needed for unmapped values'
                    : '',
            );
        }

        return sprintf(
            'Import complete. %d created, %d updated, %d skipped%s%s%s.',
            $created,
            $updated,
            $skipped,
            $phoneWarnings > 0
                ? ", {$phoneWarnings} phone values were ignored"
                : '',
            $this->treatmentReviewRequired($treatmentMeta)
                ? ', treatment review needed for unmapped values'
                : '',
            $this->postImportReviewRequired($postImportMeta)
                ? ', post-import review needed'
                : '',
        );
    }

    /**
     * @param array<string, mixed> $treatmentMeta
     */
    private function treatmentReviewRequired(array $treatmentMeta): bool
    {
        foreach ($treatmentMeta as $meta) {
            if (is_array($meta)
                && ($meta['review_required'] ?? false) === true
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $postImportMeta */
    private function postImportReviewRequired(array $postImportMeta): bool
    {
        return ($postImportMeta['review_required'] ?? false) === true;
    }

    private function stagedImportBelongsToSession(
        Request $request,
        string $csvPath,
    ): bool {
        return $request->session()->has(
            $this->importOriginalFilenameSessionKey($csvPath),
        ) && $request->session()->has(
            $this->importModeSessionKey($csvPath),
        );
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<int, mixed> $headers
     * @return array<int, string>
     */
    private function normalizeCsvHeaders(array $headers): array
    {
        return collect($headers)
            ->map(function (mixed $header, int $index): string {
                $header = (string) $header;

                if ($index === 0) {
                    $header = preg_replace(
                        '/^\xEF\xBB\xBF/',
                        '',
                        $header,
                    ) ?? $header;
                }

                return trim($header);
            })
            ->filter(fn (string $header): bool => $header !== '')
            ->values()
            ->all();
    }

    private function importOriginalFilenameSessionKey(string $csvPath): string
    {
        return 'contact_imports.'.hash(
            'sha256',
            $csvPath,
        ).'.original_filename';
    }

    private function importProfileKeySessionKey(string $csvPath): string
    {
        return 'contact_imports.'.hash(
            'sha256',
            $csvPath,
        ).'.profile_key';
    }

    private function importModeSessionKey(string $csvPath): string
    {
        return 'contact_imports.'.hash(
            'sha256',
            $csvPath,
        ).'.mode';
    }

    private function normalizeImportMode(mixed $value): string
    {
        if (! is_string($value)) {
            return self::IMPORT_MODE_ADD;
        }

        $value = strtolower(trim($value));

        return in_array($value, [
            self::IMPORT_MODE_ADD,
            self::IMPORT_MODE_UPDATE,
        ], true)
            ? $value
            : self::IMPORT_MODE_ADD;
    }
}