<?php

namespace App\Support\ModuleIntegrations\Scheduling;

use App\Models\User;
use App\Modules\Scheduling\Models\Appointment;
use App\Support\ModuleIntegrations\Scheduling\Contracts\AppointmentCommunications;

final class UnavailableAppointmentCommunications implements AppointmentCommunications
{
    public function available(): bool
    {
        return false;
    }

    public function plan(): array
    {
        return [
            'available' => false,
            'configured' => false,
            'steps' => [],
            'channels' => [],
            'media_authoring' => ['available' => false, 'assets' => [], 'image_assets' => []],
        ];
    }

    public function authoringRules(): array
    {
        return [];
    }

    public function generateDefaultSchedule(?User $actor = null): array
    {
        return $this->plan();
    }

    public function saveSchedule(array $steps, ?User $actor = null): array
    {
        return $this->plan();
    }

    public function appointmentCreated(Appointment $appointment): void {}

    public function publicBookingCompleted(
        Appointment $appointment,
        ?string $sourceIp = null,
        ?string $userAgent = null,
    ): void {}

    public function appointmentRescheduled(
        Appointment $original,
        Appointment $replacement,
    ): void {}

    public function appointmentCancelled(Appointment $appointment): void {}

    public function appointmentCompleted(Appointment $appointment): void {}

    public function appointmentNoShow(Appointment $appointment): void {}

    public function appointmentStatus(Appointment $appointment): array
    {
        return [
            'available' => false,
            'configured' => false,
            'enrollments' => [],
            'messages' => [],
        ];
    }
}