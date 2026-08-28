<?php

namespace App\Modules\Scheduling\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Actions\CancelAppointmentAction;
use App\Modules\Scheduling\Actions\CompleteAppointmentAction;
use App\Modules\Scheduling\Actions\ConfirmAppointmentAction;
use App\Modules\Scheduling\Actions\MarkAppointmentNoShowAction;
use App\Modules\Scheduling\Actions\RescheduleAppointmentToSlotAction;
use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Requests\CancelAppointmentRequest;
use App\Modules\Scheduling\Requests\RescheduleAppointmentRequest;
use App\Modules\Scheduling\Services\SchedulingReadService;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use LogicException;

class AppointmentController extends Controller
{
    private const RESCHEDULABLE_STATUSES = [
        Appointment::STATUS_PENDING,
        Appointment::STATUS_SCHEDULED,
        Appointment::STATUS_CONFIRMED,
    ];

    public function show(
        Appointment $appointment,
        SchedulingReadService $read,
        AppointmentCommunications $communications,
    ): View {
        $appointment = $read->appointmentDetail($appointment);

        return view('crm.scheduling.show', [
            'title' => $appointment->title ?: 'Appointment',
            'heading' => 'Appointment details',
            'appointment' => $appointment,
            'appointmentCommunications' => $communications->appointmentStatus($appointment),
        ]);
    }

    public function reschedule(
        Request $request,
        Appointment $appointment,
        SchedulingReadService $read,
    ): View|RedirectResponse {
        $query = $request->validate([
            'scheduling_host_id' => ['nullable', 'integer'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $appointment = $read->appointmentDetail($appointment);
        $service = $appointment->bookableService;
        $replacement = $appointment->rescheduledAppointments->first();

        if (! in_array($appointment->status, self::RESCHEDULABLE_STATUSES, true)
            || $replacement instanceof Appointment
        ) {
            return redirect()
                ->route('crm.scheduling.appointments.show', $appointment)
                ->with('error', 'This appointment is no longer eligible for rescheduling.');
        }

        if (! $service instanceof BookableService
            || $service->status !== BookableService::STATUS_ACTIVE
        ) {
            return redirect()
                ->route('crm.scheduling.appointments.show', $appointment)
                ->with('error', 'The appointment service is no longer available for rescheduling.');
        }

        $hosts = $read->eligibleHosts($service);
        $requiresHost = $read->serviceRequiresHost($service);
        $requestedHostId = $this->oldOrQueryInteger(
            request: $request,
            oldKey: 'scheduling_host_id',
            query: $query,
        );
        $selectedHost = $hosts->first(
            fn (SchedulingHost $host): bool =>
                (int) $host->getKey() === $requestedHostId,
        );

        if ($selectedHost === null && $appointment->scheduling_host_id !== null) {
            $selectedHost = $hosts->first(
                fn (SchedulingHost $host): bool =>
                    (int) $host->getKey() === (int) $appointment->scheduling_host_id,
            );
        }

        if ($selectedHost === null && $requiresHost && $hosts->count() === 1) {
            $selectedHost = $hosts->first();
        }

        $timezone = in_array($service->timezone, timezone_identifiers_list(), true)
            ? $service->timezone
            : 'UTC';
        $dateValue = $request->old('date')
            ?? ($query['date'] ?? $appointment->starts_at?->setTimezone($timezone)->toDateString())
            ?? CarbonImmutable::now($timezone)->toDateString();
        $selectedDate = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            (string) $dateValue,
            $timezone,
        );

        if (! $selectedDate instanceof CarbonImmutable) {
            $selectedDate = CarbonImmutable::now($timezone)->startOfDay();
        }

        $dateMinimum = CarbonImmutable::now($timezone)->startOfDay();
        $dateMaximum = $dateMinimum->addDays(
            max(0, (int) $service->booking_horizon_days),
        );
        $dateInRange = $selectedDate->betweenIncluded(
            $dateMinimum,
            $dateMaximum,
        );
        $slots = $dateInRange
            ? $read->rescheduleAvailabilityForDate(
                appointment: $appointment,
                date: $selectedDate,
                host: $selectedHost,
            )
            : [];
        $suggestedSlots = $read->rescheduleSuggestions(
            appointment: $appointment,
            host: $selectedHost,
        );
        $noticeMinutes = max(0, (int) $service->reschedule_notice_minutes);
        $noticeDeadline = $appointment->starts_at?->copy()
            ->subMinutes($noticeMinutes);
        $requiresNoticeOverride = $noticeDeadline !== null
            && CarbonImmutable::now('UTC')->greaterThan($noticeDeadline);
        $canPreserveConfirmation = $service->requires_confirmation
            && $appointment->status === Appointment::STATUS_CONFIRMED;

        return view('crm.scheduling.reschedule', [
            'title' => 'Reschedule '.($appointment->title ?: 'Appointment'),
            'heading' => 'Reschedule appointment',
            'appointment' => $appointment,
            'service' => $service,
            'hosts' => $hosts,
            'selectedHost' => $selectedHost,
            'requiresHost' => $requiresHost,
            'selectedDate' => $selectedDate,
            'dateMinimum' => $dateMinimum,
            'dateMaximum' => $dateMaximum,
            'dateInRange' => $dateInRange,
            'slots' => $slots,
            'suggestedSlots' => $suggestedSlots,
            'noticeMinutes' => $noticeMinutes,
            'requiresNoticeOverride' => $requiresNoticeOverride,
            'canPreserveConfirmation' => $canPreserveConfirmation,
            'idempotencyKey' => $request->old(
                'idempotency_key',
                (string) Str::uuid(),
            ),
        ]);
    }

    public function storeReschedule(
        RescheduleAppointmentRequest $request,
        Appointment $appointment,
        RescheduleAppointmentToSlotAction $rescheduleAppointment,
    ): RedirectResponse {
        $host = $request->hostId() !== null
            ? SchedulingHost::query()
                ->where('status', SchedulingHost::STATUS_ACTIVE)
                ->findOrFail($request->hostId())
            : null;

        try {
            $replacement = $rescheduleAppointment->handle(
                appointment: $appointment,
                startsAt: $request->startsAt(),
                idempotencyKey: $request->idempotencyKey(),
                lifecycle: new AppointmentLifecycleContext(
                    actor: $request->user(),
                    source: 'crm',
                    reason: $request->reason(),
                    force: $request->overridesNotice(),
                    context: [
                        'surface' => 'crm_scheduling_appointment_reschedule',
                        'action' => 'reschedule',
                        'requested_preserve_confirmation' => $request->preserveConfirmation(),
                        'override_reschedule_notice' => $request->overridesNotice(),
                    ],
                ),
                host: $host,
                preserveConfirmation: $request->preserveConfirmation(),
            );
        } catch (DomainException|InvalidArgumentException|LogicException $exception) {
            throw ValidationException::withMessages([
                'starts_at' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('crm.scheduling.appointments.show', $replacement)
            ->with('success', 'Appointment rescheduled.');
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

    /**
     * @param array<string, mixed> $query
     */
    private function oldOrQueryInteger(
        Request $request,
        string $oldKey,
        array $query,
    ): int {
        $value = $request->old($oldKey, $query[$oldKey] ?? 0);

        return is_numeric($value) ? (int) $value : 0;
    }
}