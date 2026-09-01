<?php

namespace App\Support\ModuleFacts\Contracts;

use App\Support\ModuleFacts\Data\ModuleFactQuery;
use Illuminate\Database\Eloquent\Builder;

interface ModuleFactQueryResolver
{
    /** @param Builder<*> $query */
    public function apply(Builder $query, ModuleFactQuery $factQuery): void;
}