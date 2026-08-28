<?php

namespace App\Support\ModuleIntegrations\Scheduling\Contracts;

use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;

interface AppointmentCommunications
{
    public function available(): bool;

    /** @return array<string, mixed> */
    public function plan(): array;

    /** @return array<string, mixed> */
    public function generateDefaultSchedule(?User $actor = null): array;

    /**
     * @param array<int, array<string, mixed>> $steps
     * @return array<string, mixed>
     */
    public function saveSchedule(array $steps, ?User $actor = null): array;

    public function appointmentCreated(Appointment $appointment): void;

    public function publicBookingCompleted(
        Appointment $appointment,
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): void;

    public function appointmentRescheduled(
        Appointment $original,
        Appointment $replacement,
    ): void;

    public function appointmentCancelled(Appointment $appointment): void;

    public function appointmentCompleted(Appointment $appointment): void;

    public function appointmentNoShow(Appointment $appointment): void;

    /** @return array<string, mixed> */
    public function appointmentStatus(Appointment $appointment): array;
}