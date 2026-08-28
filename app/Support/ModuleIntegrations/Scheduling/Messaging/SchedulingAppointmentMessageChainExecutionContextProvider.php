<?php

namespace App\Support\ModuleIntegrations\Scheduling\Messaging;

use App\Modules\Core\Models\Contact;
use App\Modules\Messaging\Contracts\MessageChainExecutionContextProvider;
use App\Modules\Messaging\Models\MessageChainEnrollment;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Support\Carbon;

final class SchedulingAppointmentMessageChainExecutionContextProvider implements MessageChainExecutionContextProvider
{
    public const SURFACE = 'scheduling_appointments';

    public function supports(MessageChainEnrollment $enrollment): bool
    {
        $enrollment->loadMissing(['recipient', 'context', 'origin']);

        return $enrollment->surface === self::SURFACE
            && $enrollment->recipient instanceof Contact
            && $enrollment->context instanceof Appointment
            && $enrollment->origin instanceof Appointment;
    }

    public function values(MessageChainEnrollment $enrollment): array
    {
        $enrollment->loadMissing(['recipient', 'context', 'origin']);

        $appointment = $enrollment->context;

        if (! $appointment instanceof Appointment) {
            return [];
        }

        $displayDate = $this->displayDate($appointment);
        $displayTime = $this->displayTime($appointment);
        $location = $this->locationOrMethod($appointment);

        return [
            'appointment' => [
                ...$appointment->attributesToArray(),
                'display_date' => $displayDate,
                'display_time_with_timezone' => $displayTime,
                'location_or_method' => $location,
            ],
            'appointment_date' => $displayDate,
            'appointment_time_with_timezone' => $displayTime,
            'appointment_location_or_method' => $location,
        ];
    }

    private function displayDate(Appointment $appointment): string
    {
        return $this->localStart($appointment)->format('F j, Y');
    }

    private function displayTime(Appointment $appointment): string
    {
        return $this->localStart($appointment)->format('g:i A T');
    }

    private function localStart(Appointment $appointment): Carbon
    {
        $timezone = is_string($appointment->timezone)
            && in_array($appointment->timezone, timezone_identifiers_list(), true)
                ? $appointment->timezone
                : (string) config('client.timezone', config('app.timezone', 'UTC'));

        return Carbon::parse($appointment->starts_at)->timezone($timezone);
    }

    private function locationOrMethod(Appointment $appointment): string
    {
        $snapshot = $appointment->locationSnapshot();
        $type = $snapshot?->type ?? $appointment->location_type;
        $details = $snapshot?->details ?? (
            is_array($appointment->location_details)
                ? $appointment->location_details
                : []
        );

        if ($type === BookableService::LOCATION_TYPE_PHONE) {
            return $this->joined([
                $this->string(data_get($details, 'label')) ?? 'By phone',
                $this->string(data_get($details, 'instructions')),
            ]);
        }

        if ($type === BookableService::LOCATION_TYPE_VIRTUAL) {
            return $this->joined([
                $this->string(data_get($details, 'label')) ?? 'Online',
                $this->string(data_get($details, 'url')),
                $this->string(data_get($details, 'instructions')),
            ]);
        }

        if (in_array($type, [
            BookableService::LOCATION_TYPE_FIXED,
            BookableService::LOCATION_TYPE_CUSTOMER_SITE,
        ], true)) {
            $address = $this->string(data_get($details, 'address.formatted_address'));

            if ($address === null) {
                $cityLine = $this->joined([
                    $this->string(data_get($details, 'address.city')),
                    $this->string(data_get($details, 'address.region')),
                    $this->string(data_get($details, 'address.postal_code')),
                ], ', ');

                $address = $this->joined([
                    $this->string(data_get($details, 'address.address_line_1')),
                    $this->string(data_get($details, 'address.address_line_2')),
                    $cityLine !== '' ? $cityLine : null,
                ], ', ');
            }

            return $this->joined([
                $this->string(data_get($details, 'label')),
                $address !== '' ? $address : null,
                $this->string(data_get($details, 'instructions')),
            ]);
        }

        return 'See your appointment details for location information.';
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    /** @param array<int, string|null> $values */
    private function joined(array $values, string $separator = ' · '): string
    {
        return implode($separator, array_values(array_filter(
            $values,
            static fn (?string $value): bool => is_string($value) && trim($value) !== '',
        )));
    }
}