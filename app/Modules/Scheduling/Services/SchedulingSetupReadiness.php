<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;

final class SchedulingSetupReadiness
{
    public function __construct(
        private readonly SchedulingServiceReadiness $serviceReadiness,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $activeServices = BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->get();
        $serviceStates = $activeServices
            ->map(fn (BookableService $service): array => $this->serviceReadiness->forService($service));
        $activeHostCount = SchedulingHost::query()
            ->where('status', SchedulingHost::STATUS_ACTIVE)
            ->count();
        $upcomingAppointmentCount = Appointment::query()
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '>=', now('UTC'))
            ->count();
        $internalReadyCount = $serviceStates
            ->filter(fn (array $state): bool => (bool) $state['internal_ready'])
            ->count();
        $publicReadyCount = $serviceStates
            ->filter(fn (array $state): bool => (bool) $state['public_ready'])
            ->count();
        $publicRequestedCount = $serviceStates
            ->filter(fn (array $state): bool => (bool) $state['self_booking_enabled'])
            ->count();
        $hasAvailability = $serviceStates
            ->contains(fn (array $state): bool => (bool) $state['has_availability']);
        $hasCompleteFormat = $serviceStates
            ->contains(fn (array $state): bool => (bool) $state['format_complete']);
        $publicSurfaceReady = (bool) config('scheduling.public.enabled', false)
            && trim((string) config('scheduling.public.url', '')) !== '';

        return [
            'empty' => $activeServices->isEmpty() && $upcomingAppointmentCount === 0,
            'has_service' => $activeServices->isNotEmpty(),
            'has_complete_format' => $hasCompleteFormat,
            'has_active_host' => $activeHostCount > 0,
            'has_availability' => $hasAvailability,
            'internal_ready' => $internalReadyCount > 0,
            'public_ready' => $publicReadyCount > 0,
            'public_surface_enabled' => $publicSurfaceReady,
            'has_public_service' => $publicRequestedCount > 0,
            'has_incomplete_public_service' => $publicRequestedCount > $publicReadyCount,
            'active_service_count' => $activeServices->count(),
            'internally_ready_service_count' => $internalReadyCount,
            'public_ready_service_count' => $publicReadyCount,
            'active_host_count' => $activeHostCount,
            'upcoming_appointment_count' => $upcomingAppointmentCount,
        ];
    }
}