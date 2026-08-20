<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Actions\UpsertMessageTemplateCompositionLayerAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplateCompositionLayer;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Requests\UpdateMessageTemplateCompositionLayerRequest;
use App\Modules\Messaging\Requests\UpdateMessageTemplatePresetRequest;
use App\Modules\Messaging\Services\MessageTemplateCompositionEditorPresenter;
use App\Modules\Messaging\Services\MessageTemplateCompositionImpactResolver;
use App\Modules\Messaging\Services\MessageTemplateCompositionIdentityResolver;
use App\Modules\Messaging\Services\MessageTemplateCompositionResolver;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\MessageTemplateUsageResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MessageTemplatePresetController extends Controller
{
    public function index(
        Request $request,
        MessageTemplateUsageResolver $usageResolver,
        MessageTemplateTokenValidator $messageTemplateTokenValidator,
        MessageTemplateCompositionEditorPresenter $compositionPresenter,
    ): View {
        $catalogEntries = MessageTemplateCatalogEntry::query()
            ->active()
            ->whereHas('messageTemplatePreset', fn ($query) => $query->active())
            ->with([
                'messageTemplatePreset' => fn ($query) => $query
                    ->active()
                    ->withCount(['assignments as active_assignments_count' => fn ($query) => $query->active()]),
            ])
            ->orderBy('channel')
            ->orderBy('purpose')
            ->orderBy('module_label')
            ->orderBy('group_label')
            ->orderBy('item_order')
            ->orderBy('item_label')
            ->get()
            ->filter(fn (MessageTemplateCatalogEntry $entry): bool => $entry->messageTemplatePreset instanceof MessageTemplatePreset)
            ->values();

        $presets = $catalogEntries
            ->pluck('messageTemplatePreset')
            ->filter(fn (mixed $preset): bool => $preset instanceof MessageTemplatePreset)
            ->unique(fn (MessageTemplatePreset $preset): int => (int) $preset->getKey())
            ->values();

        $filterOptions = $this->filterOptions($catalogEntries);
        $filters = $this->filters($request, $filterOptions);
        $filteredCatalogEntries = $this->filteredCatalogEntries($catalogEntries, $filters);
        $catalogGroups = $this->catalogGroups($filteredCatalogEntries);
        $selectedGroup = $this->selectedGroup($request, $catalogGroups);
        $selectedGroupEntries = $selectedGroup['entries'] ?? collect();
        $selectedPreset = $this->selectedPreset($request, $selectedGroupEntries);
        $selectedCatalogEntry = $selectedPreset instanceof MessageTemplatePreset
            ? $selectedGroupEntries->first(
                fn (MessageTemplateCatalogEntry $entry): bool => (int) $entry->message_template_preset_id === (int) $selectedPreset->getKey(),
            )
            : null;

        $selectedTemplate = null;
        $currentTemplateVersion = null;
        $compositionState = [
            'effective_payload' => [],
            'baseline_payload' => [],
            'message_override' => null,
            'shared_layers' => collect(),
            'field_sources' => [],
        ];

        if ($selectedPreset instanceof MessageTemplatePreset) {
            $selectedPreset->load([
                'catalogEntries' => fn ($query) => $query->active()->orderBy('item_order')->orderBy('item_label'),
                'assignments' => fn ($query) => $query->active()->orderBy('surface')->orderBy('campaign_key')->orderBy('campaign_step')->orderBy('message_type'),
            ])->loadCount(['assignments as active_assignments_count' => fn ($query) => $query->active()]);

            $selectedTemplate = MessageTemplate::query()
                ->with('currentVersion')
                ->where('key', $selectedPreset->key)
                ->first();
            $currentTemplateVersion = $selectedTemplate?->currentVersion;

            if ($selectedTemplate instanceof MessageTemplate) {
                $compositionState = $compositionPresenter->forTemplate($selectedTemplate, $selectedPreset);
            } else {
                $compositionState['effective_payload'] = is_array($selectedPreset->payload)
                    ? $selectedPreset->payload
                    : [];
                $compositionState['baseline_payload'] = $compositionState['effective_payload'];
            }
        }

        $editablePayload = $selectedPreset
            ? $this->editablePayload($selectedPreset, $compositionState['effective_payload'])
            : [];

        return view('crm.messaging.message-templates.index', [
            'presets' => $presets,
            'catalogEntries' => $catalogEntries,
            'catalogGroups' => $catalogGroups,
            'selectedGroup' => $selectedGroup,
            'selectedGroupEntries' => $selectedGroupEntries,
            'selectedPreset' => $selectedPreset,
            'selectedCatalogEntry' => $selectedCatalogEntry,
            'selectedTemplate' => $selectedTemplate,
            'currentTemplateVersion' => $currentTemplateVersion,
            'filterOptions' => $filterOptions,
            'filters' => $filters,
            'editablePayload' => $editablePayload,
            'tokens' => $messageTemplateTokenValidator->tokensFromPayload($compositionState['effective_payload']),
            'usageSummaries' => $selectedPreset ? $usageResolver->forPreset($selectedPreset) : collect(),
            'sharedCompositionLayers' => $compositionState['shared_layers'],
            'messageOverrideLayer' => $compositionState['message_override'],
            'fieldSources' => $compositionState['field_sources'],
        ]);
    }

    public function update(
        UpdateMessageTemplatePresetRequest $request,
        MessageTemplatePreset $messageTemplatePreset,
        MessageTemplateTokenValidator $messageTemplateTokenValidator,
        MessageTemplateCompositionResolver $compositionResolver,
        MessageTemplateCompositionIdentityResolver $compositionIdentityResolver,
        UpsertMessageTemplateCompositionLayerAction $upsertCompositionLayer,
        PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
    ): RedirectResponse {
        $messageTemplate = MessageTemplate::query()->firstOrCreate(
            ['key' => $messageTemplatePreset->key],
            [
                'name' => $messageTemplatePreset->name,
                'description' => $messageTemplatePreset->description,
                'channel' => $messageTemplatePreset->channel,
                'status' => $messageTemplatePreset->isActive()
                    ? MessageTemplate::STATUS_ACTIVE
                    : MessageTemplate::STATUS_INACTIVE,
                'composition_family_key' => $compositionIdentityResolver->familyKey(
                    scope: (string) $messageTemplatePreset->scope,
                    sourceMessageType: (string) $messageTemplatePreset->message_type,
                    campaignTemplate: false,
                ),
                'source' => $messageTemplatePreset->source,
                'source_version' => is_int($messageTemplatePreset->source_version)
                    ? (string) $messageTemplatePreset->source_version
                    : null,
            ],
        );
        $sourcePayload = is_array($messageTemplatePreset->payload)
            ? $messageTemplatePreset->payload
            : [];
        $submittedPayload = $request->safePayload();
        $baselinePayload = $compositionResolver->resolveWithoutMessageOverride(
            $messageTemplate,
            $sourcePayload,
        );

        $submittedPayload = $this->preserveTrackingKeys(
            baseline: $messageTemplate->currentPayload(),
            submitted: $submittedPayload,
        );
        $submittedPayload = $this->preserveTrackingKeys(
            baseline: $baselinePayload,
            submitted: $submittedPayload,
        );

        $overridePayload = $this->payloadDelta($baselinePayload, $submittedPayload);
        $actor = $request->user();

        DB::transaction(function () use (
            $messageTemplate,
            $messageTemplatePreset,
            $messageTemplateTokenValidator,
            $upsertCompositionLayer,
            $publishMessageTemplateVersion,
            $sourcePayload,
            $overridePayload,
            $actor,
        ): void {
            $existingOverride = MessageTemplateCompositionLayer::query()
                ->where('scope_type', MessageTemplateCompositionLayer::SCOPE_MESSAGE)
                ->where('message_template_id', $messageTemplate->getKey())
                ->first();

            if ($overridePayload === []) {
                $existingOverride?->delete();

                $messageTemplate->forceFill([
                    'is_customized' => false,
                    'customized_at' => null,
                ])->save();
            } else {
                $override = $upsertCompositionLayer->handle(
                    scopeType: MessageTemplateCompositionLayer::SCOPE_MESSAGE,
                    channel: (string) $messageTemplate->channel,
                    payload: $overridePayload,
                    messageTemplate: $messageTemplate,
                    source: 'crm',
                    isCustomized: true,
                );

                $messageTemplate->forceFill([
                    'is_customized' => true,
                    'customized_at' => $override->customized_at ?? now(),
                ])->save();
            }

            $version = $publishMessageTemplateVersion->handle(
                messageTemplate: $messageTemplate,
                payload: $sourcePayload,
                createdBy: $actor instanceof User ? $actor : null,
            );

            $messageTemplatePreset->forceFill([
                'tokens' => $messageTemplateTokenValidator->tokensFromPayload($version->payload()),
            ])->save();
        });

        return redirect()
            ->route('crm.messaging.message-templates.index', $this->redirectParams($messageTemplatePreset))
            ->with('status', $overridePayload === []
                ? 'Message override cleared. The message now inherits shared content again.'
                : 'Message override published. Existing scheduled message versions were not changed.');
    }

    public function updateCompositionLayer(
        UpdateMessageTemplateCompositionLayerRequest $request,
        MessageTemplateCompositionLayer $messageTemplateCompositionLayer,
        MessageTemplateCompositionImpactResolver $impactResolver,
        MessageTemplateCompositionResolver $compositionResolver,
        MessageTemplateTokenValidator $messageTemplateTokenValidator,
        UpsertMessageTemplateCompositionLayerAction $upsertCompositionLayer,
        PublishMessageTemplateVersionAction $publishMessageTemplateVersion,
    ): RedirectResponse {
        $this->assertEditableSharedLayer($messageTemplateCompositionLayer);

        $proposedPayload = $request->safePayload();
        $affected = $impactResolver->templatesChangedByProposedPayload(
            $messageTemplateCompositionLayer,
            $proposedPayload,
        );

        foreach ($affected as $item) {
            /** @var MessageTemplate $template */
            $template = $item['template'];
            /** @var MessageTemplatePreset $preset */
            $preset = $item['preset'];
            $effectivePayload = $compositionResolver->resolveWithLayerPayload(
                $template,
                is_array($preset->payload) ? $preset->payload : [],
                $messageTemplateCompositionLayer,
                $proposedPayload,
            );
            $surface = $preset->catalogEntries()->active()->orderBy('item_order')->orderBy('id')->value('surface');
            $this->assertPublishablePayload($preset, $effectivePayload);

            $issues = $messageTemplateTokenValidator->validatePayload(
                payload: $effectivePayload,
                dispatchKeys: $preset->dispatchKeys(),
                channel: $preset->channel,
                purpose: $preset->purpose,
                scope: $preset->scope,
                surface: is_string($surface) && trim($surface) !== '' ? trim($surface) : null,
                path: 'payload',
            );

            $error = collect($issues)->firstWhere('level', 'error');

            if (is_array($error)) {
                throw ValidationException::withMessages([
                    'payload' => (string) ($error['message'] ?? 'The shared content would create an invalid message.'),
                ]);
            }
        }

        $actor = $request->user();

        DB::transaction(function () use (
            $messageTemplateCompositionLayer,
            $proposedPayload,
            $affected,
            $messageTemplateTokenValidator,
            $upsertCompositionLayer,
            $publishMessageTemplateVersion,
            $actor,
        ): void {
            $updatedLayer = $upsertCompositionLayer->handle(
                scopeType: $messageTemplateCompositionLayer->scope_type,
                channel: $messageTemplateCompositionLayer->channel,
                payload: $proposedPayload,
                clientKey: $messageTemplateCompositionLayer->client_key,
                contextKey: $messageTemplateCompositionLayer->context_key,
                familyKey: $messageTemplateCompositionLayer->family_key,
                source: $messageTemplateCompositionLayer->source,
                sourceVersion: $messageTemplateCompositionLayer->source_version,
                isCustomized: true,
            );

            $messageTemplateCompositionLayer->setRawAttributes($updatedLayer->getAttributes(), true);

            foreach ($affected as $item) {
                /** @var MessageTemplate $template */
                $template = $item['template'];
                /** @var MessageTemplatePreset $preset */
                $preset = $item['preset'];
                $version = $publishMessageTemplateVersion->handle(
                    messageTemplate: $template,
                    payload: is_array($preset->payload) ? $preset->payload : [],
                    createdBy: $actor instanceof User ? $actor : null,
                );

                $preset->forceFill([
                    'tokens' => $messageTemplateTokenValidator->tokensFromPayload($version->payload()),
                ])->save();
            }
        });

        return back()->with(
            'status',
            'Shared content published to '.$affected->count().' '.Str::plural('message', $affected->count()).'. Existing scheduled versions were not changed.',
        );
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @return array{channels: array<int, array{value: string, label: string}>, purposes: array<int, array{value: string, label: string}>, modules: array<int, array{value: string, label: string}>}
     */
    private function filterOptions(Collection $catalogEntries): array
    {
        return [
            'channels' => $catalogEntries->pluck('channel')->filter()->unique()->sort()
                ->map(fn (string $channel): array => ['value' => $channel, 'label' => $this->channelLabel($channel)])->values()->all(),
            'purposes' => $catalogEntries->pluck('purpose')->filter()->unique()->sort()
                ->map(fn (string $purpose): array => ['value' => $purpose, 'label' => Str::headline(str_replace('_', ' ', $purpose))])->values()->all(),
            'modules' => $catalogEntries
                ->mapWithKeys(fn (MessageTemplateCatalogEntry $entry): array => [$entry->module_key => $entry->module_label])
                ->filter(fn (mixed $label, mixed $key): bool => is_string($key) && $key !== '' && is_string($label) && $label !== '')
                ->sort()
                ->map(fn (string $label, string $key): array => ['value' => $key, 'label' => $label])
                ->values()->all(),
        ];
    }

    /**
     * @param array{channels: array<int, array{value: string, label: string}>, purposes: array<int, array{value: string, label: string}>, modules: array<int, array{value: string, label: string}>} $filterOptions
     * @return array{channel: string|null, purpose: string|null, module: string|null}
     */
    private function filters(Request $request, array $filterOptions): array
    {
        return [
            'channel' => $this->validFilterValue($request->query('channel'), $filterOptions['channels']),
            'purpose' => $this->validFilterValue($request->query('purpose'), $filterOptions['purposes']),
            'module' => $this->validFilterValue($request->query('module'), $filterOptions['modules']),
        ];
    }

    /** @param array<int, array{value: string, label: string}> $options */
    private function validFilterValue(mixed $value, array $options): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        return in_array($value, array_column($options, 'value'), true) ? $value : null;
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @param array{channel: string|null, purpose: string|null, module: string|null} $filters
     * @return Collection<int, MessageTemplateCatalogEntry>
     */
    private function filteredCatalogEntries(Collection $catalogEntries, array $filters): Collection
    {
        return $catalogEntries
            ->when($filters['channel'], fn (Collection $entries, string $channel) => $entries->where('channel', $channel))
            ->when($filters['purpose'], fn (Collection $entries, string $purpose) => $entries->where('purpose', $purpose))
            ->when($filters['module'], fn (Collection $entries, string $module) => $entries->where('module_key', $module))
            ->values();
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @return Collection<int, array{key: string, label: string, module_key: string, module_label: string, channel: string, purpose: string, scope: string, entries: Collection<int, MessageTemplateCatalogEntry>}>
     */
    private function catalogGroups(Collection $catalogEntries): Collection
    {
        return $catalogEntries
            ->groupBy('group_key')
            ->map(function (Collection $entries, string $groupKey): array {
                /** @var MessageTemplateCatalogEntry $first */
                $first = $entries->first();

                return [
                    'key' => $groupKey,
                    'label' => $first->group_label,
                    'module_key' => $first->module_key,
                    'module_label' => $first->module_label,
                    'channel' => $first->channel,
                    'purpose' => $first->purpose,
                    'scope' => $first->scope,
                    'entries' => $entries->sortBy([['item_order', 'asc'], ['item_label', 'asc']])->values(),
                ];
            })
            ->sortBy([['module_label', 'asc'], ['purpose', 'asc'], ['channel', 'asc'], ['label', 'asc']])
            ->values();
    }

    /**
     * @param Collection<int, array{key: string, label: string, module_key: string, module_label: string, channel: string, purpose: string, scope: string, entries: Collection<int, MessageTemplateCatalogEntry>}> $catalogGroups
     */
    private function selectedGroup(Request $request, Collection $catalogGroups): ?array
    {
        $selectedGroupKey = is_string($request->query('group')) ? trim((string) $request->query('group')) : '';

        if ($selectedGroupKey !== '') {
            $selectedGroup = $catalogGroups->firstWhere('key', $selectedGroupKey);

            if (is_array($selectedGroup)) {
                return $selectedGroup;
            }
        }

        return $catalogGroups->first();
    }

    /** @param Collection<int, MessageTemplateCatalogEntry> $selectedGroupEntries */
    private function selectedPreset(Request $request, Collection $selectedGroupEntries): ?MessageTemplatePreset
    {
        $selectedId = $request->integer('preset');

        if ($selectedId > 0) {
            $selectedEntry = $selectedGroupEntries->first(
                fn (MessageTemplateCatalogEntry $entry): bool => (int) $entry->message_template_preset_id === $selectedId,
            );

            if ($selectedEntry?->messageTemplatePreset instanceof MessageTemplatePreset) {
                return $selectedEntry->messageTemplatePreset;
            }
        }

        $firstEntry = $selectedGroupEntries->first();

        return $firstEntry?->messageTemplatePreset instanceof MessageTemplatePreset
            ? $firstEntry->messageTemplatePreset
            : null;
    }

    /**
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $submitted
     * @return array<string,mixed>
     */
    private function payloadDelta(array $baseline, array $submitted): array
    {
        $delta = [];

        foreach ($submitted as $key => $value) {
            if (! array_key_exists($key, $baseline) || $baseline[$key] !== $value) {
                $delta[$key] = $value;
            }
        }

        return $delta;
    }

    /**
     * tracking_key is immutable structural identity for a link, not operator-facing
     * copy. Preserve it when CRM editing changes the label or destination.
     *
     * @param array<string,mixed> $baseline
     * @param array<string,mixed> $submitted
     * @return array<string,mixed>
     */
    private function preserveTrackingKeys(array $baseline, array $submitted): array
    {
        foreach (['cta', 'secondary_link'] as $key) {
            $baselineLink = $baseline[$key] ?? null;
            $submittedLink = $submitted[$key] ?? null;

            if (! is_array($baselineLink)
                || ! is_array($submittedLink)
                || ! is_string($baselineLink['tracking_key'] ?? null)
                || trim($baselineLink['tracking_key']) === ''
            ) {
                continue;
            }

            $submitted[$key]['tracking_key'] = trim($baselineLink['tracking_key']);
        }

        $baselineCtas = $baseline['ctas'] ?? null;
        $submittedCtas = $submitted['ctas'] ?? null;

        if (is_array($baselineCtas)
            && array_is_list($baselineCtas)
            && is_array($submittedCtas)
            && array_is_list($submittedCtas)
        ) {
            foreach ($submittedCtas as $index => $submittedCta) {
                $baselineCta = $baselineCtas[$index] ?? null;

                if (! is_array($submittedCta)
                    || ! is_array($baselineCta)
                    || ! is_string($baselineCta['tracking_key'] ?? null)
                    || trim($baselineCta['tracking_key']) === ''
                ) {
                    continue;
                }

                $submitted['ctas'][$index]['tracking_key'] = trim(
                    $baselineCta['tracking_key'],
                );
            }
        }

        return $submitted;
    }

    /** @return array<string,mixed> */
    private function editablePayload(MessageTemplatePreset $preset, array $payload): array
    {
        if ($preset->payload_class === EmailPayload::class) {
            return [
                'subject' => Arr::get($payload, 'subject', ''),
                'body' => Arr::get($payload, 'body', ''),
                'footer' => Arr::get($payload, 'footer', ''),
                'cta' => ['label' => Arr::get($payload, 'cta.label', ''), 'url' => Arr::get($payload, 'cta.url', '')],
                'ctas' => $this->editableCtas(Arr::get($payload, 'ctas', [])),
                'secondary_link' => ['label' => Arr::get($payload, 'secondary_link.label', ''), 'url' => Arr::get($payload, 'secondary_link.url', '')],
            ];
        }

        if ($preset->payload_class === SmsPayload::class) {
            return ['message' => Arr::get($payload, 'message', '')];
        }

        return $payload;
    }

    /** @param mixed $ctas @return array<int,array{label:string,url:string}> */
    private function editableCtas(mixed $ctas): array
    {
        if (! is_array($ctas) || ! array_is_list($ctas)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $cta): ?array {
                if (! is_array($cta)) {
                    return null;
                }

                return [
                    'label' => is_string($cta['label'] ?? null) ? $cta['label'] : '',
                    'url' => is_string($cta['url'] ?? null) ? $cta['url'] : '',
                ];
            },
            $ctas,
        )));
    }

    /** @return array<string,mixed> */
    private function redirectParams(MessageTemplatePreset $preset): array
    {
        $catalogEntry = $preset->catalogEntries()->active()->orderBy('item_order')->orderBy('item_label')->first();

        return array_filter([
            'channel' => $catalogEntry?->channel ?? $preset->channel,
            'purpose' => $catalogEntry?->purpose ?? $preset->purpose,
            'module' => $catalogEntry?->module_key,
            'group' => $catalogEntry?->group_key,
            'preset' => $preset->getKey(),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /** @param array<string,mixed> $payload */
    private function assertPublishablePayload(MessageTemplatePreset $preset, array $payload): void
    {
        if ($preset->payload_class === EmailPayload::class) {
            $subject = $payload['subject'] ?? null;
            $body = $payload['body'] ?? null;

            if (! is_string($subject) || trim($subject) === '' || ! is_string($body) || trim($body) === '') {
                throw ValidationException::withMessages([
                    'payload' => 'The shared change would leave an email without a subject or body.',
                ]);
            }
        }

        if ($preset->payload_class === SmsPayload::class) {
            $message = $payload['message'] ?? null;

            if (! is_string($message) || trim($message) === '') {
                throw ValidationException::withMessages([
                    'payload' => 'The shared change would leave an SMS message empty.',
                ]);
            }
        }
    }

    private function assertEditableSharedLayer(MessageTemplateCompositionLayer $layer): void
    {
        abort_if($layer->scope_type === MessageTemplateCompositionLayer::SCOPE_PLATFORM, 403);
        abort_if($layer->scope_type === MessageTemplateCompositionLayer::SCOPE_MESSAGE, 404);
        abort_unless($this->normalizeClientKey($layer->client_key) === $this->normalizeClientKey(config('client.key')), 404);
    }

    private function normalizeClientKey(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return str_replace('-', '_', strtolower(trim($value)));
    }

    private function channelLabel(string $channel): string
    {
        return $channel === 'sms' ? 'SMS' : Str::headline($channel);
    }
}