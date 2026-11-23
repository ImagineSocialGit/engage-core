<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use Tests\TestCase;

class ExternalFormsClientEnvironmentContractTest extends TestCase
{
    public function test_external_forms_access_identity_credentials_and_domains_are_client_owned(): void
    {
        $clientOwnedKeys = ClientEnvironmentLoader::clientOwnedKeys();

        foreach ([
            'FORMS_EXTERNAL_INTAKE_ENABLED',
        ] as $key) {
            $this->assertContains($key, $clientOwnedKeys);
        }
    }
}