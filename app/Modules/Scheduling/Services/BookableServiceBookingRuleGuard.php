<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Data\BookingSubjectEligibilityContext;
use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

final class BookableServiceBookingRuleGuard
{
    public function __construct(
        private readonly BookingSubjectProviderRegistry $subjects,
        private readonly BookingSubjectEligibilityProviderRegistry $eligibility,
        private readonly BookableServicePrerequisiteEvaluator $prerequisites,
    ) {}

    public function assertSatisfied(
        BookableService $service,
        AppointmentBookingData $booking,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        ?CarbonInterface $evaluatedAt = null,
    ): ?Model {
        $provider = $this->subjects->provider($service->bookingSubjectKey());
        $subject = $booking->primaryAttendee();
        $hasPrerequisites = $service->prerequisites()
            ->where('is_active', true)
            ->exists();

        if (! $subject instanceof Model) {
            if ($hasPrerequisites) {
                throw new DomainException(
                    'This Appointment Type requires a saved booking subject before its prerequisites can be verified.',
                );
            }

            if ($service->bookingSubjectPolicy() !== []) {
                throw new DomainException(
                    'This Appointment Type requires a saved booking subject before its eligibility can be verified.',
                );
            }

            if ($provider->allowsSnapshotOnly()) {
                return null;
            }

            throw new DomainException(
                'This Appointment Type requires a saved booking subject.',
            );
        }

        if (! $provider->accepts($subject)) {
            throw new DomainException(
                'The selected booking subject is not valid for this Appointment Type.',
            );
        }

        $policy = $service->bookingSubjectPolicy();

        if ($policy !== []) {
            if (! $startsAt instanceof CarbonInterface || ! $endsAt instanceof CarbonInterface) {
                throw new DomainException(
                    'This Appointment Type requires a scheduled appointment window before booking-subject eligibility can be verified.',
                );
            }

            try {
                $this->eligibility->assertEligible(
                    new BookingSubjectEligibilityContext(
                        service: $service,
                        subject: $subject,
                        startsAt: $startsAt,
                        endsAt: $endsAt,
                        evaluatedAt: $evaluatedAt ?? CarbonImmutable::now('UTC'),
                        policy: $policy,
                    ),
                );
            } catch (InvalidArgumentException|LogicException $exception) {
                throw new DomainException(
                    'This Appointment Type has an invalid or unavailable booking-subject eligibility policy.',
                    previous: $exception,
                );
            }
        }

        if (! $hasPrerequisites) {
            return $subject;
        }

        $evaluation = $this->prerequisites->evaluate(
            service: $service,
            subject: $subject,
            evaluatedAt: $evaluatedAt,
        );

        if (! $evaluation->satisfied()) {
            throw new DomainException(
                'The selected booking subject has not completed all required prerequisite appointments.',
            );
        }

        return $subject;
    }
}