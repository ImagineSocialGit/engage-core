<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\AssignMessageTemplatePresetAction;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Requests\UpdateWebinarMessageTemplateRequest;
use App\Modules\Webinars\Services\WebinarMessageAreaRegistry;
use App\Modules\Webinars\Services\WebinarMessageReadinessService;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebinarMessageTemplateController extends Controller
{
    public function index(
        Request $request,
        WebinarMessageReadinessService $messageReadiness,
        WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
        WebinarMessageAreaRegistry $messageAreaRegistry,
    ): View {
        $contexts = $messageAreaRegistry->enabled()
            ->map(fn ($messageArea): array => $messageArea->toArray());
        $catalogEntries = $this->webinarCatalogEntries($contexts);
        $currentAssignments = $this->currentAssignments($catalogEntries);
        $templateOptions = $this->templateOptions($catalogEntries);
        $readiness = $messageReadiness->resolve();
        $profiles = $messageReadiness->profilesInUse()['profiles'];

        $sections = $this->sections(
            contexts: $contexts,
            catalogEntries: $catalogEntries,
            currentAssignments: $currentAssignments,
            templateOptions: $templateOptions,
            profiles: $profiles,
            scheduleProfileDefinitionResolver: $scheduleProfileDefinitionResolver,
        )
            ->map(function (array $section, string $sectionKey) use ($readiness): array {
                $section['readiness'] = $readiness['contexts'][$sectionKey] ?? null;

                return $section;
            });
        $selectedSectionKey = $this->selectedSectionKey($request, $sections);

        return view('crm.webinars.message-templates.index', [
            'title' => 'Webinar Messages',
            'heading' => 'Webinar Messages',
            'sections' => $sections,
            'selectedSectionKey' => $selectedSectionKey,
            'currentAssignments' => $currentAssignments,
            'templateOptions' => $templateOptions,
            'readiness' => $readiness,
        ]);
    }

    public function update(
        UpdateWebinarMessageTemplateRequest $request,
        AssignMessageTemplatePresetAction $assignTemplatePreset,
        WebinarMessageAreaRegistry $messageAreaRegistry,
    ): RedirectResponse {
        $preset = $request->messageTemplatePreset();
        $catalogEntry = $request->messageTemplateCatalogEntry();
        $area = $messageAreaRegistry->get((string) $request->validated('context_key'));

        if (
            ! $area?->enabled
            || ! $area->isTemplate()
            || ! in_array($catalogEntry->usage_type, $area->usageTypes, true)
        ) {
            throw ValidationException::withMessages([
                'context_key' => 'This Webinar message area is disabled or does not own the selected template.',
            ]);
        }

        $definitionKey = $this->definitionKeyForEntry($catalogEntry);

        $assignTemplatePreset->handle(
            preset: $preset,
            channel: $request->validated('channel'),
            purpose: $request->validated('purpose'),
            scope: $request->validated('scope'),
            surface: $request->validated('surface'),
            messageType: $request->validated('message_type'),
            definitionKey: $definitionKey,
            sourceConfigPath: $catalogEntry->source_config_path,
            meta: [
                'source' => 'crm_webinar_message_template_assignment',
                'webinars' => [
                    'context_key' => $request->validated('context_key'),
                    'catalog_entry_id' => $catalogEntry->getKey(),
                    'definition_key' => $definitionKey,
                ],
            ],
        );

        return redirect()
            ->route('crm.webinars.message-templates.index', array_filter([
                'section' => $request->validated('context_key'),
                'context' => $request->validated('message_type'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->with('status', 'Webinar message template updated.');
    }

    /**
     * @return Collection<int, MessageTemplateCatalogEntry>
     */
    private function webinarCatalogEntries(Collection $contexts): Collection
    {
        $usageTypes = $contexts
            ->flatMap(fn (array $context): array => $context['usage_types'])
            ->unique()
            ->values()
            ->all();

        if ($usageTypes === []) {
            return collect();
        }

        return MessageTemplateCatalogEntry::query()
            ->active()
            ->where('module_key', 'webinars')
            ->whereIn('usage_type', $usageTypes)
            ->whereHas('messageTemplatePreset', fn ($query) => $query->active())
            ->with('messageTemplatePreset.catalogEntries')
            ->orderBy('purpose')
            ->orderBy('scope')
            ->orderBy('group_label')
            ->orderBy('item_order')
            ->orderBy('item_label')
            ->get()
            ->filter(fn (MessageTemplateCatalogEntry $entry): bool => $entry->messageTemplatePreset instanceof MessageTemplatePreset)
            ->values();
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @return Collection<string, MessageTemplatePresetAssignment>
     */
    private function currentAssignments(Collection $catalogEntries): Collection
    {
        if ($catalogEntries->isEmpty()) {
            return collect();
        }

        $channels = $catalogEntries->pluck('channel')->unique()->values()->all();
        $purposes = $catalogEntries->pluck('purpose')->unique()->values()->all();
        $scopes = $catalogEntries->pluck('scope')->unique()->values()->all();
        $messageTypes = $catalogEntries
            ->pluck('messageTemplatePreset.message_type')
            ->filter()
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = MessageTemplatePresetAssignment::query()
            ->active()
            ->with(['messageTemplatePreset.catalogEntries'])
            ->whereIn('channel', $channels)
            ->whereIn('purpose', $purposes)
            ->whereIn('scope', $scopes)
            ->whereIn('surface', ['webinar_registrations', 'webinar_waitlists'])
            ->whereIn('message_type', $messageTypes)
            ->whereNull('campaign_key')
            ->whereNull('campaign_step')
            ->whereNull('context_type')
            ->whereNull('context_id')
            ->orderByDesc('id')
            ->get()
            ->filter(fn (MessageTemplatePresetAssignment $assignment): bool => (bool) $assignment->messageTemplatePreset?->isActive());

        return $assignments
            ->unique(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentKey(
                channel: $assignment->channel,
                purpose: $assignment->purpose,
                scope: $assignment->scope,
                surface: $assignment->surface,
                messageType: $assignment->message_type,
                definitionKey: $this->assignmentDefinitionKey($assignment),
            ))
            ->keyBy(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentKey(
                channel: $assignment->channel,
                purpose: $assignment->purpose,
                scope: $assignment->scope,
                surface: $assignment->surface,
                messageType: $assignment->message_type,
                definitionKey: $this->assignmentDefinitionKey($assignment),
            ));
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @return Collection<string, Collection<int, MessageTemplateCatalogEntry>>
     */
    private function templateOptions(Collection $catalogEntries): Collection
    {
        return $catalogEntries
            ->groupBy(fn (MessageTemplateCatalogEntry $entry): string => $this->entryContextKey($entry))
            ->map(fn (Collection $entries): Collection => $entries
                ->filter(fn (MessageTemplateCatalogEntry $entry): bool => (bool) $entry->messageTemplatePreset?->isActive())
                ->sortBy([
                    ['item_order', 'asc'],
                    ['item_label', 'asc'],
                    ['messageTemplatePreset.name', 'asc'],
                ])
                ->values());
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @param Collection<string, MessageTemplatePresetAssignment> $currentAssignments
     * @param Collection<string, Collection<int, MessageTemplateCatalogEntry>> $templateOptions
     * @return Collection<string, array{key: string, label: string, description: string, entries: Collection<int, array<string, mixed>>}>
     */
    private function sections(
        Collection $contexts,
        Collection $catalogEntries,
        Collection $currentAssignments,
        Collection $templateOptions,
        Collection $profiles,
        WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ): Collection {
        return $contexts
            ->map(function (array $context, string $contextKey) use (
                $catalogEntries,
                $currentAssignments,
                $templateOptions,
                $profiles,
                $scheduleProfileDefinitionResolver,
            ): array {
                $entries = $catalogEntries
                    ->filter(fn (MessageTemplateCatalogEntry $entry): bool => in_array($entry->usage_type, $context['usage_types'], true))
                    ->unique(fn (MessageTemplateCatalogEntry $entry): string => $this->entryContextKey($entry))
                    ->map(function (MessageTemplateCatalogEntry $entry) use (
                        $contextKey,
                        $currentAssignments,
                        $templateOptions,
                        $profiles,
                        $scheduleProfileDefinitionResolver,
                    ): array {
                        $preset = $entry->messageTemplatePreset;
                        $surface = $entry->surface ?: $this->surfaceForEntry($entry);
                        $messageType = $preset?->message_type ?? data_get($entry->meta, 'message_type') ?? '';
                        $definitionKey = $this->definitionKeyForEntry($entry);
                        $assignmentKey = $this->assignmentKey(
                            channel: $entry->channel,
                            purpose: $entry->purpose,
                            scope: $entry->scope,
                            surface: $surface,
                            messageType: $messageType,
                            definitionKey: $definitionKey,
                        );
                        $assignment = $currentAssignments->get($assignmentKey);
                        $selectedPreset = $assignment?->messageTemplatePreset ?? $preset;
                        $options = $templateOptions->get($this->entryContextKey($entry), collect());

                        return [
                            'context_key' => $contextKey,
                            'catalog_entry' => $entry,
                            'fallback_preset' => $preset,
                            'assignment' => $assignment,
                            'selected_preset' => $selectedPreset,
                            'options' => $options,
                            'assignment_key' => $assignmentKey,
                            'surface' => $surface,
                            'message_type' => $messageType,
                            'definition_key' => $definitionKey,
                            'schedule_label' => $this->effectiveScheduleLabel(
                                preset: $preset,
                                entry: $entry,
                                profiles: $profiles,
                                scheduleProfileDefinitionResolver: $scheduleProfileDefinitionResolver,
                            ),
                        ];
                    })
                    ->values();

                return [
                    'key' => $contextKey,
                    'label' => $context['label'],
                    'description' => $context['description'],
                    'managed_by_messaging' => (bool) ($context['managed_by_messaging'] ?? false),
                    'entries' => $entries,
                ];
            });
    }

    /**
     * @param Collection<string, array{key: string, label: string, description: string, entries: Collection<int, array<string, mixed>>}> $sections
     */
    private function selectedSectionKey(Request $request, Collection $sections): string
    {
        $requested = is_string($request->query('section')) ? trim((string) $request->query('section')) : '';

        if ($requested !== '' && $sections->has($requested)) {
            return $requested;
        }

        return (string) ($sections->keys()->first() ?? 'confirmation');
    }

    private function entryContextKey(MessageTemplateCatalogEntry $entry): string
    {
        $messageType = $entry->messageTemplatePreset?->message_type ?? data_get($entry->meta, 'message_type') ?? '';

        return $this->assignmentKey(
            channel: $entry->channel,
            purpose: $entry->purpose,
            scope: $entry->scope,
            surface: $entry->surface ?: $this->surfaceForEntry($entry),
            messageType: (string) $messageType,
            definitionKey: $this->definitionKeyForEntry($entry),
        );
    }

    private function assignmentKey(
        string $channel,
        string $purpose,
        string $scope,
        ?string $surface,
        string $messageType,
        ?string $definitionKey,
    ): string {
        return implode(':', [
            $this->normalizeSegment($channel),
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
            $surface !== null ? $this->normalizeSegment($surface) : '',
            $this->normalizeSegment($messageType),
            $this->normalizeSegment((string) $definitionKey),
        ]);
    }

    private function assignmentDefinitionKey(MessageTemplatePresetAssignment $assignment): ?string
    {
        $definitionKey = $this->normalizeNullableSegment($assignment->definition_key)
            ?? $this->normalizeNullableSegment(data_get($assignment->meta, 'definition_key'));

        if ($definitionKey !== null) {
            return $definitionKey;
        }

        $sourceConfigPath = is_string($assignment->source_config_path)
            ? trim($assignment->source_config_path)
            : '';

        if ($sourceConfigPath !== '') {
            $definition = config($sourceConfigPath);

            if (is_array($definition)) {
                $definitionKey = $this->normalizeNullableSegment($definition['key'] ?? null);

                if ($definitionKey !== null) {
                    return $definitionKey;
                }
            }
        }

        $configuredKeys = $this->configuredDefinitionKeysForMessageType(
            channel: (string) $assignment->channel,
            purpose: (string) $assignment->purpose,
            scope: (string) $assignment->scope,
            messageType: (string) $assignment->message_type,
        );

        return count($configuredKeys) === 1 ? $configuredKeys[0] : null;
    }

    /**
     * @return array<int, string>
     */
    private function configuredDefinitionKeysForMessageType(
        string $channel,
        string $purpose,
        string $scope,
        string $messageType,
    ): array {
        $definitions = config(implode('.', [
            'messaging',
            $this->normalizeSegment($channel),
            'definitions',
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
        ]));

        if (! is_array($definitions)) {
            return [];
        }

        $messageType = $this->normalizeSegment($messageType);
        $keys = [];

        foreach ($definitions as $sourceMessageType => $definition) {
            if ($sourceMessageType === 'campaigns' || ! is_string($sourceMessageType) || ! is_array($definition)) {
                continue;
            }

            $runtimeMessageType = Str::singular($this->normalizeSegment($sourceMessageType));

            if ($runtimeMessageType !== $messageType) {
                continue;
            }

            $isList = array_is_list($definition);
            $definitionList = $isList ? $definition : [$definition];

            foreach ($definitionList as $index => $nestedDefinition) {
                if (! is_array($nestedDefinition) || ! ($nestedDefinition['enabled'] ?? true)) {
                    continue;
                }

                $keys[] = $this->normalizeNullableSegment($nestedDefinition['key'] ?? null)
                    ?? ($isList ? $runtimeMessageType.'_'.((int) $index + 1) : $runtimeMessageType);
            }
        }

        return array_values(array_unique($keys));
    }

    private function definitionKeyForEntry(MessageTemplateCatalogEntry $entry): ?string
    {
        $definitionKey = $this->normalizeNullableSegment(data_get($entry->meta, 'definition_key'))
            ?? $this->normalizeNullableSegment(data_get($entry->messageTemplatePreset?->meta, 'seed.definition_key'));

        if ($definitionKey !== null) {
            return $definitionKey;
        }

        foreach ([$entry->source_config_path, $entry->messageTemplatePreset?->source_config_path] as $sourceConfigPath) {
            if (! is_string($sourceConfigPath) || trim($sourceConfigPath) === '') {
                continue;
            }

            $definition = config(trim($sourceConfigPath));
            $definitionKey = is_array($definition)
                ? $this->normalizeNullableSegment($definition['key'] ?? null)
                : null;

            if ($definitionKey !== null) {
                return $definitionKey;
            }
        }

        return null;
    }

    private function surfaceForEntry(MessageTemplateCatalogEntry $entry): string
    {
        return $entry->scope === 'webinar_waitlist'
            ? 'webinar_waitlists'
            : 'webinar_registrations';
    }

    /**
     * @param Collection<int, WebinarScheduleProfile> $profiles
     */
    private function effectiveScheduleLabel(
        ?MessageTemplatePreset $preset,
        MessageTemplateCatalogEntry $entry,
        Collection $profiles,
        WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ): ?string {
        if (! $preset instanceof MessageTemplatePreset) {
            return null;
        }

        if ($profiles->isEmpty()) {
            return $this->scheduleLabel($preset->schedule);
        }

        $surface = $entry->surface ?: $this->surfaceForEntry($entry);
        $labels = [];

        foreach ($profiles as $profile) {
            $definitions = $scheduleProfileDefinitionResolver->applyProfile(
                profile: $profile,
                definitions: [$preset->toMessageDefinition()],
                dispatchKeys: $preset->dispatchKeys(),
                surface: $surface,
            );

            foreach ($definitions as $definition) {
                $resolvedBehavior = is_array($definition['resolved_behavior'] ?? null)
                    ? $definition['resolved_behavior']
                    : [];

                $label = $this->scheduleLabel(
                    is_array($resolvedBehavior['schedule'] ?? null)
                        ? $resolvedBehavior['schedule']
                        : null,
                );

                if ($label === null) {
                    $timing = $resolvedBehavior['timing'] ?? null;

                    $label = $timing === 'immediate'
                        ? 'Immediate'
                        : null;
                }

                if ($label !== null) {
                    $labels[] = $label;
                }
            }
        }

        $labels = array_values(array_unique($labels));

        if (count($labels) === 1) {
            return $labels[0];
        }

        if (count($labels) > 1) {
            return 'Varies by schedule profile';
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $schedule
     */
    private function scheduleLabel(?array $schedule): ?string
    {
        if (! is_array($schedule)) {
            return null;
        }

        $type = $schedule['type'] ?? null;

        if ($type === 'next_day_at') {
            $time = $schedule['time'] ?? null;

            if (! is_string($time) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                return null;
            }

            return 'Next day at '.$time;
        }

        $minutes = $schedule['minutes'] ?? null;

        if (! is_int($minutes)) {
            return null;
        }

        if ($type === 'delay') {
            return $minutes === 0
                ? 'Immediate'
                : 'After '.$this->humanMinutes(abs($minutes));
        }

        if ($type === 'anchored') {
            if ($minutes === 0) {
                return 'At webinar start';
            }

            return $minutes < 0
                ? $this->humanMinutes(abs($minutes)).' before start'
                : $this->humanMinutes($minutes).' after start';
        }

        return null;
    }

    private function humanMinutes(int $minutes): string
    {
        if ($minutes % 1440 === 0) {
            $days = (int) ($minutes / 1440);

            return $days.' '.Str::plural('day', $days);
        }

        if ($minutes % 60 === 0) {
            $hours = (int) ($minutes / 60);

            return $hours.' '.Str::plural('hour', $hours);
        }

        return $minutes.' '.Str::plural('minute', $minutes);
    }

    private function normalizeNullableSegment(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $this->normalizeSegment($value);
    }

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
