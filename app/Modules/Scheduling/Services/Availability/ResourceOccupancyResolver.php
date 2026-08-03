<?php

namespace App\Modules\Scheduling\Services\Availability;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\BookingHold;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use App\Modules\Scheduling\Models\SchedulingResourceOccupancy;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ResourceOccupancyResolver
{
    /**
     * @return array{capacity: int|null, remaining: int|null}
     */
    public function capacityFor(
        BookableService $service,
        ?SchedulingHost $host,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        CarbonInterface $evaluatedAt,
        ?Appointment $rescheduleAppointment = null,
    ): array {
        $requirements = $this->activeRequirements($service);

        if ($requirements->isEmpty()) {
            return [
                'capacity' => null,
                'remaining' => null,
            ];
        }

        if (! $host instanceof SchedulingHost || ! $host->exists) {
            return [
                'capacity' => 0,
                'remaining' => 0,
            ];
        }

        $resourceIds = $requirements
            ->pluck('scheduling_resource_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();
        $resources = SchedulingResource::query()
            ->whereKey($resourceIds->all())
            ->where('status', SchedulingResource::STATUS_ACTIVE)
            ->get()
            ->keyBy('id');

        if ($resources->count() !== $resourceIds->unique()->count()) {
            return [
                'capacity' => 0,
                'remaining' => 0,
            ];
        }

        $capacities = SchedulingHostResource::query()
            ->where('scheduling_host_id', $host->getKey())
            ->whereIn('scheduling_resource_id', $resourceIds->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('scheduling_resource_id');

        if ($capacities->count() !== $resourceIds->unique()->count()) {
            return [
                'capacity' => 0,
                'remaining' => 0,
            ];
        }

        $startsAt = CarbonImmutable::instance($startsAt)
            ->utc()
            ->subMinutes(max(0, (int) $service->buffer_before_minutes));
        $endsAt = CarbonImmutable::instance($endsAt)
            ->utc()
            ->addMinutes(max(0, (int) $service->buffer_after_minutes));
        $evaluatedAt = CarbonImmutable::instance($evaluatedAt)->utc();
        $occupancy = $this->overlappingOccupancy(
            host: $host,
            resourceIds: $resourceIds,
            startsAt: $startsAt,
            endsAt: $endsAt,
            evaluatedAt: $evaluatedAt,
            rescheduleAppointment: $rescheduleAppointment,
        )
            ->groupBy('scheduling_resource_id')
            ->map(static fn (Collection $rows): int => (int) $rows->sum('quantity'));
        $configuredCapacity = [];
        $remainingCapacity = [];

        foreach ($requirements as $requirement) {
            $resourceId = (int) $requirement->scheduling_resource_id;
            $quantity = (int) $requirement->quantity;
            $hostResource = $capacities->get($resourceId);

            if ($quantity < 1
                || ! $hostResource instanceof SchedulingHostResource
                || (int) $hostResource->capacity < 1
            ) {
                return [
                    'capacity' => 0,
                    'remaining' => 0,
                ];
            }

            $capacity = (int) $hostResource->capacity;
            $used = max(0, (int) $occupancy->get($resourceId, 0));
            $configuredCapacity[] = intdiv($capacity, $quantity);
            $remainingCapacity[] = intdiv(max(0, $capacity - $used), $quantity);
        }

        return [
            'capacity' => min($configuredCapacity),
            'remaining' => min($remainingCapacity),
        ];
    }

    /**
     * Lock and return the current requirement snapshot used by a new commitment.
     *
     * @return array<int, array{resource_id: int, quantity: int}>
     */
    public function lockRequirementSnapshot(
        BookableService $service,
        ?SchedulingHost $host,
    ): array {
        $requirements = BookableServiceResourceRequirement::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->orderBy('scheduling_resource_id')
            ->lockForUpdate()
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        if (! $host instanceof SchedulingHost || ! $host->exists) {
            throw new DomainException(
                'The selected service requires a scheduling host with resource capacity.',
            );
        }

        $resourceIds = $requirements
            ->pluck('scheduling_resource_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();
        $resources = SchedulingResource::withTrashed()
            ->whereKey($resourceIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $hostResources = SchedulingHostResource::query()
            ->where('scheduling_host_id', $host->getKey())
            ->whereIn('scheduling_resource_id', $resourceIds->all())
            ->orderBy('scheduling_resource_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('scheduling_resource_id');
        $snapshot = [];

        foreach ($requirements as $requirement) {
            $resourceId = (int) $requirement->scheduling_resource_id;
            $resource = $resources->get($resourceId);
            $hostResource = $hostResources->get($resourceId);
            $quantity = (int) $requirement->quantity;

            if (! $resource instanceof SchedulingResource
                || $resource->trashed()
                || $resource->status !== SchedulingResource::STATUS_ACTIVE
            ) {
                throw new DomainException(
                    'A required scheduling resource is no longer active.',
                );
            }

            if (! $hostResource instanceof SchedulingHostResource
                || ! $hostResource->is_active
                || (int) $hostResource->capacity < 1
            ) {
                throw new DomainException(
                    'The selected scheduling host no longer has the required active resource capacity.',
                );
            }

            if ($quantity < 1 || $quantity > (int) $hostResource->capacity) {
                throw new DomainException(
                    'A service resource requirement exceeds the selected host resource capacity.',
                );
            }

            $snapshot[] = [
                'resource_id' => $resourceId,
                'quantity' => $quantity,
            ];
        }

        return $snapshot;
    }

    /**
     * @param array<int, array{resource_id: int, quantity: int}> $snapshot
     */
    public function createForAppointment(
        Appointment $appointment,
        BookableService $service,
        SchedulingHost $host,
        array $snapshot,
    ): void {
        [$startsAt, $endsAt] = $this->snapshotRange(
            service: $service,
            startsAt: $appointment->starts_at,
            endsAt: $appointment->ends_at,
        );

        foreach ($snapshot as $requirement) {
            SchedulingResourceOccupancy::query()->create([
                'scheduling_resource_id' => $requirement['resource_id'],
                'scheduling_host_id' => $host->getKey(),
                'appointment_id' => $appointment->getKey(),
                'booking_hold_id' => null,
                'quantity' => $requirement['quantity'],
                'occupancy_starts_at' => $startsAt,
                'occupancy_ends_at' => $endsAt,
            ]);
        }
    }

    /**
     * @param array<int, array{resource_id: int, quantity: int}> $snapshot
     */
    public function createForHold(
        BookingHold $hold,
        SchedulingHost $host,
        array $snapshot,
    ): void {
        foreach ($snapshot as $requirement) {
            SchedulingResourceOccupancy::query()->create([
                'scheduling_resource_id' => $requirement['resource_id'],
                'scheduling_host_id' => $host->getKey(),
                'appointment_id' => null,
                'booking_hold_id' => $hold->getKey(),
                'quantity' => $requirement['quantity'],
                'occupancy_starts_at' => $hold->occupancy_starts_at,
                'occupancy_ends_at' => $hold->occupancy_ends_at,
            ]);
        }
    }

    public function transferHoldToAppointment(
        BookingHold $hold,
        Appointment $appointment,
    ): void {
        $occupancies = SchedulingResourceOccupancy::query()
            ->where('booking_hold_id', $hold->getKey())
            ->orderBy('scheduling_resource_id')
            ->lockForUpdate()
            ->get();

        foreach ($occupancies as $occupancy) {
            $occupancy->forceFill([
                'appointment_id' => $appointment->getKey(),
                'booking_hold_id' => null,
            ])->save();
        }
    }

    public function deleteForHold(BookingHold $hold): int
    {
        return SchedulingResourceOccupancy::query()
            ->where('booking_hold_id', $hold->getKey())
            ->delete();
    }

    /**
     * @param array<int, int> $holdIds
     */
    public function deleteForHoldIds(array $holdIds): int
    {
        if ($holdIds === []) {
            return 0;
        }

        return SchedulingResourceOccupancy::query()
            ->whereIn('booking_hold_id', $holdIds)
            ->delete();
    }

    /**
     * @return Collection<int, BookableServiceResourceRequirement>
     */
    private function activeRequirements(BookableService $service): Collection
    {
        return BookableServiceResourceRequirement::query()
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->orderBy('scheduling_resource_id')
            ->get();
    }

    /**
     * @param Collection<int, int> $resourceIds
     * @return Collection<int, SchedulingResourceOccupancy>
     */
    private function overlappingOccupancy(
        SchedulingHost $host,
        Collection $resourceIds,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        CarbonImmutable $evaluatedAt,
        ?Appointment $rescheduleAppointment,
    ): Collection {
        return SchedulingResourceOccupancy::query()
            ->where('scheduling_host_id', $host->getKey())
            ->whereIn('scheduling_resource_id', $resourceIds->all())
            ->where('occupancy_starts_at', '<', $endsAt)
            ->where('occupancy_ends_at', '>', $startsAt)
            ->where(function (Builder $query) use (
                $evaluatedAt,
                $rescheduleAppointment,
            ): void {
                $query
                    ->whereHas('appointment', function (Builder $query) use ($rescheduleAppointment): void {
                        $query->whereIn('status', [
                            Appointment::STATUS_PENDING,
                            Appointment::STATUS_SCHEDULED,
                            Appointment::STATUS_CONFIRMED,
                        ]);

                        if ($rescheduleAppointment !== null) {
                            $query->where('id', '!=', $rescheduleAppointment->getKey());
                        }
                    })
                    ->orWhereHas('bookingHold', function (Builder $query) use ($evaluatedAt): void {
                        $query
                            ->where('status', BookingHold::STATUS_ACTIVE)
                            ->where('expires_at', '>', $evaluatedAt);
                    });
            })
            ->orderBy('scheduling_resource_id')
            ->orderBy('occupancy_starts_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function snapshotRange(
        BookableService $service,
        mixed $startsAt,
        mixed $endsAt,
    ): array {
        return [
            CarbonImmutable::instance($startsAt)
                ->utc()
                ->subMinutes(max(0, (int) $service->buffer_before_minutes)),
            CarbonImmutable::instance($endsAt)
                ->utc()
                ->addMinutes(max(0, (int) $service->buffer_after_minutes)),
        ];
    }
}