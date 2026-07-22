<?php

namespace App\Modules\Scheduling\Data;

use InvalidArgumentException;

final readonly class AppointmentRescheduleData
{
    public string $holdId;
    public AppointmentLifecycleContext $lifecycle;

    public function __construct(
        string $holdId,
        ?AppointmentLifecycleContext $lifecycle = null,
        public bool $preserveConfirmation = false,
    ) {
        $holdId = trim($holdId);

        if ($holdId === '') {
            throw new InvalidArgumentException(
                'Appointment rescheduling requires a non-empty booking hold ID.',
            );
        }

        if (mb_strlen($holdId) > 36) {
            throw new InvalidArgumentException(
                'The appointment reschedule booking hold ID cannot exceed 36 characters.',
            );
        }

        $this->holdId = $holdId;
        $this->lifecycle = $lifecycle ?? new AppointmentLifecycleContext(
            source: 'system',
            reason: 'rescheduled',
        );
    }
}