<?php

namespace App\Support\Reporting\Contracts;

use App\Support\Reporting\Data\ReportingProjectionWindow;

interface ReportingProjectionFactContributor
{
    public function key(): string;

    /**
     * @return iterable<int, \App\Support\Reporting\Data\ReportingProjectionFact>
     */
    public function facts(ReportingProjectionWindow $window): iterable;
}