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
            label: 'Meeting date',
            description: 'The appointment date formatted in the meeting’s time zone.',
            sourcePath: 'appointment.display_date',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_date'],
            nullable: false,
            example: 'September 15, 2026',
        );

        yield TokenSourceDefinition::computed(
            token: 'appointment.display_time_with_timezone',
            owner: 'scheduling',
            label: 'Meeting time',
            description: 'The appointment time with its time-zone abbreviation.',
            sourcePath: 'appointment.display_time_with_timezone',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_time_with_timezone'],
            nullable: false,
            example: '2:00 PM EDT',
        );

        yield TokenSourceDefinition::computed(
            token: 'appointment.location_or_method',
            owner: 'scheduling',
            label: 'Meeting location or link',
            description: 'Where the appointment happens, including its remote meeting link when applicable.',
            sourcePath: 'appointment.location_or_method',
            providerClass: SchedulingAppointmentComputedTokenValueProvider::class,
            aliases: ['appointment_location_or_method'],
            nullable: false,
            example: 'Zoom — https://…',
        );
    }
}