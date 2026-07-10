<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\AssignMessageTemplatePresetAction;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Webinars\Models\WebinarScheduleProfile;
use App\Modules\Webinars\Requests\UpdateWebinarMessageTemplateRequest;
use App\Modules\Webinars\Services\WebinarMessageReadinessService;
use App\Modules\Webinars\Services\WebinarScheduleProfileDefinitionResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class WebinarMessageTemplateController extends Controller
{
    private const CONTEXTS = [
        'confirmation' => [
            'label' => 'Registration confirmations',
            'description' => 'Sent after someone registers, using the registration message timing already owned by Webinars.',
            'usage_types' => ['webinar_confirmation'],
        ],
        'registration_opt_in' => [
            'label' => 'Registration opt-in confirmations',
            'description' => 'Sent when someone grants webinar transactional messaging consent.',
            'usage_types' => ['webinar_opt_in'],
        ],
        'reminders' => [
            'label' => 'Reminder messages',
            'description' => 'Scheduled around the webinar start time. Changing the selected template affects future registrations only.',
            'usage_types' => ['webinar_reminder'],
        ],
        'waitlist' => [
            'label' => 'Waitlist availability messages',
            'description' => 'Sent when a new webinar becomes available for people waiting on a series.',
            'usage_types' => ['webinar_waitlist_alert'],
        ],
        'waitlist_opt_in' => [
            'label' => 'Waitlist opt-in confirmations',
            'description' => 'Sent when someone grants marketing messaging consent while joining a webinar waitlist.',
            'usage_types' => ['webinar_waitlist_opt_in'],
        ],
        'post_attended' => [
            'label' => 'Attended replay follow-up',
            'description' => 'Transactional replay follow-up for registrants marked as attended.',
            'usage_types' => ['webinar_post_attended'],
        ],
        'post_missed' => [
            'label' => 'Missed replay follow-up',
            'description' => 'Transactional replay follow-up for registrants marked as missed.',
            'usage_types' => ['webinar_post_missed'],
        ],
    ];

    public function index(
        Request $request,
        WebinarMessageReadinessService $messageReadiness,
        WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ): View {
        $catalogEntries = $this->webinarCatalogEntries();
        $currentAssignments = $this->currentAssignments($catalogEntries);
        $templateOptions = $this->templateOptions($catalogEntries);
        $readiness = $messageReadiness->resolve();
        $profiles = $messageReadiness->profilesInUse()['profiles'];

        $sections = $this->sections(
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
    ): RedirectResponse {
        $preset = $request->messageTemplatePreset();

        $assignTemplatePreset->handle(
            preset: $preset,
            channel: $request->validated('channel'),
            purpose: $request->validated('purpose'),
            scope: $request->validated('scope'),
            surface: $request->validated('surface'),
            messageType: $request->validated('message_type'),
            meta: [
                'source' => 'crm_webinar_message_template_assignment',
                'webinars' => [
                    'context_key' => $request->validated('context_key'),
                    'catalog_entry_id' => $request->integer('catalog_entry_id') ?: null,
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
    private function webinarCatalogEntries(): Collection
    {
        $usageTypes = collect(self::CONTEXTS)
            ->flatMap(fn (array $context): array => $context['usage_types'])
            ->unique()
            ->values()
            ->all();

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
            ))
            ->keyBy(fn (MessageTemplatePresetAssignment $assignment): string => $this->assignmentKey(
                channel: $assignment->channel,
                purpose: $assignment->purpose,
                scope: $assignment->scope,
                surface: $assignment->surface,
                messageType: $assignment->message_type,
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
        Collection $catalogEntries,
        Collection $currentAssignments,
        Collection $templateOptions,
        Collection $profiles,
        WebinarScheduleProfileDefinitionResolver $scheduleProfileDefinitionResolver,
    ): Collection {
        return collect(self::CONTEXTS)
            ->map(function (array $context, string $contextKey) use (
                $catalogEntries,
                $currentAssignments,
                $templateOptions,
                $profiles,
                $scheduleProfileDefinitionResolver,
            ): array {
                $entries = $catalogEntries
                    ->filter(fn (MessageTemplateCatalogEntry $entry): bool => in_array($entry->usage_type, $context['usage_types'], true))
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
                        $assignmentKey = $this->assignmentKey(
                            channel: $entry->channel,
                            purpose: $entry->purpose,
                            scope: $entry->scope,
                            surface: $surface,
                            messageType: $messageType,
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
        );
    }

    private function assignmentKey(
        string $channel,
        string $purpose,
        string $scope,
        ?string $surface,
        string $messageType,
    ): string {
        return implode(':', [
            $this->normalizeSegment($channel),
            $this->normalizeSegment($purpose),
            $this->normalizeSegment($scope),
            $surface !== null ? $this->normalizeSegment($surface) : '',
            $this->normalizeSegment($messageType),
        ]);
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

    private function normalizeSegment(string $value): string
    {
        return str_replace('-', '_', strtolower(trim($value)));
    }
}
