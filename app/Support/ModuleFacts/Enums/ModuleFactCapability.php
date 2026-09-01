<?php

namespace App\Support\ModuleFacts\Enums;

enum ModuleFactCapability: string
{
    case Renderable = 'renderable';
    case Filterable = 'filterable';
    case Annualizable = 'annualizable';
    case Writable = 'writable';
}