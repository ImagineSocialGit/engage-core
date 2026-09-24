<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventStakeholder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventStakeholder>
 */
class EventStakeholderFactory extends Factory
{
    protected $model = EventStakeholder::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'role_key' => 'venue_contact',
            'name' => fake()->name(),
            'organization' => fake()->optional()->company(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forEvent(Event $event): self
    {
        return $this->state([
            'event_id' => $event->getKey(),
        ]);
    }
}