<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;

final class SchedulingServiceReadiness
{
    /**
     * @return array<string, mixed>
     */
    public function forService(BookableService $service): array
    {
        $active = $service->exists
            && ! $service->trashed()
            && $service->status === BookableService::STATUS_ACTIVE;
        $formatComplete = $service->hasCompleteAppointmentFormat();
        $hasAvailability = $active && $this->hasAvailability($service);
        $hasAssignmentRows = $active && BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->exists();
        $hasActiveStaff = $active && BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->whereHas('schedulingHost', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('status', SchedulingHost::STATUS_ACTIVE))
            ->exists();
        $staffRequired = $hasAssignmentRows;
        $internalReady = $active
            && $formatComplete
            && $hasAvailability
            && (! $staffRequired || $hasActiveStaff);

        $publicUrl = trim((string) config('scheduling.public.url', ''));
        $publicSurfaceReady = (bool) config('scheduling.public.enabled', false)
            && $publicUrl !== '';
        $selfBookingEnabled = (bool) $service->is_public;
        $publicReady = $internalReady
            && $selfBookingEnabled
            && $publicSurfaceReady;

        $internalBlockers = [];

        if (! $active) {
            $internalBlockers[] = $this->blocker(
                key: 'active',
                label: 'Activate the appointment type',
                description: 'This appointment type must be active before it can be booked.',
                actionLabel: 'Edit appointment details',
                url: route('crm.scheduling.configuration.services.details.edit', $service),
            );
        }

        if (! $formatComplete) {
            $internalBlockers[] = $this->blocker(
                key: 'appointment_format',
                label: 'Choose how the appointment happens',
                description: 'Set the appointment method before Scheduling can treat this appointment type as ready to book.',
                actionLabel: 'Set appointment method',
                url: route('crm.scheduling.configuration.services.details.edit', $service),
            );
        }

        if (! $hasAvailability) {
            $internalBlockers[] = $this->blocker(
                key: 'availability',
                label: 'Set availability',
                description: 'Add regular hours or another available window so Scheduling has times it can offer.',
                actionLabel: 'Set availability',
                url: route('crm.scheduling.configuration.availability.index', [
                    'service_id' => $service->getKey(),
                ]),
            );
        }

        if ($staffRequired && ! $hasActiveStaff) {
            $internalBlockers[] = $this->blocker(
                key: 'staff',
                label: 'Activate assigned staff',
                description: 'This appointment type uses explicit staff assignments, but none of those assignments currently resolve to an active staff member.',
                actionLabel: 'Review staff assignments',
                url: route('crm.scheduling.configuration.services.staff.edit', $service),
            );
        }

        $publicBlockers = $internalBlockers;

        if ($internalBlockers === [] && ! $selfBookingEnabled) {
            $publicBlockers[] = $this->blocker(
                key: 'self_booking',
                label: 'Turn on customer self-booking',
                description: 'This appointment type is ready internally, but customers cannot book it themselves until self-booking is enabled.',
                actionLabel: 'Edit appointment details',
                url: route('crm.scheduling.configuration.services.details.edit', $service),
            );
        }

        if ($internalBlockers === [] && $selfBookingEnabled && ! $publicSurfaceReady) {
            $publicBlockers[] = $this->blocker(
                key: 'public_surface',
                label: 'Configure the public Scheduling URL',
                description: 'Customer self-booking is turned on, but this environment does not have an enabled public Scheduling URL yet. Configure the public Scheduling origin for this client, then return here.',
                actionLabel: 'Review Scheduling setup',
                url: route('crm.scheduling.configuration.index'),
            );
        }

        return [
            'active' => $active,
            'format_complete' => $formatComplete,
            'has_availability' => $hasAvailability,
            'has_assignment_rows' => $hasAssignmentRows,
            'has_active_staff' => $hasActiveStaff,
            'staff_required' => $staffRequired,
            'internal_ready' => $internalReady,
            'self_booking_enabled' => $selfBookingEnabled,
            'public_surface_ready' => $publicSurfaceReady,
            'public_ready' => $publicReady,
            'internal_blockers' => $internalBlockers,
            'public_blockers' => $publicBlockers,
            'internal_issue' => $internalBlockers[0]['description'] ?? null,
            'public_issue' => $publicBlockers[0]['description'] ?? null,
            'public_url' => $publicUrl !== ''
                ? rtrim($publicUrl, '/').'/services/'.rawurlencode((string) $service->key)
                : null,
        ];
    }

    public function hasAvailability(BookableService $service): bool
    {
        if (! $service->exists || $service->trashed()) {
            return false;
        }

        $activeHostIds = BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->whereHas('schedulingHost', fn ($query) => $query
                ->whereNull('deleted_at')
                ->where('status', SchedulingHost::STATUS_ACTIVE))
            ->pluck('scheduling_host_id');

        $timeRelevant = static function ($query): void {
            $query
                ->where('window_type', SchedulingAvailabilityWindowType::Weekly->value)
                ->orWhere(function ($query): void {
                    $query
                        ->where('window_type', SchedulingAvailabilityWindowType::Absolute->value)
                        ->where('ends_at', '>', now('UTC'));
                });
        };

        $serviceQuery = SchedulingAvailabilityWindow::query()
            ->where('is_available', true)
            ->where('bookable_service_id', $service->getKey())
            ->where($timeRelevant)
            ->where(function ($query) use ($activeHostIds): void {
                $query->whereNull('scheduling_host_id');

                if ($activeHostIds->isNotEmpty()) {
                    $query->orWhereIn('scheduling_host_id', $activeHostIds);
                }
            });

        if ($serviceQuery->exists()) {
            return true;
        }

        if ($activeHostIds->isEmpty()) {
            return false;
        }

        return SchedulingAvailabilityWindow::query()
            ->where('is_available', true)
            ->whereNull('bookable_service_id')
            ->whereIn('scheduling_host_id', $activeHostIds)
            ->where($timeRelevant)
            ->exists();
    }

    /**
     * @return array{key: string, label: string, description: string, action_label: string, url: string}
     */
    private function blocker(
        string $key,
        string $label,
        string $description,
        string $actionLabel,
        string $url,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'action_label' => $actionLabel,
            'url' => $url,
        ];
    }
}