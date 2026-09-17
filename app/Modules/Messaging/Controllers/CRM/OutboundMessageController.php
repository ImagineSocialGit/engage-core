<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Core\Services\Contacts\ContactIndexFilterService;
use App\Modules\Core\Services\Contacts\ContactResultSetResolver;
use App\Modules\Messaging\Actions\ControlScheduledMessageAction;
use App\Modules\Messaging\Services\OutboundMessageIndex;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class OutboundMessageController extends Controller
{
    public function index(
        Request $request,
        OutboundMessageIndex $messages,
        ContactIndexFilterService $contactFilters,
        UserAccessService $access,
        ContactResultSetResolver $resultSets,
    ): View {
        $filters = $request->validate([
            'module' => ['nullable', 'string', Rule::in(array_keys(config('modules.outbound_message_sources', [])))],
            'period' => ['nullable', 'string', Rule::in(['upcoming', 'past', 'all'])],
            'message_status' => ['nullable', 'string', Rule::in(['pending', 'held', 'sending', 'sent', 'skipped', 'failed', 'cancelled'])],
            'channel' => ['nullable', 'string', Rule::in(['email', 'sms'])],
            'contact_status' => ['nullable', 'string', 'max:191'],
            'tag' => ['nullable', 'string', 'max:191'],
            'search' => ['nullable', 'string', 'max:120'],
            'contact_id' => ['nullable', 'integer', 'min:1'],
            'after' => ['nullable', 'date_format:Y-m-d'],
            'before' => ['nullable', 'date_format:Y-m-d'],
            'origin_type' => ['nullable', 'string', 'max:191'],
            'origin_id' => ['required_with:origin_type', 'nullable', 'integer', 'min:1'],
            'scope' => ['nullable', 'string', Rule::in(['webinar_series', 'webinar', 'campaign'])],
            'scope_id' => ['required_with:scope', 'nullable', 'integer', 'min:1'],
            'group' => ['nullable', 'string', 'max:64'],
            'embedded' => ['nullable', 'boolean'],
        ]);
        $filters = array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== '');
        if (isset($filters['group'])) {
            $stored = $request->session()->get('outbound.contact_groups.'.$filters['group']);

            abort_unless(is_array($stored)
                && ($stored['user_id'] ?? null) === $request->user()->getKey()
                && isset($stored['payload']), 404);

            $filters['contact_group'] = $resultSets->normalize($stored['payload']);
        }

        if (isset($filters['scope'])) {
            $module = $filters['scope'] === 'campaign' ? 'campaigns' : 'webinars';
            abort_unless(module_enabled($module), 404);
        }

        $contactState = $contactFilters->state([
            'status' => $filters['contact_status'] ?? null,
            'tag' => $filters['tag'] ?? null,
            'search' => $filters['search'] ?? null,
        ]);

        foreach (['contact_status' => 'status', 'tag' => 'tag'] as $field => $criterion) {
            if (isset($filters[$field])
                && ! in_array($filters[$field], $contactState['criteria'][$criterion] ?? [], true)
            ) {
                throw ValidationException::withMessages([
                    $field => 'Choose a current lead filter value.',
                ]);
            }
        }

        $contactOptions = [];

        foreach ($contactState['primary'] as $criterion) {
            if (in_array($criterion['key'] ?? null, ['status', 'tag'], true)) {
                $contactOptions[$criterion['key']] = $criterion['options'];
            }
        }

        $page = $messages->paginate($request->user(), $filters);
        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));

        return view('crm.messaging.outbound.index', [
            'messages' => $page,
            'rows' => $messages->rows($page, $timezone),
            'filters' => $filters,
            'contactOptions' => $contactOptions,
            'sources' => collect(array_keys(config('modules.outbound_message_sources', [])))
                ->mapWithKeys(fn (string $key): array => [$key => str($key)->headline()->toString()])
                ->all(),
            'statuses' => [
                'pending' => 'Pending', 'held' => 'Held', 'sending' => 'Sending',
                'sent' => 'Sent', 'skipped' => 'Skipped', 'failed' => 'Failed',
                'cancelled' => 'Cancelled',
            ],
            'returnFilters' => $request->query(),
            'timezone' => $timezone,
            'canControl' => $access->allows($request->user(), 'contacts.manage'),
            'embedded' => $request->boolean('embedded'),
        ]);
    }

    public function contactGroup(
        Request $request,
        ContactResultSetResolver $resultSets,
    ): RedirectResponse {
        $validated = $request->validate($resultSets->validationRules());
        $payload = $resultSets->normalizeForInput($validated['contact_result']);
        $token = Str::random(24);
        $groups = $request->session()->get('outbound.contact_groups', []);
        $groups[$token] = [
            'user_id' => $request->user()->getKey(),
            'payload' => $payload,
        ];
        $request->session()->put('outbound.contact_groups', array_slice($groups, -10, null, true));

        return redirect()->route('crm.messaging.outbound.index', [
            'group' => $token,
            'period' => 'upcoming',
            'embedded' => 1,
        ]);
    }

    public function control(
        Request $request,
        int $scheduledMessage,
        OutboundMessageIndex $messages,
        ControlScheduledMessageAction $control,
    ): RedirectResponse {
        $validated = $request->validate([
            'action' => ['required', 'string', Rule::in(['hold', 'resume', 'cancel', 'reschedule'])],
            'send_at' => ['required_if:action,reschedule', 'nullable', 'date_format:Y-m-d\\TH:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $message = $messages->visibleMessage($request->user(), $scheduledMessage);
        $actor = $request->user();
        $reason = $validated['reason'] ?? null;

        match ($validated['action']) {
            'hold' => $control->hold($message, $actor, $reason),
            'resume' => $control->resume($message, $actor, $reason),
            'cancel' => $control->cancel($message, $actor, $reason),
            'reschedule' => $control->reschedule(
                $message,
                $actor,
                $this->sendAt((string) $validated['send_at']),
                $reason,
            ),
        };

        return redirect()->route('crm.messaging.outbound.index', $request->query())
            ->with('success', 'Outbound message updated.');
    }

    private function sendAt(string $value): Carbon
    {
        $timezone = (string) config('client.timezone', config('app.timezone', 'UTC'));
        $time = Carbon::createFromFormat('!Y-m-d\\TH:i', $value, $timezone);

        if (! $time instanceof Carbon || $time->format('Y-m-d\\TH:i') !== $value) {
            throw ValidationException::withMessages(['send_at' => 'Choose a valid date and time.']);
        }

        return $time->utc();
    }
}