<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;

final class SchedulingSetupReadiness
{
    /**
     * @return array{
     *     empty: bool,
     *     has_service: bool,
     *     has_active_host: bool,
     *     has_availability: bool,
     *     internal_ready: bool,
     *     public_ready: bool,
     *     public_surface_enabled: bool,
     *     has_public_service: bool,
     *     has_incomplete_public_service: bool,
     *     active_service_count: int,
     *     active_host_count: int,
     *     upcoming_appointment_count: int
     * }
     */
    public function summary(): array
    {
        $activeServices = BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->get([
                'id',
                'is_public',
                'appointment_format',
                'in_person_arrangement',
                'remote_method',
                'location_type',
            ]);
        $activeHosts = SchedulingHost::query()
            ->where('status', SchedulingHost::STATUS_ACTIVE)
            ->get(['id']);
        $activeServiceIds = $activeServices->pluck('id')->all();
        $activeHostIds = $activeHosts->pluck('id')->all();

        $hasService = $activeServices->isNotEmpty();
        $hasActiveHost = $activeHosts->isNotEmpty();
        $hasAvailability = $hasService
            && SchedulingAvailabilityWindow::query()
                ->where('is_available', true)
                ->where(function ($query): void {
                    $query
                        ->where(
                            'window_type',
                            SchedulingAvailabilityWindowType::Weekly->value,
                        )
                        ->orWhere(function ($query): void {
                            $query
                                ->where(
                                    'window_type',
                                    SchedulingAvailabilityWindowType::Absolute->value,
                                )
                                ->where('ends_at', '>', now('UTC'));
                        });
                })
                ->where(function ($query) use ($activeServiceIds): void {
                    $query
                        ->whereNull('bookable_service_id')
                        ->orWhereIn('bookable_service_id', $activeServiceIds);
                })
                ->where(function ($query) use ($activeHostIds): void {
                    $query->whereNull('scheduling_host_id');

                    if ($activeHostIds !== []) {
                        $query->orWhereIn('scheduling_host_id', $activeHostIds);
                    }
                })
                ->exists();
        $internalReady = $hasService && $hasAvailability;
        $publicSurfaceEnabled = (bool) config('scheduling.public.enabled', false);
        $publicServices = $activeServices->filter(
            fn (BookableService $service): bool => (bool) $service->is_public,
        );
        $completePublicServices = $publicServices->filter(
            fn (BookableService $service): bool => $service->hasCompleteAppointmentFormat(),
        );
        $hasPublicService = $completePublicServices->isNotEmpty();
        $hasIncompletePublicService = $publicServices->count()
            !== $completePublicServices->count();
        $upcomingAppointmentCount = Appointment::query()
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '>=', now('UTC'))
            ->count();

        return [
            'empty' => ! $hasService && $upcomingAppointmentCount === 0,
            'has_service' => $hasService,
            'has_active_host' => $hasActiveHost,
            'has_availability' => $hasAvailability,
            'internal_ready' => $internalReady,
            'public_ready' => $internalReady
                && $publicSurfaceEnabled
                && $hasPublicService
                && ! $hasIncompletePublicService,
            'public_surface_enabled' => $publicSurfaceEnabled,
            'has_public_service' => $hasPublicService,
            'has_incomplete_public_service' => $hasIncompletePublicService,
            'active_service_count' => $activeServices->count(),
            'active_host_count' => $activeHosts->count(),
            'upcoming_appointment_count' => $upcomingAppointmentCount,
        ];
    }
}