<?php

namespace App\Modules\Tasks\Contracts;

use Illuminate\Database\Eloquent\Model;

interface TaskAssignmentStrategyResolverContract
{
    public function supports(string $strategy): bool;

    /**
     * @param array<string, mixed> $context
     */
    public function resolve(string $strategy, array $context = []): ?Model;
}
