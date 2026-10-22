<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClientServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleBootstrapServiceProvider;
use App\Providers\ModuleIntegrationServiceProvider;
use App\Providers\PlatformMigrationServiceProvider;

return [
    ClientServiceProvider::class,
    AppServiceProvider::class,
    PlatformMigrationServiceProvider::class,
    ModuleBootstrapServiceProvider::class,
    ModuleIntegrationServiceProvider::class,
    HorizonServiceProvider::class,
];