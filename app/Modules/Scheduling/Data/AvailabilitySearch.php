<?php

namespace App\Modules\Scheduling\Data;

use App\Modules\Scheduling\Models\Appointment;
use App\Modules\Scheduling\Models\BookableService;
use App\Modules\Scheduling\Models\SchedulingHost;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class AvailabilitySearch
{
    public const MAX_REQUEST_RANGE_DAYS = 366;

    public BookableService $service;
    public ?SchedulingHost $host;
    public ?Appointment $rescheduleAppointment;
    public CarbonImmutable $requestedStartsAt;
    public CarbonImmutable $requestedEndsAt;
    public CarbonImmutable $effectiveStartsAt;
    public CarbonImmutable $effectiveEndsAt;
    public CarbonImmutable $evaluatedAt;
    public string $displayTimezone;

    public function __construct(
        BookableService $service,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        ?SchedulingHost $host = null,
        ?string $displayTimezone = null,
        ?CarbonInterface $evaluatedAt = null,
        ?Appointment $rescheduleAppointment = null,
    ) {
        $requestedStartsAt = CarbonImmutable::instance($startsAt)->utc();
        $requestedEndsAt = CarbonImmutable::instance($endsAt)->utc();
        $evaluatedAt = $evaluatedAt !== null
            ? CarbonImmutable::instance($evaluatedAt)->utc()
            : CarbonImmutable::now('UTC');

        if ($requestedStartsAt->greaterThanOrEqualTo($requestedEndsAt)) {
            throw new InvalidArgumentException(
                'Availability searches require startsAt before endsAt.',
            );
        }

        if ($requestedStartsAt->diffInSeconds($requestedEndsAt) > self::MAX_REQUEST_RANGE_DAYS * 86400) {
            throw new InvalidArgumentException(sprintf(
                'Availability searches cannot exceed %d days.',
                self::MAX_REQUEST_RANGE_DAYS,
            ));
        }

        $this->assertRescheduleAppointment(
            service: $service,
            appointment: $rescheduleAppointment,
        );

        $displayTimezone = $this->validatedTimezone(
            $displayTimezone ?? $service->timezone ?? 'UTC',
        );

        $noticeBoundary = $evaluatedAt->addMinutes(
            max(0, (int) $service->minimum_notice_minutes),
        );

        $horizonBoundary = $evaluatedAt->addDays(
            max(0, (int) $service->booking_horizon_days),
        );

        $this->service = $service;
        $this->host = $host;
        $this->rescheduleAppointment = $rescheduleAppointment;
        $this->requestedStartsAt = $requestedStartsAt;
        $this->requestedEndsAt = $requestedEndsAt;
        $this->effectiveStartsAt = $requestedStartsAt->greaterThan($noticeBoundary)
            ? $requestedStartsAt
            : $noticeBoundary;
        $this->effectiveEndsAt = $requestedEndsAt->lessThan($horizonBoundary)
            ? $requestedEndsAt
            : $horizonBoundary;
        $this->evaluatedAt = $evaluatedAt;
        $this->displayTimezone = $displayTimezone;
    }

    public function hasEffectiveRange(): bool
    {
        return $this->effectiveStartsAt->lessThan($this->effectiveEndsAt);
    }

    public function isRescheduleSearch(): bool
    {
        return $this->rescheduleAppointment !== null;
    }

    public function serviceTimezone(): string
    {
        return $this->validatedTimezone($this->service->timezone ?? 'UTC');
    }

    public function durationMinutes(): int
    {
        return max(1, (int) $this->service->duration_minutes);
    }

    public function slotIntervalMinutes(): int
    {
        return max(1, (int) $this->service->slot_interval_minutes);
    }

    private function assertRescheduleAppointment(
        BookableService $service,
        ?Appointment $appointment,
    ): void {
        if ($appointment === null) {
            return;
        }

        if (! $appointment->exists || $appointment->getKey() === null) {
            throw new InvalidArgumentException(
                'Reschedule availability requires a persisted Appointment.',
            );
        }

        if ($service->getKey() === null
            || (int) $appointment->bookable_service_id !== (int) $service->getKey()
        ) {
            throw new InvalidArgumentException(
                'The reschedule Appointment must belong to the searched service.',
            );
        }
    }

    private function validatedTimezone(string $timezone): string
    {
        $timezone = trim($timezone);

        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException(
                "Scheduling timezone [{$timezone}] is invalid.",
            );
        }

        return $timezone;
    }
}