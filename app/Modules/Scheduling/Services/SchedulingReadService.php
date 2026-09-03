<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\BookableServiceResourceRequirement;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use App\Modules\Scheduling\Models\SchedulingHostResource;
use App\Modules\Scheduling\Models\SchedulingResource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class SchedulingReadService
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly SchedulingConfigurationWriter $configurationWriter,
        private readonly SchedulingAvailabilityConfigurationWriter $availabilityConfigurationWriter,
        private readonly SchedulingResourceConfigurationWriter $resourceConfigurationWriter,
        private readonly SchedulingDurationResolver $durations,
    ) {}

    /**
     * @return Collection<int, Appointment>
     */
    public function upcomingAppointments(int $limit = 50): Collection
    {
        return Appointment::query()
            ->with([
                'bookableService',
                'schedulingHost',
                'contact',
                'attendees' => fn ($query) => $query
                    ->orderByRaw("case when role = 'primary' then 0 else 1 end")
                    ->orderBy('id'),
            ])
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(max(1, min(200, $limit)))
            ->get();
    }

    public function appointmentDetail(Appointment $appointment): Appointment
    {
        return Appointment::query()
            ->with([
                'bookableService',
                'schedulingHost',
                'contact',
                'createdBy',
                'attendees' => fn ($query) => $query
                    ->with('contact')
                    ->orderByRaw("case when role = 'primary' then 0 else 1 end")
                    ->orderBy('id'),
                'lifecycleEvents.actor',
                'rescheduledFrom',
                'rescheduledAppointments' => fn ($query) => $query
                    ->orderBy('id'),
            ])
            ->findOrFail($appointment->getKey());
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function contactUpcomingAppointments(
        Contact $contact,
        int $limit = 5,
    ): Collection {
        return $this->contactAppointmentsQuery($contact)
            ->whereIn('status', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_SCHEDULED,
                Appointment::STATUS_CONFIRMED,
            ])
            ->where('starts_at', '>=', CarbonImmutable::now('UTC'))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit($this->boundedContactLimit($limit))
            ->get();
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function contactRecentTerminalAppointments(
        Contact $contact,
        int $limit = 5,
    ): Collection {
        return $this->contactAppointmentsQuery($contact)
            ->whereIn('status', [
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELED,
                Appointment::STATUS_NO_SHOW,
            ])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->limit($this->boundedContactLimit($limit))
            ->get();
    }

    /**
     * @return Collection<int, SchedulingHost>
     */
    public function configurationHosts(): Collection
    {
        $hosts = SchedulingHost::query()
            ->select('scheduling_hosts.*')
            ->selectSub(
                Appointment::query()
                    ->selectRaw('count(*)')
                    ->whereColumn(
                        'appointments.scheduling_host_id',
                        'scheduling_hosts.id',
                    ),
                'appointments_count',
            )
            ->withCount([
                'serviceAssignments',
                'serviceAssignments as active_service_assignments_count' =>
                    fn ($query) => $query->where('is_active', true),
                'availabilityWindows',
            ])
            ->orderByRaw("case status when 'active' then 0 when 'inactive' then 1 else 2 end")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $hosts->each(function (SchedulingHost $host): void {
            $host->setAttribute(
                'crm_editable',
                $this->configurationWriter->hostIsEditable($host),
            );
        });
    }

    /**
     * @return Collection<int, BookableService>
     */
    public function configurationServices(): Collection
    {
        $services = BookableService::query()
            ->with([
                'hostAssignments' => fn ($query) => $query
                    ->with('schedulingHost')
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->withCount([
                'appointments',
                'availabilityWindows',
                'hostAssignments',
                'hostAssignments as active_host_assignments_count' =>
                    fn ($query) => $query->where('is_active', true),
            ])
            ->orderByRaw("case status when 'active' then 0 when 'inactive' then 1 else 2 end")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $services->each(function (BookableService $service): void {
            $service->setAttribute(
                'crm_editable',
                $this->configurationWriter->serviceIsEditable($service),
            );
        });
    }

    public function configurationService(
        BookableService $service,
    ): BookableService {
        $service->load([
            'hostAssignments' => fn ($query) => $query
                ->with('schedulingHost')
                ->orderBy('sort_order')
                ->orderBy('id'),
        ])->loadCount([
            'appointments',
            'availabilityWindows',
            'hostAssignments',
            'hostAssignments as active_host_assignments_count' =>
                fn ($query) => $query->where('is_active', true),
        ]);

        $service->setAttribute(
            'crm_editable',
            $this->configurationWriter->serviceIsEditable($service),
        );

        return $service;
    }

    /**
     * @return Collection<int, SchedulingResource>
     */
    public function configurationResources(): Collection
    {
        $resources = SchedulingResource::withTrashed()
            ->withCount([
                'hostCapacities',
                'hostCapacities as active_host_capacities_count' =>
                    fn ($query) => $query->where('is_active', true),
                'serviceRequirements',
                'serviceRequirements as active_service_requirements_count' =>
                    fn ($query) => $query->where('is_active', true),
                'occupancies',
            ])
            ->orderByRaw('case when deleted_at is null then 0 else 1 end')
            ->orderByRaw("case status when 'active' then 0 when 'inactive' then 1 else 2 end")
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $resources->each(function (SchedulingResource $resource): void {
            $resource->setAttribute(
                'crm_editable',
                $this->resourceConfigurationWriter->resourceIsEditable($resource),
            );
        });
    }

    /**
     * @return Collection<int, SchedulingHost>
     */
    public function configurationResourceHosts(): Collection
    {
        $hosts = $this->configurationHosts();
        $rows = SchedulingHostResource::query()
            ->with('schedulingResource')
            ->orderBy('sort_order')
            ->orderBy('scheduling_resource_id')
            ->orderBy('id')
            ->get()
            ->groupBy('scheduling_host_id');

        return $hosts->each(function (SchedulingHost $host) use ($rows): void {
            $hostRows = new Collection(
                $rows->get($host->getKey(), collect())
                    ->each(function (SchedulingHostResource $row): void {
                        $row->setAttribute(
                            'crm_editable',
                            $this->resourceConfigurationWriter->hostResourceIsEditable($row),
                        );
                    })
                    ->values()
                    ->all(),
            );

            $host->setRelation('resourceCapacities', $hostRows);
        });
    }

    /**
     * @return Collection<int, BookableService>
     */
    public function configurationResourceServices(): Collection
    {
        $services = $this->configurationServices();
        $rows = BookableServiceResourceRequirement::query()
            ->with('schedulingResource')
            ->orderBy('sort_order')
            ->orderBy('scheduling_resource_id')
            ->orderBy('id')
            ->get()
            ->groupBy('bookable_service_id');

        return $services->each(function (BookableService $service) use ($rows): void {
            $serviceRows = new Collection(
                $rows->get($service->getKey(), collect())
                    ->each(function (BookableServiceResourceRequirement $row): void {
                        $row->setAttribute(
                            'crm_editable',
                            $this->resourceConfigurationWriter->serviceRequirementIsEditable($row),
                        );
                    })
                    ->values()
                    ->all(),
            );

            $service->setRelation('resourceRequirements', $serviceRows);
        });
    }

    /**
     * @return SupportCollection<int, array<string, mixed>>
     */
    public function resourceConfigurationEffects(): SupportCollection
    {
        $assignments = BookableServiceHost::query()
            ->with([
                'bookableService',
                'schedulingHost',
            ])
            ->where('is_active', true)
            ->orderBy('bookable_service_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $requirements = BookableServiceResourceRequirement::query()
            ->with('schedulingResource')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('scheduling_resource_id')
            ->orderBy('id')
            ->get()
            ->groupBy('bookable_service_id');
        $capacities = SchedulingHostResource::query()
            ->with('schedulingResource')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('scheduling_resource_id')
            ->orderBy('id')
            ->get()
            ->groupBy('scheduling_host_id')
            ->map(fn ($rows) => $rows->keyBy('scheduling_resource_id'));

        return $assignments
            ->map(function (BookableServiceHost $assignment) use (
                $requirements,
                $capacities,
            ): array {
                $service = $assignment->bookableService;
                $host = $assignment->schedulingHost;
                $serviceRequirements = $requirements->get(
                    $assignment->bookable_service_id,
                    collect(),
                );
                $hostCapacities = $capacities->get(
                    $assignment->scheduling_host_id,
                    collect(),
                );
                $state = 'available';
                $reason = null;
                $ceilings = [];
                $details = [];

                if (! $service instanceof BookableService
                    || $service->trashed()
                    || $service->status !== BookableService::STATUS_ACTIVE
                ) {
                    $state = 'closed';
                    $reason = 'service_inactive';
                } elseif (! $host instanceof SchedulingHost
                    || $host->trashed()
                    || $host->status !== SchedulingHost::STATUS_ACTIVE
                ) {
                    $state = 'closed';
                    $reason = 'host_inactive';
                } elseif ($serviceRequirements->isEmpty()) {
                    $state = 'no_limit';
                } else {
                    foreach ($serviceRequirements as $requirement) {
                        $resource = $requirement->schedulingResource;
                        $capacity = $hostCapacities->get(
                            $requirement->scheduling_resource_id,
                        );
                        $quantity = max(0, (int) $requirement->quantity);
                        $configured = $capacity instanceof SchedulingHostResource
                            ? max(0, (int) $capacity->capacity)
                            : 0;
                        $ceiling = $quantity > 0
                            ? intdiv($configured, $quantity)
                            : 0;

                        $details[] = [
                            'resource_id' => (int) $requirement->scheduling_resource_id,
                            'resource_key' => $resource?->key,
                            'resource_name' => $resource?->name,
                            'resource_status' => $resource?->status,
                            'quantity' => $quantity,
                            'host_capacity' => $configured,
                            'ceiling' => $ceiling,
                        ];

                        if (! $resource instanceof SchedulingResource
                            || $resource->trashed()
                            || $resource->status !== SchedulingResource::STATUS_ACTIVE
                        ) {
                            $state = 'closed';
                            $reason = 'resource_inactive';
                            break;
                        }

                        if (! $capacity instanceof SchedulingHostResource) {
                            $state = 'closed';
                            $reason = 'host_capacity_missing';
                            break;
                        }

                        if ($quantity < 1 || $configured < $quantity) {
                            $state = 'closed';
                            $reason = 'quantity_exceeds_capacity';
                            break;
                        }

                        $ceilings[] = $ceiling;
                    }
                }

                return [
                    'assignment_id' => (int) $assignment->getKey(),
                    'service_id' => (int) $assignment->bookable_service_id,
                    'service_name' => $service?->name,
                    'host_id' => (int) $assignment->scheduling_host_id,
                    'host_name' => $host?->name,
                    'state' => $state,
                    'reason' => $reason,
                    'resource_ceiling' => $state === 'available' && $ceilings !== []
                        ? min($ceilings)
                        : null,
                    'requirements' => $details,
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, SchedulingAvailabilityWindow>
     */
    public function configurationAvailabilityWindows(): Collection
    {
        $windows = SchedulingAvailabilityWindow::withTrashed()
            ->with([
                'bookableService',
                'schedulingHost',
            ])
            ->orderByRaw('case when deleted_at is null then 0 else 1 end')
            ->orderByRaw('case when is_available = 1 then 0 else 1 end')
            ->orderBy('bookable_service_id')
            ->orderBy('scheduling_host_id')
            ->orderBy('window_type')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        return $windows->each(function (SchedulingAvailabilityWindow $window): void {
            $window->setAttribute(
                'crm_editable',
                $this->availabilityConfigurationWriter->windowIsEditable($window),
            );
            $window->setAttribute(
                'crm_scope',
                $this->availabilityConfigurationWriter->scope($window),
            );
        });
    }

    /**
     * @return Collection<int, BookableService>
     */
    public function activeServices(): Collection
    {
        return BookableService::query()
            ->where('status', BookableService::STATUS_ACTIVE)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, SchedulingHost>
     */
    public function eligibleHosts(BookableService $service): Collection
    {
        $assignments = BookableServiceHost::query()
            ->with('schedulingHost')
            ->where('bookable_service_id', $service->getKey())
            ->where('is_active', true)
            ->whereHas('schedulingHost', function ($query): void {
                $query->where('status', SchedulingHost::STATUS_ACTIVE);
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return new Collection(
            $assignments
                ->pluck('schedulingHost')
                ->filter(fn (mixed $host): bool => $host instanceof SchedulingHost)
                ->values()
                ->all(),
        );
    }

    public function serviceRequiresHost(BookableService $service): bool
    {
        return BookableServiceHost::query()
            ->where('bookable_service_id', $service->getKey())
            ->exists();
    }

    /**
     * @return array<int, BookableSlot>
     */
    public function availabilityForDate(
        BookableService $service,
        CarbonInterface $date,
        ?SchedulingHost $host = null,
    ): array {
        return $this->dateAvailability(
            service: $service,
            date: $date,
            host: $host,
        );
    }

    /**
     * @return array<int, BookableSlot>
     */
    public function rescheduleAvailabilityForDate(
        Appointment $appointment,
        CarbonInterface $date,
        ?SchedulingHost $host = null,
    ): array {
        $appointment = Appointment::query()
            ->with('bookableService')
            ->findOrFail($appointment->getKey());
        $service = $appointment->bookableService;

        if (! $service instanceof BookableService
            || $service->status !== BookableService::STATUS_ACTIVE
        ) {
            return [];
        }

        $candidateDurationMinutes = $this->rescheduleDurationMinutes(
            service: $service,
            appointment: $appointment,
        );

        if ($candidateDurationMinutes === null) {
            return [];
        }

        return array_values(array_filter(
            $this->dateAvailability(
                service: $service,
                date: $date,
                host: $host,
                rescheduleAppointment: $appointment,
                candidateDurationMinutes: $candidateDurationMinutes,
            ),
            fn (BookableSlot $slot): bool => ! (
                $appointment->starts_at?->equalTo($slot->startsAt)
                && $this->sameHost(
                    $appointment->scheduling_host_id,
                    $slot->schedulingHostId,
                )
            ),
        ));
    }

    /**
     * @return array<int, BookableSlot>
     */
    public function rescheduleSuggestions(
        Appointment $appointment,
        ?SchedulingHost $host = null,
        ?CarbonInterface $evaluatedAt = null,
        ?int $limit = null,
    ): array {
        $appointment = Appointment::query()
            ->with('bookableService')
            ->findOrFail($appointment->getKey());
        $service = $appointment->bookableService;

        if (! $service instanceof BookableService
            || $service->status !== BookableService::STATUS_ACTIVE
            || ($this->serviceRequiresHost($service) && $host === null)
        ) {
            return [];
        }

        $candidateDurationMinutes = $this->rescheduleDurationMinutes(
            service: $service,
            appointment: $appointment,
        );

        if ($candidateDurationMinutes === null) {
            return [];
        }

        $evaluatedAt = $evaluatedAt !== null
            ? CarbonImmutable::instance($evaluatedAt)->utc()
            : CarbonImmutable::now('UTC');
        $lookaheadDays = max(1, min(60, (int) config(
            'scheduling.reschedule_suggestions.lookahead_days',
            14,
        )));
        $limit = max(1, min(20, $limit ?? (int) config(
            'scheduling.reschedule_suggestions.limit',
            6,
        )));
        $anchor = $appointment->starts_at !== null
            ? CarbonImmutable::instance($appointment->starts_at)->utc()
            : $evaluatedAt;
        $halfWindowDays = intdiv($lookaheadDays, 2);
        $startsAt = $anchor->subDays($halfWindowDays);

        if ($startsAt->lessThan($evaluatedAt)) {
            $startsAt = $evaluatedAt;
        }

        $horizonEndsAt = $evaluatedAt->addDays(
            max(0, (int) $service->booking_horizon_days),
        );
        $endsAt = $anchor->addDays($lookaheadDays);

        if ($endsAt->greaterThan($horizonEndsAt)) {
            $endsAt = $horizonEndsAt;
        }

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            return [];
        }

        $search = new AvailabilitySearch(
            service: $service,
            startsAt: $startsAt,
            endsAt: $endsAt,
            host: $host,
            displayTimezone: $this->validTimezone($service->timezone),
            evaluatedAt: $evaluatedAt,
            rescheduleAppointment: $appointment,
            location: $appointment->locationSnapshot(),
            candidateDurationMinutes: $candidateDurationMinutes,
        );
        $slots = array_values(array_filter(
            $this->findAvailability->handle($search),
            fn (BookableSlot $slot): bool => ! (
                $appointment->starts_at?->equalTo($slot->startsAt)
                && $this->sameHost(
                    $appointment->scheduling_host_id,
                    $slot->schedulingHostId,
                )
            ),
        ));

        usort($slots, function (BookableSlot $left, BookableSlot $right) use ($anchor): int {
            $leftTravel = $left->totalTravelMinutes() ?? 0;
            $rightTravel = $right->totalTravelMinutes() ?? 0;
            $leftDistance = abs($left->startsAt->getTimestamp() - $anchor->getTimestamp());
            $rightDistance = abs($right->startsAt->getTimestamp() - $anchor->getTimestamp());

            return $leftTravel <=> $rightTravel
                ?: $leftDistance <=> $rightDistance
                ?: $left->startsAt->getTimestamp() <=> $right->startsAt->getTimestamp();
        });

        return array_slice($slots, 0, $limit);
    }

    /**
     * @return array<int, BookableSlot>
     */
    private function dateAvailability(
        BookableService $service,
        CarbonInterface $date,
        ?SchedulingHost $host = null,
        ?Appointment $rescheduleAppointment = null,
        ?int $candidateDurationMinutes = null,
    ): array {
        if ($this->serviceRequiresHost($service) && $host === null) {
            return [];
        }

        $timezone = $this->validTimezone($service->timezone);
        $localStart = CarbonImmutable::instance($date)
            ->setTimezone($timezone)
            ->startOfDay();
        $localEnd = $localStart->addDay();
        $rangeDurationMinutes = $service->usesRangeDuration()
            ? ($candidateDurationMinutes ?? $service->defaultDurationMinutes())
            : null;
        $searchEnd = $rangeDurationMinutes !== null
            ? $localEnd->addMinutes($rangeDurationMinutes)
            : $localEnd;
        $slots = $this->findAvailability->handle(new AvailabilitySearch(
            service: $service,
            startsAt: $localStart->utc(),
            endsAt: $searchEnd->utc(),
            host: $host,
            displayTimezone: $timezone,
            evaluatedAt: CarbonImmutable::now('UTC'),
            rescheduleAppointment: $rescheduleAppointment,
            location: $rescheduleAppointment?->locationSnapshot(),
            candidateDurationMinutes: $candidateDurationMinutes,
        ));

        if (! $service->usesRangeDuration()) {
            return $slots;
        }

        $dayStartsAt = $localStart->utc();
        $dayEndsAt = $localEnd->utc();

        return array_values(array_filter(
            $slots,
            static fn (BookableSlot $slot): bool =>
                $slot->startsAt->greaterThanOrEqualTo($dayStartsAt)
                && $slot->startsAt->lessThan($dayEndsAt),
        ));
    }

    private function rescheduleDurationMinutes(
        BookableService $service,
        Appointment $appointment,
    ): ?int {
        try {
            return $this->durations->rescheduleDurationMinutes(
                service: $service,
                appointment: $appointment,
            );
        } catch (DomainException) {
            return null;
        }
    }

    /**
     * @return Builder<Appointment>
     */
    private function contactAppointmentsQuery(Contact $contact): Builder
    {
        return Appointment::query()
            ->with([
                'bookableService',
                'schedulingHost',
                'attendees' => fn ($query) => $query
                    ->orderByRaw("case when role = 'primary' then 0 else 1 end")
                    ->orderBy('id'),
                'rescheduledFrom',
                'rescheduledAppointments' => fn ($query) => $query
                    ->orderBy('id'),
            ])
            ->where('contact_id', $contact->getKey());
    }

    private function boundedContactLimit(int $limit): int
    {
        return max(1, min(20, $limit));
    }

    private function sameHost(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return (int) $left === (int) $right;
    }

    private function validTimezone(?string $timezone): string
    {
        return is_string($timezone)
            && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : 'UTC';
    }
}