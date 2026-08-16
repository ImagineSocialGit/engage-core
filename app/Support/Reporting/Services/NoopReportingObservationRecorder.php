<?php

namespace App\Support\Reporting\Services;

use App\Support\Reporting\Contracts\ReportingObservationRecorder;
use App\Support\Reporting\Data\ReportingObservationData;
use App\Support\Reporting\Data\ReportingObservationResult;

final class NoopReportingObservationRecorder implements ReportingObservationRecorder
{
    public function record(ReportingObservationData $observation): ReportingObservationResult
    {
        return ReportingObservationResult::disabled($observation->eventId);
    }
}