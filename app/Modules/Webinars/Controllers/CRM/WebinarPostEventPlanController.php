<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Messaging\Actions\PublishMessageTemplatePresetOverrideAction;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplatePublicationHookRegistry;
use App\Modules\Messaging\Services\MessageTemplateTokenValidator;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarPostEventPlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class WebinarPostEventPlanController extends Controller
{
    public function show(WebinarSeries $series, WebinarPostEventPlanService $plans): View
    {
        $plan = $plans->forSeries($series);
        abort_unless($plan !== null, 404);

        $options = $this->templateOptions();
        $presets = $options->keyBy('key');
        $cards = [];

        foreach ($plans->rules($plan) as $id => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $cards[] = [
                'id' => (string) $id,
                'rule' => $rule,
                'primary' => $this->messageCard($presets->get($rule['template_key'] ?? null)),
                'alternate' => $this->messageCard($presets->get($rule['alternate_template_key'] ?? null)),
            ];
        }

        return view('crm.webinars.post-event-plan', [
            'series' => $series,
            'plan' => $plan,
            'cards' => $cards,
            'templateOptions' => $options,
            'triggers' => WebinarPostEventPlanService::TRIGGERS,
            'outcomes' => WebinarPostEventPlanService::OUTCOMES,
            'sendConditions' => $plans->sendConditions(),
            'conditionFailureActions' => WebinarPostEventPlanService::CONDITION_FAILURE_ACTIONS,
        ]);
    }

    public function update(Request $request, WebinarSeries $series, WebinarPostEventPlanService $plans): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);

        if ($request->boolean('enabled') && $series->status !== 'active') {
            throw ValidationException::withMessages(['enabled' => 'Restore this webinar type before activating the plan.']);
        }

        try {
            $plans->saveActivation($series, $request->boolean('enabled'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['enabled' => $exception->getMessage()]);
        }

        return redirect()->route('crm.webinar-series.post-event-plan.show', $series)
            ->with('status', $request->boolean('enabled') ? 'Plan enabled for future sessions.' : 'Plan saved as a draft.');
    }

    public function updateRule(
        Request $request,
        WebinarSeries $series,
        string $ruleId,
        WebinarPostEventPlanService $plans,
    ): RedirectResponse {
        $path = 'rules.'.$ruleId;
        $validated = $request->validate([
            $path.'.enabled' => ['required', 'boolean'],
            $path.'.template_key' => ['required', 'string', 'max:191'],
            $path.'.trigger' => ['required', Rule::in(array_keys(WebinarPostEventPlanService::TRIGGERS))],
            $path.'.outcome' => ['required', Rule::in(array_keys(WebinarPostEventPlanService::OUTCOMES))],
            $path.'.send_condition' => ['required', Rule::in(array_keys($plans->sendConditions()))],
            $path.'.on_condition_failure' => ['required', Rule::in(array_keys(WebinarPostEventPlanService::CONDITION_FAILURE_ACTIONS))],
            $path.'.alternate_template_key' => ['nullable', 'string', 'max:191'],
            $path.'.delay_unit' => ['required', Rule::in(['minutes', 'days'])],
            $path.'.delay_value' => ['required', 'integer', 'min:0', 'max:1440'],
            $path.'.send_time' => ['nullable', 'date_format:H:i'],
        ]);
        $data = data_get($validated, $path);
        $data['enabled'] = (bool) $data['enabled'];
        $data['delay_value'] = (int) $data['delay_value'];
        if ($data['delay_unit'] === 'days' && $data['delay_value'] > 365) {
            throw ValidationException::withMessages([$path.'.delay_value' => 'Choose 365 days or fewer.']);
        }
        $data['send_time'] = $data['delay_unit'] === 'days' && filled($data['send_time'] ?? null)
            ? $data['send_time'] : null;
        $preset = $this->templateOptions()->firstWhere('key', $data['template_key']);

        if (! $preset) {
            throw ValidationException::withMessages(['template_key' => 'Select an active webinar follow-up template.']);
        }
        $data['channel'] = $preset->channel;
        if ($data['send_condition'] === 'always') {
            $data['on_condition_failure'] = 'skip';
        }
        if ($data['on_condition_failure'] === 'alternate') {
            $alternate = $this->templateOptions()->firstWhere('key', $data['alternate_template_key'] ?? null);
            if (! $alternate || $alternate->channel !== $preset->channel) {
                throw ValidationException::withMessages([$path.'.alternate_template_key' => 'Choose an active alternate template in the same channel.']);
            }
        } else {
            $data['alternate_template_key'] = null;
        }

        try {
            $plans->saveRule($series, $ruleId, $data);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template_key' => $exception->getMessage()]);
        }

        return $this->backToRule($series, $ruleId, 'Follow-up message saved for future sessions.');
    }

    public function addRule(Request $request, WebinarSeries $series, WebinarPostEventPlanService $plans): RedirectResponse
    {
        $data = $request->validate(['template_key' => ['required', 'string', 'max:191']]);
        $preset = $this->templateOptions()->firstWhere('key', $data['template_key']);

        if (! $preset) {
            throw ValidationException::withMessages(['template_key' => 'Select an active webinar follow-up template.']);
        }

        try {
            $plans->addRule($series, ['template_key' => $preset->key, 'channel' => $preset->channel]);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['template_key' => $exception->getMessage()]);
        }

        return redirect()->route('crm.webinar-series.post-event-plan.show', $series)
            ->with('status', 'Follow-up message added as a disabled draft.');
    }

    public function deleteRule(WebinarSeries $series, string $ruleId, WebinarPostEventPlanService $plans): RedirectResponse
    {
        try {
            $plans->deleteRule($series, $ruleId);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rule' => $exception->getMessage()]);
        }

        return redirect()->route('crm.webinar-series.post-event-plan.show', $series)
            ->with('status', 'Follow-up message removed.');
    }

    public function updateCopy(
        Request $request,
        WebinarSeries $series,
        string $ruleId,
        WebinarPostEventPlanService $plans,
        PublishMessageTemplatePresetOverrideAction $publish,
        MessageTemplatePublicationHookRegistry $hooks,
        MessageTemplateTokenValidator $tokens,
    ): RedirectResponse {
        $plan = $plans->forSeries($series);
        $rule = is_array($plan) ? $plans->rule($plan, $ruleId) : null;
        abort_unless($rule !== null, 404);

        $request->validate(['branch' => ['required', Rule::in(['primary', 'alternate'])]]);
        $branch = $request->input('branch');
        if ($branch === 'alternate' && ($rule['on_condition_failure'] ?? null) !== 'alternate') {
            abort(404);
        }

        $key = $branch === 'alternate' ? ($rule['alternate_template_key'] ?? null) : $rule['template_key'];
        $preset = $this->templateOptions()->firstWhere('key', $key);
        $template = $preset?->canonicalTemplate;
        abort_unless($preset && $template && $template->currentVersion, 404);

        $path = 'copy.'.$ruleId.'.'.$branch;
        $fields = $preset->channel === 'email'
            ? [$path.'.subject' => ['required', 'string', 'max:255'], $path.'.body' => ['required', 'string', 'max:10000']]
            : [$path.'.message' => ['required', 'string', 'max:1600']];
        $data = data_get($request->validate($fields), $path);
        $payload = array_replace($template->currentPayload(), $data);
        $surface = $preset->catalogEntries->first()?->surface;
        $issues = $tokens->validatePayload(
            payload: $payload,
            dispatchKeys: $preset->dispatchKeys(),
            channel: $preset->channel,
            purpose: $preset->purpose,
            scope: $preset->scope,
            surface: is_string($surface) ? $surface : null,
            path: 'payload',
        );

        foreach ($issues as $issue) {
            if (($issue['level'] ?? null) === 'error') {
                throw ValidationException::withMessages(['copy' => (string) ($issue['message'] ?? 'The copy contains an invalid dynamic field.')]);
            }
        }

        $actor = $request->user();
        $actor = $actor instanceof User ? $actor : null;
        DB::transaction(function () use ($preset, $payload, $actor, $publish, $hooks): void {
            $result = $publish->handle($preset, $payload, $actor);
            $hooks->afterPublish($preset, $result->version, $actor);
        }, 3);

        return $this->backToRule($series, $ruleId, 'Message copy published. Existing scheduled messages keep their pinned version.');
    }

    private function templateOptions(): \Illuminate\Support\Collection
    {
        return MessageTemplatePreset::query()
            ->active()
            ->where('purpose', 'transactional')
            ->where('scope', 'webinar')
            ->whereIn('channel', ['sms', 'email'])
            ->with(['canonicalTemplate.currentVersion', 'catalogEntries' => fn ($query) => $query
                ->where('is_active', true)->where('module_key', 'webinars')->orderBy('id')])
            ->orderBy('channel')->orderBy('name')->get()
            ->filter(fn (MessageTemplatePreset $preset): bool => in_array('webinar_post_event_plan', $preset->dispatchKeys(), true)
                && $preset->canonicalTemplate?->isActive() === true
                && $preset->canonicalTemplate?->currentVersion !== null)
            ->values();
    }

    /** @return array<string, mixed> */
    private function messageCard(?MessageTemplatePreset $preset): array
    {
        $payload = $preset?->canonicalTemplate?->currentPayload() ?? [];

        return [
            'preset' => $preset,
            'subject' => is_string($payload['subject'] ?? null) ? $payload['subject'] : '',
            'copy' => is_string($payload['message'] ?? $payload['body'] ?? null)
                ? ($payload['message'] ?? $payload['body']) : '',
            'advanced_url' => $this->advancedUrl($preset),
        ];
    }

    private function advancedUrl(?MessageTemplatePreset $preset): ?string
    {
        $entry = $preset?->catalogEntries->first();
        if (! $entry) {
            return null;
        }

        return route('crm.messaging.message-templates.index', [
            'channel' => $preset->channel,
            'purpose' => $preset->purpose,
            'module' => 'webinars',
            'group' => $entry->group_key,
            'preset' => $preset->getKey(),
        ]);
    }

    private function backToRule(WebinarSeries $series, string $ruleId, string $status): RedirectResponse
    {
        return redirect()->to(route('crm.webinar-series.post-event-plan.show', $series).'#rule-'.$ruleId)
            ->with('status', $status);
    }
}