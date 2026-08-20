<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bounded Message Template Composition
    |--------------------------------------------------------------------------
    |
    | Shared authoring values are optional. Core defines no shared copy by
    | default. Selected clients may contribute bounded platform/client/family/
    | context layers under client/{client-key}/config/messaging/composition.php.
    |
    | Runtime sending never reads this config. Preset sync materializes these
    | low-cardinality authoring layers before publishing fully resolved immutable
    | MessageTemplateVersion payloads.
    |
    */
    'layers' => [],
];