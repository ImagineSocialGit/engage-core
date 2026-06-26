<?php

use App\Providers\AppServiceProvider;
use App\Providers\ClientServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\ModuleBootstrapServiceProvider;

return [
    ClientServiceProvider::class,
    AppServiceProvider::class,
    ModuleBootstrapServiceProvider::class,
    HorizonServiceProvider::class,
];