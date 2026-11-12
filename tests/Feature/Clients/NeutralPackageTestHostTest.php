<?php

namespace Tests\Feature\Clients;

use App\Providers\ClientServiceProvider;
use App\Support\Clients\ClientPackageManifest;
use App\Support\Clients\ClientPackageRuntime;
use RuntimeException;
use Tests\TestCase;

class NeutralPackageTestHostTest extends TestCase
{
    protected function tearDown(): void
    {
        ClientPackageRuntime::reset();

        parent::tearDown();
    }

    public function test_default_testing_host_does_not_select_a_real_client(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('default', config('client.key'));
        $this->assertNull(config('client.config_path'));
        $this->assertNull(config('client.views_path'));
        $this->assertNull(config('client.env_path'));
    }

    public function test_package_test_activation_is_limited_to_declared_modules(): void
    {
        $original = getenv('ENGAGE_PACKAGE_TEST_MODULES');

        try {
            ClientPackageRuntime::replace(new ClientPackageManifest(
                moduleDefinitions: [
                    'fixture_vertical' => [
                        'name' => 'Fixture Vertical',
                        'depends_on' => ['core'],
                    ],
                ],
            ));

            putenv('ENGAGE_PACKAGE_TEST_MODULES=fixture_vertical');
            (new ClientServiceProvider($this->app))->register();

            $this->assertContains('fixture_vertical', config('modules.enabled'));
            $this->assertNotContains('donations', config('modules.enabled'));
        } finally {
            $original === false
                ? putenv('ENGAGE_PACKAGE_TEST_MODULES')
                : putenv('ENGAGE_PACKAGE_TEST_MODULES='.$original);
        }
    }

    public function test_package_test_cannot_enable_an_undeclared_module(): void
    {
        $original = getenv('ENGAGE_PACKAGE_TEST_MODULES');

        try {
            ClientPackageRuntime::replace(new ClientPackageManifest(
                moduleDefinitions: [
                    'fixture_vertical' => ['name' => 'Fixture Vertical'],
                ],
            ));

            putenv('ENGAGE_PACKAGE_TEST_MODULES=donations');
            $this->expectException(RuntimeException::class);

            (new ClientServiceProvider($this->app))->register();
        } finally {
            $original === false
                ? putenv('ENGAGE_PACKAGE_TEST_MODULES')
                : putenv('ENGAGE_PACKAGE_TEST_MODULES='.$original);
        }
    }
}