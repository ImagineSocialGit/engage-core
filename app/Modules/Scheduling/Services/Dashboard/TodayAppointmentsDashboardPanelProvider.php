<?php

namespace App\Modules\Scheduling\Services\Dashboard;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Http\Request;

final class TodayAppointmentsDashboardPanelProvider implements DashboardPanelProvider
{
    public function __construct(
        private readonly SchedulingDashboardAppointments $appointments,
    ) {}

    public function key(): string
    {
        return 'scheduling.today';
    }

    public function module(): string
    {
        return 'scheduling';
    }

    /**
     * @return array<string, mixed>
     */
    public function panel(Request $request): array
    {
        $summary = $this->appointments->forLocalDay(0, 8);
        $count = $summary['count'];
        $pendingCount = $summary['pending_count'];
        $appointments = $summary['appointments'];
        $primaryAppointment = $pendingCount > 0
            ? $appointments->firstWhere('status', Appointment::STATUS_PENDING)
            : $appointments->first();

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'immediate_work',
            'priority' => $pendingCount > 0 ? 125 : ($count > 0 ? 115 : 70),
            'order' => 30,
            'view' => 'scheduling_today',
            'title' => 'Today’s appointments',
            'description' => 'Your remaining appointments for today, in time order.',
            'empty_title' => 'No appointments are scheduled for today.',
            'empty_description' => 'The rest of today is clear.',
            'summary_label' => 'appointments today',
            'count' => $count,
            'attention_count' => $pendingCount,
            'items' => $appointments
                ->map(fn ($appointment): array => $this->appointments->item($appointment, 'Today'))
                ->values(),
            'primary_action' => $primaryAppointment instanceof Appointment ? [
                'label' => $pendingCount > 0
                    ? 'Review appointment confirmation'
                    : 'Open next appointment',
                'href' => route('crm.scheduling.appointments.show', $primaryAppointment),
                'summary' => $pendingCount > 0
                    ? 'Start with the earliest appointment that still needs confirmation.'
                    : 'Open the next appointment on today’s schedule.',
            ] : null,
            'actions' => [
                [
                    'label' => 'View Scheduling',
                    'href' => route('crm.scheduling.index'),
                ],
            ],
        ];
    }
}