<?php

namespace Tests\Feature\Deployment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use Tests\TestCase;

class EnvironmentVariableCatalogTest extends TestCase
{
    public function test_client_environment_ownership_is_exposed_from_the_catalog(): void
    {
        foreach ([
            'APP_URL',
            'DB_DATABASE',
            'RESEND_API_KEY',
            'FORMS_EXTERNAL_INTAKE_CLIENT_SECRET',
            'TELNYX_API_KEY',
            'ZOOM_CLIENT_SECRET',
        ] as $key) {
            $definition = EnvironmentVariableCatalog::definition($key);

            $this->assertSame(EnvironmentVariableDefinition::SCOPE_CLIENT, $definition->scope);
        }

        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_ROOT,
            EnvironmentVariableCatalog::definition('APP_KEY')->scope,
        );
        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_ROOT,
            EnvironmentVariableCatalog::definition('FILESYSTEM_DISK')->scope,
        );
        $this->assertSame(
            EnvironmentVariableDefinition::SCOPE_ROOT,
            EnvironmentVariableCatalog::definition('RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS')->scope,
        );
    }

    public function test_root_and_client_reference_templates_cover_the_executable_catalog(): void
    {
        $rootKeys = $this->environmentKeysFromFile(base_path('.env.example'));
        $clientKeys = $this->environmentKeysFromFile(
            base_path('docs/config-templates/client-environment.example'),
        );

        $documented = array_values(array_unique([
            ...$rootKeys,
            ...$clientKeys,
        ]));
        sort($documented);

        $catalog = EnvironmentVariableCatalog::keys();
        sort($catalog);

        $this->assertSame($catalog, $documented);
    }

    public function test_client_reference_contains_only_client_owned_keys(): void
    {
        $keys = $this->environmentKeysFromFile(
            base_path('docs/config-templates/client-environment.example'),
        );

        foreach ($keys as $key) {
            $this->assertSame(
                EnvironmentVariableDefinition::SCOPE_CLIENT,
                EnvironmentVariableCatalog::definition($key)->scope,
                "{$key} must remain selected-client owned.",
            );
        }
    }

    /** @return array<int, string> */
    private function environmentKeysFromFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $keys = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(?:#\s*)?([A-Z][A-Z0-9_]*)\s*=/', $line, $matches) !== 1) {
                continue;
            }

            $keys[] = $matches[1];
        }

        return array_values(array_unique($keys));
    }
}