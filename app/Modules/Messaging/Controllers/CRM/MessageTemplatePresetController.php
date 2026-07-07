<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\MessageTemplateCatalogEntry;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Requests\UpdateMessageTemplatePresetRequest;
use App\Modules\Messaging\Services\MessageTemplateUsageResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MessageTemplatePresetController extends Controller
{
    public function index(Request $request, MessageTemplateUsageResolver $usageResolver): View
    {
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

        if ($selectedPreset instanceof MessageTemplatePreset) {
            $selectedPreset->load([
                'catalogEntries' => fn ($query) => $query->active()->orderBy('item_order')->orderBy('item_label'),
                'assignments' => fn ($query) => $query->active()->orderBy('surface')->orderBy('campaign_key')->orderBy('campaign_step')->orderBy('message_type'),
            ])->loadCount(['assignments as active_assignments_count' => fn ($query) => $query->active()]);
        }

        return view('crm.messaging.message-templates.index', [
            'presets' => $presets,
            'catalogEntries' => $catalogEntries,
            'catalogGroups' => $catalogGroups,
            'selectedGroup' => $selectedGroup,
            'selectedGroupEntries' => $selectedGroupEntries,
            'selectedPreset' => $selectedPreset,
            'filterOptions' => $filterOptions,
            'filters' => $filters,
            'editablePayload' => $selectedPreset ? $this->editablePayload($selectedPreset) : [],
            'tokens' => $selectedPreset?->tokens ?? [],
            'usageSummaries' => $selectedPreset ? $usageResolver->forPreset($selectedPreset) : collect(),
        ]);
    }

    public function update(
        UpdateMessageTemplatePresetRequest $request,
        MessageTemplatePreset $messageTemplatePreset,
    ): RedirectResponse {
        $payload = array_replace_recursive(
            $messageTemplatePreset->payload ?? [],
            $request->safePayload(),
        );

        $messageTemplatePreset->forceFill([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'payload' => $payload,
            'tokens' => $this->tokensFromPayload($payload),
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        $catalogEntry = $messageTemplatePreset->catalogEntries()
            ->active()
            ->orderBy('item_order')
            ->orderBy('item_label')
            ->first();

        return redirect()
            ->route('crm.messaging.message-templates.index', array_filter([
                'channel' => $catalogEntry?->channel ?? $messageTemplatePreset->channel,
                'purpose' => $catalogEntry?->purpose ?? $messageTemplatePreset->purpose,
                'module' => $catalogEntry?->module_key,
                'group' => $catalogEntry?->group_key,
                'preset' => $messageTemplatePreset->getKey(),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''))
            ->with('status', 'Message template updated.');
    }

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $catalogEntries
     * @return array{channels: array<int, array{value: string, label: string}>, purposes: array<int, array{value: string, label: string}>, modules: array<int, array{value: string, label: string}>}
     */
    private function filterOptions(Collection $catalogEntries): array
    {
        return [
            'channels' => $catalogEntries
                ->pluck('channel')
                ->filter()
                ->unique()
                ->sort()
                ->map(fn (string $channel): array => [
                    'value' => $channel,
                    'label' => $this->channelLabel($channel),
                ])
                ->values()
                ->all(),
            'purposes' => $catalogEntries
                ->pluck('purpose')
                ->filter()
                ->unique()
                ->sort()
                ->map(fn (string $purpose): array => [
                    'value' => $purpose,
                    'label' => Str::headline(str_replace('_', ' ', $purpose)),
                ])
                ->values()
                ->all(),
            'modules' => $catalogEntries
                ->mapWithKeys(fn (MessageTemplateCatalogEntry $entry): array => [
                    $entry->module_key => $entry->module_label,
                ])
                ->filter(fn (mixed $label, mixed $key): bool => is_string($key) && $key !== '' && is_string($label) && $label !== '')
                ->sort()
                ->map(fn (string $label, string $key): array => [
                    'value' => $key,
                    'label' => $label,
                ])
                ->values()
                ->all(),
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

    /**
     * @param array<int, array{value: string, label: string}> $options
     */
    private function validFilterValue(mixed $value, array $options): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $allowed = array_column($options, 'value');

        return in_array($value, $allowed, true) ? $value : null;
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
                    'entries' => $entries
                        ->sortBy([
                            ['item_order', 'asc'],
                            ['item_label', 'asc'],
                        ])
                        ->values(),
                ];
            })
            ->sortBy([
                ['module_label', 'asc'],
                ['purpose', 'asc'],
                ['channel', 'asc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    /**
     * @param Collection<int, array{key: string, label: string, module_key: string, module_label: string, channel: string, purpose: string, scope: string, entries: Collection<int, MessageTemplateCatalogEntry>}> $catalogGroups
     * @return array{key: string, label: string, module_key: string, module_label: string, channel: string, purpose: string, scope: string, entries: Collection<int, MessageTemplateCatalogEntry>}|null
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

    /**
     * @param Collection<int, MessageTemplateCatalogEntry> $selectedGroupEntries
     */
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
     * @return array<string, mixed>
     */
    private function editablePayload(MessageTemplatePreset $preset): array
    {
        $payload = $preset->payload ?? [];

        if ($preset->payload_class === EmailPayload::class) {
            return [
                'subject' => Arr::get($payload, 'subject', ''),
                'body' => Arr::get($payload, 'body', ''),
                'footer' => Arr::get($payload, 'footer', ''),
                'cta' => [
                    'label' => Arr::get($payload, 'cta.label', ''),
                    'url' => Arr::get($payload, 'cta.url', ''),
                ],
                'secondary_link' => [
                    'label' => Arr::get($payload, 'secondary_link.label', ''),
                    'url' => Arr::get($payload, 'secondary_link.url', ''),
                ],
            ];
        }

        if ($preset->payload_class === SmsPayload::class) {
            return [
                'message' => Arr::get($payload, 'message', ''),
            ];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string>
     */
    private function tokensFromPayload(array $payload): array
    {
        $tokens = [];

        array_walk_recursive($payload, function (mixed $value) use (&$tokens): void {
            if (! is_string($value) || trim($value) === '') {
                return;
            }

            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_.:-]*)\}/', $value, $matches);

            $tokens = array_merge($tokens, $matches[1] ?? []);
        });

        return array_values(array_unique($tokens));
    }

    private function channelLabel(string $channel): string
    {
        return match ($channel) {
            'sms' => 'SMS',
            default => Str::headline($channel),
        };
    }
}
