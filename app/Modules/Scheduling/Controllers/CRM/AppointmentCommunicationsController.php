<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AppointmentCommunicationsController extends Controller
{
    public function index(
        AppointmentCommunications $communications,
    ): View {
        return view('crm.scheduling.communications', [
            'title' => 'Appointment Communications',
            'heading' => 'Appointment Communications',
            'plan' => $communications->plan(),
        ]);
    }

    public function generate(
        Request $request,
        AppointmentCommunications $communications,
    ): RedirectResponse {
        if (! $communications->available()) {
            return back()->with(
                'error',
                'Appointment communications are available when Messaging is enabled.',
            );
        }

        $communications->generateDefaultSchedule($request->user());

        return redirect()
            ->route('crm.scheduling.configuration.communications.index')
            ->with('success', 'Default appointment communication schedule generated.');
    }

    public function update(
        Request $request,
        AppointmentCommunications $communications,
    ): RedirectResponse {
        if (! $communications->available()) {
            return back()->with(
                'error',
                'Appointment communications are available when Messaging is enabled.',
            );
        }

        $validated = $request->validate([
            'steps' => ['required', 'array', 'min:1', 'max:20'],
            'steps.*.key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_]+$/'],
            'steps.*.name' => ['required', 'string', 'max:80'],
            'steps.*.timing' => ['required', Rule::in(['immediate', 'before', 'after'])],
            'steps.*.offset_value' => ['nullable', 'integer', 'min:1', 'max:525600'],
            'steps.*.offset_unit' => ['nullable', Rule::in(['minutes', 'hours', 'days'])],
            'steps.*.channels' => ['required', 'array', 'min:1'],
            'steps.*.channels.*' => ['required', 'string', Rule::in(['email', 'sms']), 'distinct'],
            'steps.*.subject' => ['nullable', 'string', 'max:255'],
            'steps.*.message' => ['required', 'string', 'max:5000'],
        ]);

        $communications->saveSchedule(
            steps: $validated['steps'],
            actor: $request->user(),
        );

        return redirect()
            ->route('crm.scheduling.configuration.communications.index')
            ->with('success', 'Appointment communication schedule saved.');
    }
}