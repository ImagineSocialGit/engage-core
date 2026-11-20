<?php

/**
 * Forms owns these environment keys regardless of whether it is enabled.
 * Deployment requirements remain conditional in FormsDeploymentPlanContributor.
 * Owner `forms` is inferred from the enclosing module directory.
 *
 * No env() calls belong here: Core reads these declarations before providers
 * register and before the selected client environment is fully loaded.
 */
return [
    ['key' => 'FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES', 'scope' => 'root', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_MAX_TIMESTAMP_DRIFT_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_NONCE_TTL_SECONDS', 'scope' => 'root', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_UNAUTHENTICATED_RATE_LIMIT_PER_MINUTE', 'scope' => 'root', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_CLIENT_RATE_LIMIT_PER_MINUTE', 'scope' => 'root', 'secret' => false],
    ['key' => 'FORMS_APP_URL', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_ENABLED', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_CLIENT_ID', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET', 'scope' => 'client', 'secret' => true],
    ['key' => 'FORMS_EXTERNAL_INTAKE_SOURCE', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_PROVIDER', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS', 'scope' => 'client', 'secret' => false],
    ['key' => 'FORMS_EXTERNAL_INTAKE_DOMAINS', 'scope' => 'client', 'secret' => false],
];
