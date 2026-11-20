<?php

/**
 * Static, bootstrap-safe environment ownership for this module.
 * Owner is derived from the module directory; requiredness remains governed
 * by the module's deployment contributor and the selected client roles.
 * Do not read env(), config(), or services from this file.
 */
return [
    ['key' => 'SCHEDULING_APP_URL', 'scope' => 'client', 'secret' => false],
    ['key' => 'SCHEDULING_TRAVEL_PROVIDER', 'scope' => 'client', 'secret' => false],
    ['key' => 'GOOGLE_ROUTES_API_KEY', 'scope' => 'client', 'secret' => true],
];
