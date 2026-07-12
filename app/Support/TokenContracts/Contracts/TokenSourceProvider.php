<?php

namespace App\Support\TokenContracts\Contracts;

use App\Support\TokenContracts\Data\TokenSourceDefinition;

interface TokenSourceProvider
{
    /**
     * @return iterable<int, TokenSourceDefinition>
     */
    public function sources(): iterable;
}
