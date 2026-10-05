<?php

namespace App\Modules\Portal\Contracts;

interface PortalRouteContributor
{
    public function registerRoutes(): void;
}