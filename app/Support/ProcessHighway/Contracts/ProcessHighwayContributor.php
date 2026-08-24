<?php

namespace App\Support\ProcessHighway\Contracts;

use App\Support\ProcessHighway\Data\ProcessHighwayContribution;

interface ProcessHighwayContributor
{
    /**
     * Return module-owned business graph fragments.
     *
     * Contributors describe their own persisted definitions and may reference
     * stable semantic nodes owned by other modules. Process Highway composes
     * those fragments; it never queries or mutates a contributor's source of
     * truth.
     *
     * @return iterable<int, ProcessHighwayContribution>
     */
    public function contributions(): iterable;
}