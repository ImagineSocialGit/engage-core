<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Models\BookableService;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentAfterBookingWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SchedulingAfterBookingController extends Controller
{
    public function index(
        AppointmentAfterBookingWorkspace $workspace,
    ): View {
        return view('crm.scheduling.after-booking', [
            'title' => 'After Booking',
            'heading' => 'After Booking',
            'afterBooking' => $workspace->read(),
        ]);
    }

    public function update(
        Request $request,
        BookableService $bookableService,
        AppointmentAfterBookingWorkspace $workspace,
    ): RedirectResponse {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(['manual', 'simple'])],
            'tag' => ['nullable', 'string', 'max:255'],
            'contact_status_key' => ['nullable', 'string', 'max:255'],
            'task_template_key' => ['nullable', 'string', 'max:255'],
        ]);

        $workspace->update(
            service: $bookableService,
            input: $validated,
        );

        return redirect()
            ->route('crm.scheduling.configuration.after-booking.index')
            ->with('success', 'After-booking follow-up updated.');
    }
}