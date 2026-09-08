<?php

namespace App\Modules\Core\Access\Contracts;

interface AccessCapabilityContributor
{
    /**
     * @return array<int, \App\Modules\Core\Access\Data\AccessCapabilityDefinition>
     */
    public function capabilities(): array;
}