<?php

namespace App\Support\ProcessHighway\Contracts;

interface ProcessHighwayContributor
{
    /**
     * Return read-only process descriptions owned by the contributing module.
     *
     * Each process may contain presentation fields in addition to the common
     * identity/grouping fields. Process Highway composes these descriptions;
     * it does not persist or mutate them.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public function processes(): iterable;
}