<?php

namespace App\Modules\Core\Access\Data;

final readonly class AccessCapabilityDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public string $module = 'core',
    ) {}
}