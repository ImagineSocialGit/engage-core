<?php

namespace App\Providers\Modules;

use App\Providers\ModuleIntegrationServiceProvider;

/**
 * Compatibility alias for existing integration-boundary tests and client providers.
 * New registration must use the always-on ModuleIntegrationServiceProvider.
 *
 * @deprecated
 */
class IntegrationsModuleServiceProvider extends ModuleIntegrationServiceProvider
{
}