<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\BookableService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BookableService>
 */
class BookableServiceFactory extends Factory
{
    protected $model = BookableService::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'key' => Str::slug($name),
            'name' => Str::title($name),
            'description' => fake()->optional()->paragraph(),
            'status' => BookableService::STATUS_ACTIVE,
            'duration_mode' => BookableService::DURATION_MODE_FIXED,
            'duration_minutes' => 60,
            'minimum_duration_minutes' => null,
            'maximum_duration_minutes' => null,
            'slot_interval_minutes' => 15,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
            'minimum_notice_minutes' => 0,
            'booking_horizon_days' => 60,
            'cancellation_notice_minutes' => 0,
            'reschedule_notice_minutes' => 0,
            'timezone' => config('client.timezone', 'UTC'),
            'appointment_format' => null,
            'in_person_arrangement' => null,
            'remote_method' => null,
            'location_type' => BookableService::LOCATION_TYPE_PHONE,
            'location_details' => null,
            'capacity' => 1,
            'requires_confirmation' => false,
            'is_public' => true,
            'sort_order' => 0,
            'source' => 'manual',
            'provider' => null,
            'external_id' => null,
            'external_url' => null,
            'meta' => null,
        ];
    }

    public function inactive(): self
    {
        return $this->state([
            'status' => BookableService::STATUS_INACTIVE,
            'is_public' => false,
        ]);
    }

    public function rangeDuration(
        int $defaultMinutes = 1440,
        int $minimumMinutes = 1440,
        int $maximumMinutes = 10080,
    ): self {
        return $this->state([
            'duration_mode' => BookableService::DURATION_MODE_RANGE,
            'duration_minutes' => $defaultMinutes,
            'minimum_duration_minutes' => $minimumMinutes,
            'maximum_duration_minutes' => $maximumMinutes,
        ]);
    }
}