<?php

namespace App\Support\TokenContracts\Contracts;

interface ComputedTokenValueProvider
{
    /**
     * @param array<string, mixed> $context
     */
    public function value(string $sourcePath, array $context): mixed;
}
