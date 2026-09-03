<?php

namespace App\Support\ModuleIntegrations\InboundMessaging;

use App\Support\ModuleIntegrations\InboundMessaging\Contracts\InboundEmailRouteAutomationWorkspace;

final class UnavailableInboundEmailRouteAutomationWorkspace implements InboundEmailRouteAutomationWorkspace
{
    public function available(): bool
    {
        return false;
    }

    public function readForRoutes(array $routes): array
    {
        return [];
    }
}