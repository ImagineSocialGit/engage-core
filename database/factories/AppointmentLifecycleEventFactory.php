<?php

namespace Database\Factories;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\AppointmentLifecycleEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @extends Factory<AppointmentLifecycleEvent>
 */
class AppointmentLifecycleEventFactory extends Factory
{
    protected $model = AppointmentLifecycleEvent::class;

    public function definition(): array
    {
        return [
            'appointment_id' => Appointment::factory(),
            'event_id' => (string) Str::uuid(),
            'event_key' => AppointmentLifecycleEvent::EVENT_SCHEDULED,
            'from_status' => null,
            'to_status' => Appointment::STATUS_SCHEDULED,
            'actor_type' => null,
            'actor_id' => null,
            'source' => 'system',
            'reason' => null,
            'context' => null,
            'occurred_at' => now(),
        ];
    }

    public function actedBy(Model $actor): self
    {
        return $this->state([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
        ]);
    }

    public function confirmed(): self
    {
        return $this->state([
            'event_key' => AppointmentLifecycleEvent::EVENT_CONFIRMED,
            'from_status' => Appointment::STATUS_SCHEDULED,
            'to_status' => Appointment::STATUS_CONFIRMED,
        ]);
    }

    public function canceled(?string $reason = null): self
    {
        return $this->state([
            'event_key' => AppointmentLifecycleEvent::EVENT_CANCELED,
            'from_status' => Appointment::STATUS_SCHEDULED,
            'to_status' => Appointment::STATUS_CANCELED,
            'reason' => $reason,
        ]);
    }
}