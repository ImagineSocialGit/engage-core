<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Data\AppointmentBookingData;
use App\Modules\Scheduling\Models\BookableService;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class BookableServiceBookingRuleGuard
{
    public function __construct(
        private readonly BookingSubjectProviderRegistry $subjects,
        private readonly BookableServicePrerequisiteEvaluator $prerequisites,
    ) {}

    public function assertSatisfied(
        BookableService $service,
        AppointmentBookingData $booking,
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