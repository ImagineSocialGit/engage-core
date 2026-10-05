<?php

namespace App\Modules\Portal\Data;

use InvalidArgumentException;

final readonly class PortalDashboardPanel
{
    public function __construct(
        public string $key,
        public string $view,
        public int $sort = 100,
        public array $data = [],
        public bool $requiresVerifiedEmail = true,
    ) {
        if (trim($key) === '' || trim($view) === '') {
            throw new InvalidArgumentException('Portal dashboard panels require a key and view.');
        }
    }
}