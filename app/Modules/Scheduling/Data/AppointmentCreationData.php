<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class AppointmentCreationData
{
    public CarbonImmutable $startsAt;
    public string $idempotencyKey;
    public AppointmentLifecycleContext $lifecycle;

    public function __construct(
        public BookableService $service,
        CarbonInterface $startsAt,
        public AppointmentBookingData $booking,
        string $idempotencyKey,
        public ?SchedulingHost $host = null,
        ?AppointmentLifecycleContext $lifecycle = null,
    ) {
        $this->assertPersisted($service, 'bookable service');
        $this->assertPersisted($host, 'scheduling host');

        $this->startsAt = CarbonImmutable::instance($startsAt)->utc();
        $this->idempotencyKey = $this->requiredString(
            value: $idempotencyKey,
            label: 'idempotency key',
            maximumLength: 191,
        );
        $this->lifecycle = $lifecycle ?? new AppointmentLifecycleContext(
            actor: $booking->createdBy,
            source: $booking->source,
            reason: 'direct_appointment_created',
        );
    }

    private function assertPersisted(?Model $model, string $label): void
    {
        if ($model === null) {
            return;
        }

        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException(
                "Appointment creation {$label} must be persisted.",
            );
        }
    }

    private function requiredString(
        string $value,
        string $label,
        int $maximumLength,
    ): string {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException(
                "Appointment creation {$label} cannot be empty.",
            );
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Appointment creation {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}