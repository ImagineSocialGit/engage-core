<?php

namespace App\Modules\Core\Contracts\Contacts;

interface ContactFilterCriterionPresentation
{
    /**
     * Optional UI metadata for generic Contact filtering surfaces.
     *
     * Runtime filtering semantics remain owned by ContactFilterCriterion.
     * Consumers that do not care about presentation may ignore this contract.
     *
     * @return array<string, mixed>
     */
    public function presentation(): array;
}