<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SchedulingReadService
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
        private readonly SchedulingConfigurationWriter $configurationWriter,
        private readonly SchedulingAvailabilityConfigurationWriter $availabilityConfigurationWriter,
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

        return array_values(array_filter(
            $this->dateAvailability(
                service: $service,
                date: $date,
                host: $host,
                rescheduleAppointment: $appointment,
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
    private function dateAvailability(
        BookableService $service,
        CarbonInterface $date,
        ?SchedulingHost $host = null,
        ?Appointment $rescheduleAppointment = null,
    ): array {
        if ($this->serviceRequiresHost($service) && $host === null) {
            return [];
        }

        $timezone = $this->validTimezone($service->timezone);
        $localStart = CarbonImmutable::instance($date)
            ->setTimezone($timezone)
            ->startOfDay();
        $localEnd = $localStart->addDay();

        return $this->findAvailability->handle(new AvailabilitySearch(
            service: $service,
            startsAt: $localStart->utc(),
            endsAt: $localEnd->utc(),
            host: $host,
            displayTimezone: $timezone,
            evaluatedAt: CarbonImmutable::now('UTC'),
            rescheduleAppointment: $rescheduleAppointment,
        ));
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