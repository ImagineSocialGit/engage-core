<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use Tests\TestCase;

class ExternalFormsClientEnvironmentContractTest extends TestCase
{
    public function test_external_forms_access_identity_and_credentials_are_client_owned(): void
    {
        $clientOwnedKeys = ClientEnvironmentLoader::clientOwnedKeys();

        foreach ([
            'FORMS_EXTERNAL_INTAKE_ENABLED',
            'FORMS_EXTERNAL_INTAKE_CLIENT_ID',
            'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET',
            'FORMS_EXTERNAL_INTAKE_SOURCE',
            'FORMS_EXTERNAL_INTAKE_PROVIDER',
            'FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS',
        ] as $key) {
            $this->assertContains($key, $clientOwnedKeys);
        }
    }
}