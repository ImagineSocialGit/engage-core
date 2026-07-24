<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Actions\CancelAppointmentAction;
use App\Modules\Scheduling\Actions\CompleteAppointmentAction;
use App\Modules\Scheduling\Actions\ConfirmAppointmentAction;
use App\Modules\Scheduling\Actions\MarkAppointmentNoShowAction;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Requests\CancelAppointmentRequest;
use App\Modules\Scheduling\Services\SchedulingReadService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class AppointmentController extends Controller
{
    public function show(
        Appointment $appointment,
        SchedulingReadService $read,
    ): View {
        return view('crm.scheduling.show', [
            'title' => $appointment->title ?: 'Appointment',
            'heading' => 'Appointment details',
            'appointment' => $read->appointmentDetail($appointment),
        ]);
    }

    public function confirm(
        Request $request,
        Appointment $appointment,
        ConfirmAppointmentAction $confirmAppointment,
    ): RedirectResponse {
        return $this->transition(
            appointment: $appointment,
            transition: fn (): Appointment => $confirmAppointment->handle(
                appointment: $appointment,
                context: $this->context($request, 'crm_manual_confirm'),
            ),
            success: 'Appointment confirmed.',
        );
    }

    public function cancel(
        CancelAppointmentRequest $request,
        Appointment $appointment,
        CancelAppointmentAction $cancelAppointment,
    ): RedirectResponse {
        $validated = $request->validated();

        return $this->transition(
            appointment: $appointment,
            transition: fn (): Appointment => $cancelAppointment->handle(
                appointment: $appointment,
                context: $this->context(
                    request: $request,
                    reason: $validated['cancellation_reason'],
                    force: $request->boolean('override_cancellation_notice'),
                    action: 'cancel',
                ),
            ),
            success: 'Appointment canceled.',
        );
    }

    public function complete(
        Request $request,
        Appointment $appointment,
        CompleteAppointmentAction $completeAppointment,
    ): RedirectResponse {
        return $this->transition(
            appointment: $appointment,
            transition: fn (): Appointment => $completeAppointment->handle(
                appointment: $appointment,
                context: $this->context($request, 'crm_manual_complete'),
            ),
            success: 'Appointment marked complete.',
        );
    }

    public function noShow(
        Request $request,
        Appointment $appointment,
        MarkAppointmentNoShowAction $markNoShow,
    ): RedirectResponse {
        return $this->transition(
            appointment: $appointment,
            transition: fn (): Appointment => $markNoShow->handle(
                appointment: $appointment,
                context: $this->context($request, 'crm_manual_no_show'),
            ),
            success: 'Appointment marked as no-show.',
        );
    }

    /**
     * @param callable(): Appointment $transition
     */
    private function transition(
        Appointment $appointment,
        callable $transition,
        string $success,
    ): RedirectResponse {
        try {
            $transition();
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            return redirect()
                ->route('crm.scheduling.appointments.show', $appointment)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.scheduling.appointments.show', $appointment)
            ->with('success', $success);
    }

    private function context(
        Request $request,
        string $reason,
        bool $force = false,
        ?string $action = null,
    ): AppointmentLifecycleContext {
        return new AppointmentLifecycleContext(
            actor: $request->user(),
            source: 'crm',
            reason: $reason,
            force: $force,
            context: [
                'surface' => 'crm_scheduling_appointment',
                'action' => $action ?? str_replace('crm_manual_', '', $reason),
            ],
        );
    }
}