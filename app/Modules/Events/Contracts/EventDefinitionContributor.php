<?php

namespace App\Modules\Events\Contracts;

use App\Modules\Events\Data\EventDefinitionContribution;

interface EventDefinitionContributor
{
    /**
     * @return iterable<int, EventDefinitionContribution>
     */
    public function definitions(): iterable;
}