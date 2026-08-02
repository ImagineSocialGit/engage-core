<?php

namespace Tests\Feature\Clients;

use App\Support\Clients\ClientEnvironmentLoader;
use Tests\TestCase;

class ProjectStateClientEnvironmentContractTest extends TestCase
{
    public function test_project_state_admin_email_is_a_client_owned_environment_key(): void
    {
        $this->assertTrue(in_array(
            'PROJECT_STATE_ADMIN_EMAIL',
            ClientEnvironmentLoader::clientOwnedKeys(),
            true,
        ));
    }
}