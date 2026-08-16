<?php

namespace App\Support\Reporting\Contracts;

use App\Support\Reporting\Data\ReportingEventDefinition;

interface ReportingEventDefinitionContributor
{
    /**
     * @return iterable<int, ReportingEventDefinition>
     */
    public function definitions(): iterable;
}