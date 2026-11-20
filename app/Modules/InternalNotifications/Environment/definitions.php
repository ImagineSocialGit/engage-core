<?php

/**
 * Static, bootstrap-safe environment ownership for this module.
 * Owner is derived from the module directory; requiredness remains governed
 * by the module's deployment contributor and the selected client roles.
 * Do not read env(), config(), or services from this file.
 */
return [
    ['key' => 'INTERNAL_NOTIFICATION_FROM_ADDRESS', 'scope' => 'client', 'secret' => false],
    ['key' => 'INTERNAL_NOTIFICATION_FROM_NAME', 'scope' => 'client', 'secret' => false],
];
