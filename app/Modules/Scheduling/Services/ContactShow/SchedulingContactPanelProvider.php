<?php

namespace App\Modules\Scheduling\Services\ContactShow;

use App\Modules\Core\Contracts\Contacts\ContactPanelProvider;
use App\Modules\Core\Data\Contacts\ContactPanel;
use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Services\SchedulingReadService;

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

        return [
            new ContactPanel(
                key: 'scheduling-appointments',
                title: 'Appointments',
                view: 'crm.scheduling.contact-panel',
                data: [
                    'upcomingAppointments' => $upcomingAppointments,
                    'recentAppointments' => $recentAppointments,
                    'pendingAppointmentCount' => $upcomingAppointments
                        ->where('status', Appointment::STATUS_PENDING)
                        ->count(),
                ],
                sort: 90,
                module: 'scheduling',
            ),
        ];
    }
}