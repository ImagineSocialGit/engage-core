<?php

namespace App\Support\TokenContracts\Contracts;

use App\Support\TokenContracts\Data\TokenContextDefinition;

interface TokenContextProvider
{
    /**
     * @return iterable<int, TokenContextDefinition>
     */
    public function contexts(): iterable;
}
