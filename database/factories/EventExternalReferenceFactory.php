<?php

namespace Database\Factories;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventExternalReference;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EventExternalReference>
 */
class EventExternalReferenceFactory extends Factory
{
    protected $model = EventExternalReference::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'provider_key' => 'website',
            'reference_type' => 'event_page',
            'external_id' => (string) Str::uuid(),
            'url' => fake()->url(),
            'label' => 'Event page',
        ];
    }

    public function forEvent(Event $event): self
    {
        return $this->state([
            'event_id' => $event->getKey(),
        ]);
    }

    public function urlOnly(): self
    {
        return $this->state([
            'external_id' => null,
        ]);
    }
}