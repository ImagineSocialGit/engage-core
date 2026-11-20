<?php

/**
 * Static, bootstrap-safe environment ownership for this module.
 * Owner is derived from the module directory; requiredness remains governed
 * by the module's deployment contributor and the selected client roles.
 * Do not read env(), config(), or services from this file.
 */
return [
    ['key' => 'FLOW_ROUTE_CONTINUATION_QUEUE', 'scope' => 'root', 'secret' => false],
    ['key' => 'FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET', 'scope' => 'root', 'secret' => false],
];
