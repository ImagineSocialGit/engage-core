<?php

namespace App\Support\ModuleFacts\Contracts;

use App\Support\ModuleFacts\Data\ModuleFactDefinition;

interface ModuleFactProvider
{
    /** @return iterable<int, ModuleFactDefinition> */
    public function facts(): iterable;
}