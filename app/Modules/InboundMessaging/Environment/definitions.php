<?php

/**
 * Static, bootstrap-safe environment ownership for this module.
 * Owner is derived from the module directory; requiredness remains governed
 * by the module's deployment contributor and the selected client roles.
 * Do not read env(), config(), or services from this file.
 */
return [
    ['key' => 'INBOUND_EMAIL_DOMAIN', 'scope' => 'client', 'secret' => false],
    ['key' => 'INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL', 'scope' => 'client', 'secret' => false],
];
