<?php

namespace App\Modules\Portal\Services;

use App\Modules\Portal\Contracts\PortalRouteContributor;

final class PortalRouteRegistry
{
    public const TAG = 'portal.route_contributors';

    public function __construct(private readonly iterable $contributors) {}

    public function registerRoutes(): void
    {
        foreach ($this->contributors as $contributor) {
            if ($contributor instanceof PortalRouteContributor) {
                $contributor->registerRoutes();
            }
        }
    }
}