<?php

namespace App\Modules\Portal\Data;

use InvalidArgumentException;

final readonly class PortalNavigationItem
{
    public function __construct(
        public string $key,
        public string $label,
        public string $routeName,
        public array $routeParameters = [],
        public int $sort = 100,
        public bool $requiresVerifiedEmail = true,
    ) {
        if (trim($key) === '' || trim($label) === '' || trim($routeName) === '') {
            throw new InvalidArgumentException('Portal navigation items require a key, label, and route name.');
        }
    }
}