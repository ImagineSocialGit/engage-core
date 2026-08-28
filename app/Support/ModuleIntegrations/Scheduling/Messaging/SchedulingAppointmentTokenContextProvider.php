<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Support\TokenContracts\Contracts\TokenContextProvider;
use App\Support\TokenContracts\Data\TokenContextDefinition;

final class SchedulingAppointmentTokenContextProvider implements TokenContextProvider
{
    public function contexts(): iterable
    {
        yield new TokenContextDefinition(
            key: 'scheduling_appointment',
            owner: 'scheduling',
            description: 'Transactional appointment confirmations, reminders, scheduling updates, and appointment-related follow-up.',
            sourceTokens: [
                'contact.first_name',
                'contact.last_name',
                'contact.name',
                'contact.email',
                'contact.phone',
                'appointment.display_date',
                'appointment.display_time_with_timezone',
                'appointment.location_or_method',
            ],
            channels: ['email', 'sms'],
            purposes: ['transactional'],
            scopes: ['scheduling_appointments'],
            surfaces: ['scheduling_appointments'],
        );
    }
}