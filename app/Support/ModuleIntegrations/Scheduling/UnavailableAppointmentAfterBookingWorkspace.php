<?php

namespace App\Support\ModuleIntegrations\Scheduling;

use App\Modules\Scheduling\Models\BookableService;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentAfterBookingWorkspace;
use Illuminate\Validation\ValidationException;

final class UnavailableAppointmentAfterBookingWorkspace implements AppointmentAfterBookingWorkspace
{
    public function read(): array
    {
        return [
            'mode' => 'unavailable',
            'services' => [],
        ];
    }

    public function update(
        BookableService $service,
        array $input,
    ): void {
        throw ValidationException::withMessages([
            'after_booking' => 'After-booking configuration is unavailable.',
        ]);
    }
}