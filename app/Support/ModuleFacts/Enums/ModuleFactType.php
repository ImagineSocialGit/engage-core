<?php

namespace App\Support\ModuleFacts\Enums;

enum ModuleFactType: string
{
    case String = 'string';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Money = 'money';
    case Date = 'date';
    case DateTime = 'datetime';
    case Enum = 'enum';
    case Url = 'url';
}