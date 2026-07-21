<?php

namespace Database\Factories;

use App\Modules\Core\Models\Contact;
use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $startsAt = now()->addDays(fake()->numberBetween(1, 14))->startOfHour();

        return [
            'bookable_service_id' => BookableService::factory(),
            'scheduling_host_id' => null,
            'contact_id' => Contact::factory(),
            'location_reference_type' => null,
            'location_reference_id' => null,
            'primary_attendee_type' => null,
            'primary_attendee_id' => null,
            'source_context_type' => null,
            'source_context_id' => null,
            'rescheduled_from_id' => null,
            'status' => Appointment::STATUS_SCHEDULED,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'location_type' => null,
            'location_details' => null,
            'timezone' => 'UTC',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHour(),
            'confirmed_at' => null,
            'completed_at' => null,
            'no_show_at' => null,
            'canceled_at' => null,
            'cancellation_reason' => null,
            'source' => 'manual',
            'created_by_type' => null,
            'created_by_id' => null,
            'meta' => null,
        ];
    }

    public function forSchedulingHost(?SchedulingHost $host = null): self
    {
        return $this->state([
            'scheduling_host_id' => $host?->getKey()
                ?? SchedulingHost::factory(),
        ]);
    }

    public function forLocationReference(Model $locationReference): self
    {
        return $this->state([
            'location_reference_type' => $locationReference->getMorphClass(),
            'location_reference_id' => $locationReference->getKey(),
        ]);
    }

    public function forPrimaryAttendee(Model $attendee): self
    {
        return $this->state([
            'primary_attendee_type' => $attendee->getMorphClass(),
            'primary_attendee_id' => $attendee->getKey(),
        ]);
    }

    public function fromSourceContext(Model $sourceContext): self
    {
        return $this->state([
            'source_context_type' => $sourceContext->getMorphClass(),
            'source_context_id' => $sourceContext->getKey(),
        ]);
    }

    public function createdBy(Model $creator): self
    {
        return $this->state([
            'created_by_type' => $creator->getMorphClass(),
            'created_by_id' => $creator->getKey(),
        ]);
    }

    public function confirmed(): self
    {
        return $this->state([
            'status' => Appointment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function completed(): self
    {
        return $this->state([
            'status' => Appointment::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function canceled(?string $reason = null): self
    {
        return $this->state([
            'status' => Appointment::STATUS_CANCELED,
            'canceled_at' => now(),
            'cancellation_reason' => $reason,
        ]);
    }
}