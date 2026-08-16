<?php

namespace App\Support\Reporting\Contracts;

use App\Support\Reporting\Data\ReportingObservationData;
use App\Support\Reporting\Data\ReportingObservationResult;

interface ReportingObservationRecorder
{
    public function record(ReportingObservationData $observation): ReportingObservationResult;
}