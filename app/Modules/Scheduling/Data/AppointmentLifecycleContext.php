<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\AppointmentAttendee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final readonly class AppointmentLifecycleContext
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?Model $actor = null,
        public ?AppointmentAttendee $attendee = null,
        string $source = 'system',
        ?string $reason = null,
        ?CarbonInterface $occurredAt = null,
        public bool $force = false,
        public array $context = [],
    ) {
        $this->assertPersisted($actor, 'actor');
        $this->assertPersisted($attendee, 'appointment attendee');

        $this->source = $this->requiredString($source, 'source', 100);
        $this->reason = $this->nullableString($reason, 'reason', 10000);
        $this->occurredAt = $occurredAt !== null
            ? CarbonImmutable::instance($occurredAt)->utc()
            : CarbonImmutable::now('UTC');
    }

    public string $source;
    public ?string $reason;
    public CarbonImmutable $occurredAt;

    private function assertPersisted(?Model $model, string $label): void
    {
        if ($model === null) {
            return;
        }

        if (! $model->exists || $model->getKey() === null) {
            throw new InvalidArgumentException(
                "Appointment lifecycle {$label} must be persisted.",
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
                "Appointment lifecycle {$label} cannot be empty.",
            );
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Appointment lifecycle {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }

    private function nullableString(
        ?string $value,
        string $label,
        int $maximumLength,
    ): ?string {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (mb_strlen($value) > $maximumLength) {
            throw new InvalidArgumentException(
                "Appointment lifecycle {$label} cannot exceed {$maximumLength} characters.",
            );
        }

        return $value;
    }
}