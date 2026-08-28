<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Support\TokenContracts\Contracts\TokenSourceProvider;
use App\Support\TokenContracts\Data\TokenSourceDefinition;

final class SchedulingAppointmentTokenSourceProvider implements TokenSourceProvider
{
    public function sources(): iterable
    {
        yield TokenSourceDefinition::computed(
            token: 'appointment.display_date',
            owner: 'scheduling',
            label: 'Appointment date',
            description: 'Appointment date formatted in the appointment timezone.',
            sourcePath: 'appointment.display_date',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_date'],
            nullable: false,
        );

        yield TokenSourceDefinition::computed(
            token: 'appointment.display_time_with_timezone',
            owner: 'scheduling',
            label: 'Appointment time with time zone',
            description: 'Appointment time formatted with its time-zone abbreviation.',
            sourcePath: 'appointment.display_time_with_timezone',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_time_with_timezone'],
            nullable: false,
        );

        yield TokenSourceDefinition::computed(
            token: 'appointment.location_or_method',
            owner: 'scheduling',
            label: 'Appointment location or method',
            description: 'Durable appointment location details or remote meeting method.',
            sourcePath: 'appointment.location_or_method',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_location_or_method'],
            nullable: false,
        );
    }
}