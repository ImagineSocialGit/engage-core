<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Actions\FindBookableAvailabilityAction;
use App\Modules\Scheduling\Data\AvailabilitySearch;
use App\Modules\Scheduling\Data\BookableSlot;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\BookableServiceHost;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

class SchedulingReadService
{
    public function __construct(
        private readonly FindBookableAvailabilityAction $findAvailability,
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
        ));
    }

    private function validTimezone(?string $timezone): string
    {
        return is_string($timezone)
            && in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : 'UTC';
    }
}