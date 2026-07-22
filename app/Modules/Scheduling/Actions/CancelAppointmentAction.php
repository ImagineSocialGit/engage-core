<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Scheduling\Data\AppointmentLifecycleContext;
use App\Modules\Scheduling\Models\Appointment;

class CancelAppointmentAction
{
    public function __construct(
        private readonly TransitionAppointmentStatusAction $transition,
    ) {}

    public function handle(
        Appointment $appointment,
        ?AppointmentLifecycleContext $context = null,
    ): Appointment {
        return $this->transition->handle(
            appointment: $appointment,
            toStatus: Appointment::STATUS_CANCELED,
            context: $context,
        );
    }
}