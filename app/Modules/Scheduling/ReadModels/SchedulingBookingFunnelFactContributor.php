<?php

namespace App\Modules\Scheduling\ReadModels;

use App\Modules\Scheduling\Models\Appointment;
use App\Support\Reporting\Contracts\ReportingProjectionFactContributor;
use App\Support\Reporting\Data\ReportingProjectionFact;
use App\Support\Reporting\Data\ReportingProjectionWindow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class SchedulingBookingFunnelFactContributor implements ReportingProjectionFactContributor
{
    public const CONTRIBUTOR_KEY = 'scheduling_booking_funnel';

    public const FACT_KEY = 'scheduling.public_booking';

    public const FACT_VERSION = 1;

    public function key(): string
    {
        return self::CONTRIBUTOR_KEY;
    }

    /** @return iterable<int, ReportingProjectionFact> */
    public function facts(ReportingProjectionWindow $window): iterable
    {
        $appointments = Appointment::query()
            ->with([
                'bookableService' => fn ($query) => $query->withTrashed(),
            ])
            ->where('source', 'public_booking')
            ->whereBetween('created_at', [$window->startsAt, $window->endsAt])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($appointments as $appointment) {
            $service = $appointment->bookableService;
            $attemptId = data_get(
                is_array($appointment->meta) ? $appointment->meta : [],
                'reporting.public_submission_attempt_id',
            );

            if (! is_string($attemptId) || ! Str::isUuid($attemptId)) {
                $attemptId = null;
            }

            yield new ReportingProjectionFact(
                key: self::FACT_KEY,
                version: self::FACT_VERSION,
                occurredAt: CarbonImmutable::instance($appointment->created_at)->utc(),
                subjectType: $appointment->getMorphClass(),
                subjectId: (string) $appointment->getKey(),
                correlationId: $attemptId,
                dimensions: [
                    'service_id' => $service?->getKey() !== null
                        ? (string) $service->getKey()
                        : null,
                    'service_key' => is_string($service?->key)
                        ? mb_substr(trim($service->key), 0, 100)
                        : null,
                    'location_type' => is_string($appointment->location_type)
                        ? mb_substr(trim($appointment->location_type), 0, 80)
                        : null,
                    'source' => 'public_booking',
                ],
                values: [
                    'appointment_status' => mb_substr((string) $appointment->status, 0, 80),
                    'requires_confirmation' => $service !== null
                        && (bool) $service->requires_confirmation,
                ],
            );
        }
    }
}