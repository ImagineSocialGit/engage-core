<?php

namespace Database\Factories;

use App\Modules\Scheduling\Enums\SchedulingAvailabilityWindowType;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchedulingAvailabilityWindow>
 */
class SchedulingAvailabilityWindowFactory extends Factory
{
    protected $model = SchedulingAvailabilityWindow::class;

    public function definition(): array
    {
        return [
            'bookable_service_id' => BookableService::factory(),
            'scheduling_host_id' => null,
            'window_type' => SchedulingAvailabilityWindowType::Weekly->value,
            'timezone' => 'UTC',
            'weekday' => fake()->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'starts_at' => null,
            'ends_at' => null,
            'capacity' => 1,
            'is_available' => true,
            'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
            'meta' => null,
        ];
    }

    public function weekly(
        int $weekday = 1,
        string $startTime = '09:00:00',
        string $endTime = '17:00:00',
    ): self {
        return $this->state([
            'window_type' => SchedulingAvailabilityWindowType::Weekly->value,
            'weekday' => $weekday,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'starts_at' => null,
            'ends_at' => null,
        ]);
    }

    public function absolute(
        ?DateTimeInterface $startsAt = null,
        ?DateTimeInterface $endsAt = null,
    ): self {
        $startsAt = $startsAt !== null
            ? CarbonImmutable::instance($startsAt)
            : CarbonImmutable::now('UTC')->addDay()->setTime(9, 0);

        $endsAt = $endsAt !== null
            ? CarbonImmutable::instance($endsAt)
            : $startsAt->addHour();

        return $this->state([
            'window_type' => SchedulingAvailabilityWindowType::Absolute->value,
            'weekday' => null,
            'start_time' => null,
            'end_time' => null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
    }

    public function forService(BookableService $service): self
    {
        return $this->state([
            'bookable_service_id' => $service->getKey(),
        ]);
    }

    public function forHost(SchedulingHost $host): self
    {
        return $this->state([
            'scheduling_host_id' => $host->getKey(),
        ]);
    }

    public function serviceWide(BookableService $service): self
    {
        return $this->state([
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => null,
        ]);
    }

    public function hostWide(SchedulingHost $host): self
    {
        return $this->state([
            'bookable_service_id' => null,
            'scheduling_host_id' => $host->getKey(),
        ]);
    }

    public function forServiceAndHost(
        BookableService $service,
        SchedulingHost $host,
    ): self {
        return $this->state([
            'bookable_service_id' => $service->getKey(),
            'scheduling_host_id' => $host->getKey(),
        ]);
    }

    public function unavailable(): self
    {
        return $this->state([
            'is_available' => false,
        ]);
    }
}