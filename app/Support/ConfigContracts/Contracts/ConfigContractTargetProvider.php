<?php

namespace App\Support\ConfigContracts\Contracts;

use App\Support\ConfigContracts\Data\ConfigContractTarget;
use App\Support\ConfigContracts\Data\ConfigContractTargetContext;

interface ConfigContractTargetProvider
{
    /**
     * Contract keys this provider is solely responsible for locating.
     *
     * @return array<int, string>
     */
    public function contractKeys(): array;

    /**
     * Return untouched applicable authored values with canonical source paths.
     *
     * @return iterable<int, ConfigContractTarget>
     */
    public function targets(ConfigContractTargetContext $context): iterable;
}
