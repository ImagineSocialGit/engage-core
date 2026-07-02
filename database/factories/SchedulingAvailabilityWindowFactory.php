<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingAvailabilityWindow;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

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
            'owner_type' => null,
            'owner_id' => null,
            'timezone' => 'UTC',
            'weekday' => fake()->numberBetween(0, 6),
            'starts_at' => null,
            'ends_at' => null,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
            'capacity' => 1,
            'rrule' => null,
            'is_available' => true,
            'source' => SchedulingAvailabilityWindow::SOURCE_MANUAL,
            'provider' => null,
            'external_id' => null,
            'meta' => null,
        ];
    }

    public function forOwner(Model $owner): self
    {
        return $this->state([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
        ]);
    }

    public function unavailable(): self
    {
        return $this->state([
            'is_available' => false,
        ]);
    }
}
