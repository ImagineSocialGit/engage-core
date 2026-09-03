<?php

namespace App\Support\ModuleIntegrations\Scheduling\Contracts;

use App\Modules\Scheduling\Models\BookableService;

interface AppointmentAfterBookingWorkspace
{
    /**
     * @return array<string, mixed>
     */
    public function read(): array;

    /**
     * @param array<string, mixed> $input
     */
    public function update(
        BookableService $service,
        array $input,
    ): void;
}