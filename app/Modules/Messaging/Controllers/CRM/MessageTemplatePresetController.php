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

class MessageTemplatePresetController extends Controller
{
    public function index(Request $request, MessageTemplateUsageResolver $usageResolver): View
    {
        $presets = MessageTemplatePreset::query()
            ->with(['catalogEntries' => fn ($query) => $query->active()->orderBy('item_order')->orderBy('item_label')])
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query->active()])
            ->orderBy('channel')
            ->orderBy('purpose')
            ->orderBy('scope')
            ->orderBy('message_type')
            ->orderBy('name')
            ->get();

        $selectedPreset = $this->selectedPreset($request, $presets);

        if ($selectedPreset instanceof MessageTemplatePreset) {
            $selectedPreset->load([
                'catalogEntries' => fn ($query) => $query->active()->orderBy('item_order')->orderBy('item_label'),
                'assignments' => fn ($query) => $query->active()->orderBy('surface')->orderBy('campaign_key')->orderBy('campaign_step')->orderBy('message_type'),
            ]);
        }

        return view('crm.messaging.message-templates.index', [
            'presets' => $presets,
            'selectedPreset' => $selectedPreset,
            'groupedPresets' => $this->groupedPresets($presets),
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

        return redirect()
            ->route('crm.messaging.message-templates.index', ['preset' => $messageTemplatePreset->getKey()])
            ->with('status', 'Message template updated.');
    }

    private function selectedPreset(Request $request, Collection $presets): ?MessageTemplatePreset
    {
        $selectedId = $request->integer('preset');

        if ($selectedId > 0) {
            $selected = $presets->firstWhere('id', $selectedId);

            if ($selected instanceof MessageTemplatePreset) {
                return $selected;
            }
        }

        return $presets->first();
    }

    /**
     * @param Collection<int, MessageTemplatePreset> $presets
     * @return Collection<string, Collection<int, MessageTemplatePreset>>
     */
    private function groupedPresets(Collection $presets): Collection
    {
        return $presets->groupBy(function (MessageTemplatePreset $preset): string {
            $catalogEntry = $preset->catalogEntries->first();

            if ($catalogEntry instanceof MessageTemplateCatalogEntry) {
                return implode(':', [
                    $catalogEntry->channel,
                    $catalogEntry->purpose,
                    $catalogEntry->module_label,
                    $catalogEntry->group_label,
                ]);
            }

            return implode(':', [
                $preset->channel,
                $preset->purpose,
                str_replace('_', ' ', $preset->scope),
            ]);
        });
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
}
