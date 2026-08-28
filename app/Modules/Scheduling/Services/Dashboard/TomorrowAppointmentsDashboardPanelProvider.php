<?php

namespace App\Modules\Scheduling\Services\Dashboard;

use App\Support\Dashboard\Contracts\DashboardPanelProvider;
use Illuminate\Http\Request;

final class TomorrowAppointmentsDashboardPanelProvider implements DashboardPanelProvider
{
    public function __construct(
        private readonly SchedulingDashboardAppointments $appointments,
    ) {}

    public function key(): string
    {
        return 'scheduling.tomorrow';
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
        $summary = $this->appointments->forLocalDay(1, 6);
        $count = $summary['count'];
        $pendingCount = $summary['pending_count'];
        $appointments = $summary['appointments'];

        return [
            'key' => $this->key(),
            'module' => $this->module(),
            'slot' => 'context',
            'priority' => $pendingCount > 0 ? 80 : 60,
            'order' => 5,
            'view' => 'scheduling_tomorrow',
            'title' => 'Prep for tomorrow',
            'description' => 'Tomorrow’s appointments in order, with the details you need to prepare before the day starts.',
            'empty_title' => 'No appointments are scheduled for tomorrow.',
            'empty_description' => 'There is nothing to prepare for tomorrow yet.',
            'summary_label' => 'appointments tomorrow',
            'count' => $count,
            'attention_count' => $pendingCount,
            'hide_when_empty' => true,
            'items' => $appointments
                ->map(fn ($appointment): array => $this->appointments->item($appointment, 'Tomorrow'))
                ->values(),
            'primary_action' => $appointments->isNotEmpty() ? [
                'label' => 'Review tomorrow’s appointments',
                'href' => route('crm.scheduling.index'),
                'summary' => 'Review tomorrow’s schedule while there is still time to prepare.',
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