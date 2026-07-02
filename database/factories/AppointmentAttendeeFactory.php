<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentAttendee;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AppointmentAttendee>
 */
class AppointmentAttendeeFactory extends Factory
{
    protected $model = AppointmentAttendee::class;

    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'attendee_type' => null,
            'attendee_id' => null,
            'contact_id' => null,
            'name' => fake()->name(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('##########'),
            'role' => 'attendee',
            'status' => AppointmentAttendee::STATUS_INVITED,
            'responded_at' => null,
            'joined_at' => null,
            'canceled_at' => null,
            'meta' => null,
        ];
    }

    public function forAttendee(Model $attendee): self
    {
        return $this->state([
            'attendee_type' => $attendee->getMorphClass(),
            'attendee_id' => $attendee->getKey(),
        ]);
    }

    public function forContact(?Contact $contact = null): self
    {
        $contact ??= Contact::factory()->create();

        return $this->state([
            'attendee_type' => $contact->getMorphClass(),
            'attendee_id' => $contact->getKey(),
            'contact_id' => $contact->id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
        ]);
    }

    public function accepted(): self
    {
        return $this->state([
            'status' => AppointmentAttendee::STATUS_ACCEPTED,
            'responded_at' => now(),
        ]);
    }
}
