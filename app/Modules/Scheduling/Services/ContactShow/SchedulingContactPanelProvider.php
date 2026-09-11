<?php

namespace App\Modules\Scheduling\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\SchedulingReadService;
use Illuminate\Support\Collection;

class SchedulingContactPanelProvider implements ContactPanelProvider
{
    public function __construct(
        private readonly SchedulingReadService $read,
    ) {}

    public function panels(Contact $contact): array
    {
        $upcomingAppointments = $this->read
            ->contactUpcomingAppointments($contact);
        $recentAppointments = $this->read
            ->contactRecentTerminalAppointments($contact);
        $nextAppointment = $upcomingAppointments->first();

        return [
            new ContactPanel(
                key: 'scheduling-appointments',
                title: 'Appointments',
                view: 'crm.scheduling.contact-panel',
                data: [
                    // Keep the model collections available to module consumers while
                    // Blade receives presentation-ready rows below.
                    'upcomingAppointments' => $upcomingAppointments,
                    'recentAppointments' => $recentAppointments,
                    'pendingAppointmentCount' => $upcomingAppointments
                        ->where('status', Appointment::STATUS_PENDING)
                        ->count(),
                    'nextAppointmentPresentation' => $nextAppointment instanceof Appointment
                        ? $this->presentAppointment($nextAppointment, 'next')
                        : null,
                    'otherUpcomingAppointmentPresentations' => $this->presentAppointments(
                        $upcomingAppointments->skip(1)->values(),
                        'upcoming',
                    ),
                    'recentAppointmentPresentations' => $this->presentAppointments(
                        $recentAppointments,
                        'recent',
                    ),
                    'hasAnyAppointment' => $upcomingAppointments->isNotEmpty()
                        || $recentAppointments->isNotEmpty(),
                ],
                sort: 90,
                module: 'scheduling',
            ),
        ];
    }

    /**
     * @param Collection<int, Appointment> $appointments
     * @return array<int, array<string, mixed>>
     */
    private function presentAppointments(Collection $appointments, string $kind): array
    {
        return $appointments
            ->map(fn (Appointment $appointment): array =>
                $this->presentAppointment($appointment, $kind)
            )
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentAppointment(Appointment $appointment, string $kind): array
    {
        $timezone = $this->displayTimezone($appointment);
        $replacement = $appointment->rescheduledAppointments->first();
        $rescheduleNote = null;

        if ($appointment->rescheduledFrom) {
            $rescheduleNote = 'Replacement for an earlier appointment.';
        } elseif ($replacement instanceof Appointment) {
            $rescheduleNote = 'Rescheduled to '
                .$replacement->starts_at
                    ->setTimezone($this->displayTimezone($replacement))
                    ->format('M j, Y g:i A')
                .'.';
        }

        return [
            'id' => (int) $appointment->getKey(),
            'kind' => $kind,
            'status' => (string) $appointment->status,
            'status_label' => str((string) $appointment->status)
                ->replace('_', ' ')
                ->title()
                ->toString(),
            'status_classes' => $this->statusClasses((string) $appointment->status),
            'title' => $appointment->title
                ?: $appointment->bookableService?->name
                ?: 'Appointment',
            'show_url' => route('crm.scheduling.appointments.show', $appointment),
            'starts_at_label' => $appointment->starts_at
                ->setTimezone($timezone)
                ->format($kind === 'next' ? 'D, M j, Y \a\t g:i A' : 'M j, Y g:i A'),
            'ends_at_label' => $appointment->ends_at
                ->setTimezone($timezone)
                ->format('g:i A'),
            'timezone' => $timezone,
            'host_name' => $appointment->schedulingHost?->name,
            'reschedule_note' => $rescheduleNote,
        ];
    }

    private function displayTimezone(Appointment $appointment): string
    {
        $timezone = (string) $appointment->timezone;

        if (in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        $fallback = (string) config(
            'client.timezone',
            config('app.timezone', 'UTC'),
        );

        return in_array($fallback, timezone_identifiers_list(), true)
            ? $fallback
            : 'UTC';
    }

    private function statusClasses(string $status): string
    {
        return match ($status) {
            Appointment::STATUS_PENDING => 'bg-amber-100 text-amber-800',
            Appointment::STATUS_CONFIRMED,
            Appointment::STATUS_COMPLETED => 'bg-emerald-100 text-emerald-800',
            Appointment::STATUS_CANCELED => 'bg-slate-100 text-slate-700',
            Appointment::STATUS_NO_SHOW => 'bg-rose-100 text-rose-800',
            default => 'bg-sky-100 text-sky-800',
        };
    }
}