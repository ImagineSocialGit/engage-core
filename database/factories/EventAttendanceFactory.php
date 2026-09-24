<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Events\Enums\EventAttendanceStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAttendance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventAttendance>
 */
class EventAttendanceFactory extends Factory
{
    protected $model = EventAttendance::class;

    public function definition(): array
    {
        return [
            'event_id' => Event::factory(),
            'contact_id' => Contact::factory(),
            'status' => EventAttendanceStatus::Attended->value,
            'observed_at' => now()->startOfSecond(),
            'source_key' => 'operator',
            'source_reference' => null,
        ];
    }

    public function forEvent(Event $event): self
    {
        return $this->state([
            'event_id' => $event->getKey(),
        ]);
    }

    public function forContact(Contact $contact): self
    {
        return $this->state([
            'contact_id' => $contact->getKey(),
        ]);
    }

    public function didNotAttend(): self
    {
        return $this->state([
            'status' => EventAttendanceStatus::DidNotAttend->value,
        ]);
    }
}