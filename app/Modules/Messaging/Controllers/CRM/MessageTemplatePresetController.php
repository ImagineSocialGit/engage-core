<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
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
use App\Modules\Messaging\Services\MessageTemplateDisplayLabelResolver;
use App\Modules\Messaging\Services\MessageTemplateCatalogCarouselPresenter;
use App\Modules\Messaging\Services\MessageTemplateCompositionImpactResolver;
use App\Modules\Messaging\Services\MessageTemplateCompositionResolver;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Messaging\Services\MessageTemplatePublicationHookRegistry;
use App\Modules\Messaging\Services\MessageTemplateUsageResolver;
use App\Support\ModuleIntegrations\Messaging\Contracts\MessageMediaLibrary;
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
        MessageTemplateCatalogCarouselPresenter $catalogCarouselPresenter,
        MessageTemplateDisplayLabelResolver $displayLabels,
        MessageMediaLibrary $messageMediaLibrary,
    ): View {
        $catalogEntries = MessageTemplateCatalogEntry::query()
            ->active()
            ->whereHas('messageTemplatePreset', fn ($query) => $query->active())
            ->with([
                'messageTemplatePreset' => fn ($query) => $query
                    ->active()
                    ->with('canonicalTemplate.currentVersion')
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
        $filteredCatalogEntries = $this->filteredCatalogEntries($catalogEntries, $filters, $displayLabels);
        $catalogGroups = $this->catalogGroups($filteredCatalogEntries);
        $selectedGroup = $this->selectedGroup($request, $catalogGroups);
        $selectedGroupEntries = $selectedGroup['entries'] ?? collect();
        $selectedPreset = $this->selectedPreset($request, $selectedGroupEntries);
        $messageLibrary = $catalogCarouselPresenter->present($selectedGroupEntries);
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
            'messageLibrary' => $messageLibrary,
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
            'messageMediaAvailable' => $messageMediaLibrary->available(),
            'messageMediaAssets' => $messageMediaLibrary->available()
                ? $messageMediaLibrary->selectableAssets()
                : [],
            'messageMediaLibraryUrl' => $messageMediaLibrary->available()
                ? route('crm.media.index')
                : null,
        ]);
    }

    public function update(
        UpdateMessageTemplatePresetRequest $request,
        MessageTemplatePreset $messageTemplatePreset,
        PublishMessageTemplatePresetOverrideAction $publishOverride,
        MessageTemplatePublicationHookRegistry $publicationHooks,
        MessageMediaLibrary $messageMediaLibrary,
    ): RedirectResponse {
        $actor = $request->user();
        $actor = $actor instanceof User ? $actor : null;
        $submittedPayload = $this->submittedPayloadWithMedia(
            request: $request,
            preset: $messageTemplatePreset,
            messageMediaLibrary: $messageMediaLibrary,
            actor: $actor,
        );
        $result = DB::transaction(function () use (
            $messageTemplatePreset,
            $publishOverride,
            $publicationHooks,
            $submittedPayload,
            $actor,
        ) {
            $result = $publishOverride->handle(
                preset: $messageTemplatePreset,
                submittedPayload: $submittedPayload,
                createdBy: $actor,
            );

            $publicationHooks->afterPublish(
                preset: $messageTemplatePreset,
                version: $result->version,
                actor: $actor,
            );

            return $result;
        }, 3);

        $redirect = $this->safeReturnPath($request);

        return ($redirect !== null
                ? redirect($redirect)
                : redirect()->route('crm.messaging.message-templates.index', $this->redirectParams($messageTemplatePreset)))
            ->with('status', $result->overrideCleared
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
        MessageTemplatePublicationHookRegistry $publicationHooks,
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
        $actor = $actor instanceof User ? $actor : null;

        DB::transaction(function () use (
            $messageTemplateCompositionLayer,
            $proposedPayload,
            $affected,
            $messageTemplateTokenValidator,
            $upsertCompositionLayer,
            $publishMessageTemplateVersion,
            $publicationHooks,
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
                    createdBy: $actor,
                );

                $preset->forceFill([
                    'tokens' => $messageTemplateTokenValidator->tokensFromPayload($version->payload()),
                ])->save();

                $publicationHooks->afterPublish(
                    preset: $preset,
                    version: $version,
                    actor: $actor,
                );
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
     * @return array{q: string|null, channel: string|null, purpose: string|null, module: string|null}
     */
    private function filters(Request $request, array $filterOptions): array
    {
        $search = $request->query('q');
        $search = is_string($search) ? trim($search) : '';

        return [
            'q' => $search !== '' ? mb_substr($search, 0, 120) : null,
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
     * @param array{q: string|null, channel: string|null, purpose: string|null, module: string|null} $filters
     * @return Collection<int, MessageTemplateCatalogEntry>
     */
    private function filteredCatalogEntries(
        Collection $catalogEntries,
        array $filters,
        MessageTemplateDisplayLabelResolver $displayLabels,
    ): Collection {
        return $catalogEntries
            ->when($filters['channel'], fn (Collection $entries, string $channel) => $entries->where('channel', $channel))
            ->when($filters['purpose'], fn (Collection $entries, string $purpose) => $entries->where('purpose', $purpose))
            ->when($filters['module'], fn (Collection $entries, string $module) => $entries->where('module_key', $module))
            ->when($filters['q'], function (Collection $entries, string $search) use ($displayLabels): Collection {
                $needle = mb_strtolower($search);

                return $entries->filter(function (MessageTemplateCatalogEntry $entry) use ($displayLabels, $needle): bool {
                    $preset = $entry->messageTemplatePreset;
                    $payload = $displayLabels->payload($preset);
                    $haystack = implode(' ', array_filter([
                        $entry->group_label,
                        $entry->module_label,
                        $entry->item_label,
                        $entry->item_key,
                        $displayLabels->label($entry, $payload),
                        $preset?->name,
                        $preset?->message_type,
                        $payload['subject'] ?? null,
                        $payload['body'] ?? null,
                        $payload['message'] ?? null,
                    ], static fn (mixed $value): bool => is_string($value) && trim($value) !== ''));

                    return str_contains(mb_strtolower($haystack), $needle);
                });
            })
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
                'media' => is_array(Arr::get($payload, 'media')) ? Arr::get($payload, 'media') : [],
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

    /** @return array<string, mixed> */
    private function submittedPayloadWithMedia(
        UpdateMessageTemplatePresetRequest $request,
        MessageTemplatePreset $preset,
        MessageMediaLibrary $messageMediaLibrary,
        ?User $actor,
    ): array {
        $submitted = $request->safePayload();

        if (! $request->hasMediaSubmission()) {
            return $submitted;
        }

        if (! $messageMediaLibrary->available()) {
            throw ValidationException::withMessages([
                'payload.media_asset_uuid' => 'Enable the Media module before adding media to a message.',
            ]);
        }

        $currentMedia = $this->currentMediaSnapshot($preset);
        $upload = $request->mediaUpload();
        $selectedUuid = $request->mediaAssetUuid();
        $posterUuid = $request->mediaPosterAssetUuid();

        try {
            if ($upload !== null) {
                $submitted['media'] = $messageMediaLibrary->store(
                    file: $upload,
                    title: $request->mediaTitle(),
                    posterAssetUuid: $posterUuid,
                    uploadedBy: $actor,
                );

                return $submitted;
            }

            if ($selectedUuid !== null) {
                $currentUuid = is_string($currentMedia['asset_uuid'] ?? null)
                    ? trim($currentMedia['asset_uuid'])
                    : null;
                $currentPosterUuid = is_string($currentMedia['poster_asset_uuid'] ?? null)
                    ? trim($currentMedia['poster_asset_uuid'])
                    : null;

                if ($currentMedia !== []
                    && $selectedUuid === $currentUuid
                    && $posterUuid === $currentPosterUuid
                ) {
                    $submitted['media'] = $currentMedia;
                } else {
                    $submitted['media'] = $messageMediaLibrary->snapshot(
                        assetUuid: $selectedUuid,
                        posterAssetUuid: $posterUuid,
                    );
                }

                return $submitted;
            }
        } catch (\RuntimeException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'payload.media_asset_uuid' => $exception->getMessage(),
            ]);
        }

        if ($currentMedia !== []) {
            $submitted['media'] = null;
        }

        return $submitted;
    }

    /** @return array<string, mixed> */
    private function currentMediaSnapshot(MessageTemplatePreset $preset): array
    {
        $template = MessageTemplate::query()
            ->with('currentVersion')
            ->where('key', $preset->key)
            ->first();
        $payload = $template instanceof MessageTemplate
            ? $template->currentPayload()
            : (is_array($preset->payload) ? $preset->payload : []);
        $media = $payload['media'] ?? null;

        return is_array($media) && ! array_is_list($media)
            ? $media
            : [];
    }

    private function safeReturnPath(Request $request): ?string
    {
        $value = $request->input('return_to');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === ''
            || ! str_starts_with($value, '/')
            || str_starts_with($value, '//')
            || str_contains($value, '\\')
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return null;
        }

        return $value;
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