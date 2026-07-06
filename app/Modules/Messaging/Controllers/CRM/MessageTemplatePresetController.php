<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use App\Modules\Messaging\Payloads\EmailPayload;
use App\Modules\Messaging\Payloads\SmsPayload;
use App\Modules\Messaging\Requests\UpdateMessageTemplatePresetAssignmentRequest;
use App\Modules\Messaging\Requests\UpdateMessageTemplatePresetRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class MessageTemplatePresetController extends Controller
{
    public function index(Request $request): View
    {
        $presets = MessageTemplatePreset::query()
            ->withCount(['assignments as active_assignments_count' => fn ($query) => $query->active()])
            ->orderBy('channel')
            ->orderBy('purpose')
            ->orderBy('scope')
            ->orderBy('message_type')
            ->orderBy('name')
            ->get();

        $selectedPreset = $this->selectedPreset($request, $presets);

        if ($selectedPreset instanceof MessageTemplatePreset) {
            $selectedPreset->load(['assignments' => fn ($query) => $query
                ->active()
                ->with('messageTemplatePreset')
                ->orderBy('surface')
                ->orderBy('campaign_key')
                ->orderBy('campaign_step')
                ->orderBy('message_type')
                ->orderByDesc('id')]);
        }

        return view('crm.messaging.message-templates.index', [
            'presets' => $presets,
            'selectedPreset' => $selectedPreset,
            'groupedPresets' => $presets->groupBy(fn (MessageTemplatePreset $preset): string => implode(':', [
                $preset->channel,
                $preset->purpose,
                $preset->scope,
            ])),
            'editablePayload' => $selectedPreset ? $this->editablePayload($selectedPreset) : [],
            'tokens' => $selectedPreset?->tokens ?? [],
            'assignmentOptions' => $selectedPreset ? $this->assignmentOptions($selectedPreset) : collect(),
        ]);
    }

    public function update(
        UpdateMessageTemplatePresetRequest $request,
        MessageTemplatePreset $messageTemplatePreset,
    ): RedirectResponse {
        $messageTemplatePreset->forceFill([
            'name' => $request->validated('name'),
            'description' => $request->validated('description'),
            'payload' => array_replace_recursive(
                $messageTemplatePreset->payload ?? [],
                $request->safePayload(),
            ),
            'tokens' => $this->tokensFromPayload(array_replace_recursive(
                $messageTemplatePreset->payload ?? [],
                $request->safePayload(),
            )),
            'is_customized' => true,
            'customized_at' => now(),
        ])->save();

        return redirect()
            ->route('crm.messaging.message-templates.index', ['preset' => $messageTemplatePreset->getKey()])
            ->with('status', 'Message template updated.');
    }

    public function updateAssignment(
        UpdateMessageTemplatePresetAssignmentRequest $request,
        MessageTemplatePresetAssignment $messageTemplatePresetAssignment,
    ): RedirectResponse {
        $selectedPreset = $request->selectedPreset();

        $messageTemplatePresetAssignment->forceFill([
            'message_template_preset_id' => $selectedPreset->getKey(),
            'meta' => array_replace_recursive($messageTemplatePresetAssignment->meta ?? [], [
                'selected_from_crm' => true,
                'selected_at' => now()->toISOString(),
            ]),
        ])->save();

        return redirect()
            ->route('crm.messaging.message-templates.index', ['preset' => $selectedPreset->getKey()])
            ->with('status', 'Selected template updated for this workflow.');
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
     * @return Collection<int, Collection<int, MessageTemplatePreset>>
     */
    private function assignmentOptions(MessageTemplatePreset $selectedPreset): Collection
    {
        /** @var Collection<int, MessageTemplatePresetAssignment> $assignments */
        $assignments = $selectedPreset->assignments;

        if ($assignments->isEmpty()) {
            return collect();
        }

        $optionSets = collect();

        foreach ($assignments as $assignment) {
            $optionSets->put($assignment->getKey(), MessageTemplatePreset::query()
                ->active()
                ->where('channel', $assignment->channel)
                ->where('purpose', $assignment->purpose)
                ->where('scope', $assignment->scope)
                ->where('message_type', $assignment->message_type)
                ->orderByDesc('is_customized')
                ->orderBy('name')
                ->get());
        }

        return $optionSets;
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
