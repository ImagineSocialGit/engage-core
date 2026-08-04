<?php

namespace Database\Factories;

use App\Modules\Events\Enums\EventAttendanceMode;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $startsAt = now()->addWeeks(2)->startOfMinute();

        return [
            'type_key' => 'concert',
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => EventStatus::Draft->value,
            'attendance_mode' => EventAttendanceMode::Physical->value,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(2),
            'timezone' => 'America/Chicago',
            'announcement_at' => $startsAt->copy()->subWeek(),
            'venue_name' => fake()->company(),
            'address_line_1' => fake()->streetAddress(),
            'address_line_2' => null,
            'city' => fake()->city(),
            'region' => fake()->stateAbbr(),
            'postal_code' => fake()->postcode(),
            'country' => 'US',
            'primary_external_reference_id' => null,
        ];
    }

    public function upcoming(): self
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Upcoming->value,
        ]);
    }

    public function virtual(): self
    {
        return $this->state(fn (): array => [
            'attendance_mode' => EventAttendanceMode::Virtual->value,
            'venue_name' => null,
            'address_line_1' => null,
            'address_line_2' => null,
            'city' => null,
            'region' => null,
            'postal_code' => null,
            'country' => null,
        ]);
    }
}