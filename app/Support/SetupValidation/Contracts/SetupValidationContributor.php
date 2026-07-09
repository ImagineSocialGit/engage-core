<?php

namespace App\Support\SetupValidation\Contracts;

use App\Support\SetupValidation\Data\SetupValidationFinding;

interface SetupValidationContributor
{
    /**
     * @return iterable<int, SetupValidationFinding>
     */
    public function findings(): iterable;
}
