<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use InvalidArgumentException;

final class SchedulingDurationResolver
{
    public function resolveEndsAt(
        BookableService $service,
        CarbonInterface $startsAt,
        ?CarbonInterface $requestedEndsAt = null,
        bool $requireExplicitRange = false,
    ): CarbonImmutable {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $this->assertValidPolicy($service);

        if ($service->usesRangeDuration()) {
            if ($requestedEndsAt === null && $requireExplicitRange) {
                throw new DomainException(
                    'Range-duration services require an explicit end time.',
                );
            }

            $endsAt = $requestedEndsAt !== null
                ? CarbonImmutable::instance($requestedEndsAt)->utc()
                : $startsAt->addMinutes($service->defaultDurationMinutes());

            $this->durationMinutes(
                service: $service,
                startsAt: $startsAt,
                endsAt: $endsAt,
            );

            return $endsAt;
        }

        $endsAt = $startsAt->addMinutes($service->defaultDurationMinutes());

        if ($requestedEndsAt !== null
            && ! CarbonImmutable::instance($requestedEndsAt)->utc()->equalTo($endsAt)
        ) {
            throw new DomainException(
                'Fixed-duration services use their configured duration and do not accept a custom end time.',
            );
        }

        return $endsAt;
    }

    public function durationMinutes(
        BookableService $service,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): int {
        $startsAt = CarbonImmutable::instance($startsAt)->utc();
        $endsAt = CarbonImmutable::instance($endsAt)->utc();
        $this->assertValidPolicy($service);
        $seconds = $endsAt->getTimestamp() - $startsAt->getTimestamp();

        if ($seconds <= 0) {
            throw new DomainException(
                'Scheduling booking intervals require the end time to be after the start time.',
            );
        }

        if ($seconds % 60 !== 0) {
            throw new DomainException(
                'Scheduling booking intervals must use whole-minute duration boundaries.',
            );
        }

        $durationMinutes = intdiv($seconds, 60);

        if (! $service->allowsDurationMinutes($durationMinutes)) {
            if ($service->usesRangeDuration()) {
                throw new DomainException(sprintf(
                    'Range-duration services require a duration between %d and %d minutes.',
                    $service->minimumDurationMinutes(),
                    $service->maximumDurationMinutes(),
                ));
            }

            throw new DomainException(sprintf(
                'Fixed-duration services require exactly %d minutes.',
                $service->defaultDurationMinutes(),
            ));
        }

        return $durationMinutes;
    }

    public function rescheduleDurationMinutes(
        BookableService $service,
        Appointment $appointment,
    ): int {
        if (! $appointment->exists || $appointment->getKey() === null) {
            throw new InvalidArgumentException(
                'Range-aware rescheduling requires a persisted Appointment.',
            );
        }

        if ($service->usesFixedDuration()) {
            return $service->defaultDurationMinutes();
        }

        if ($appointment->starts_at === null || $appointment->ends_at === null) {
            throw new DomainException(
                'Range-duration appointments require persisted start and end times before they can be rescheduled.',
            );
        }

        return $this->durationMinutes(
            service: $service,
            startsAt: $appointment->starts_at,
            endsAt: $appointment->ends_at,
        );
    }
    private function assertValidPolicy(BookableService $service): void
    {
        if (! $service->hasValidDurationPolicy()) {
            throw new DomainException(
                'Range-duration service policy requires minimum <= default <= maximum duration within the 366-day Scheduling limit.',
            );
        }
    }

}