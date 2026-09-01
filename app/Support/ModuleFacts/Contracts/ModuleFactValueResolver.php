<?php

namespace App\Support\ModuleFacts\Contracts;

use Illuminate\Database\Eloquent\Model;

interface ModuleFactValueResolver
{
    public function resolve(Model $subject): mixed;
}