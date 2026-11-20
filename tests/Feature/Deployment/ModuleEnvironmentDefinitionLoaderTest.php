<?php

namespace Tests\Feature\Deployment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use App\Support\Environment\EnvironmentVariableCatalog;
use App\Support\Environment\ModuleEnvironmentDefinitionLoader;
use InvalidArgumentException;
use Tests\TestCase;

final class ModuleEnvironmentDefinitionLoaderTest extends TestCase
{
    public function test_forms_definitions_are_available_before_module_contributors_register(): void
    {
        $definitions = ModuleEnvironmentDefinitionLoader::definitions();

        $this->assertSame('forms', $definitions['FORMS_APP_URL']->owner);
        $this->assertSame(EnvironmentVariableDefinition::SCOPE_CLIENT, $definitions['FORMS_APP_URL']->scope);
        $this->assertSame(EnvironmentVariableDefinition::SCOPE_ROOT, $definitions['FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES']->scope);
        $this->assertTrue($definitions['FORMS_EXTERNAL_INTAKE_CLIENT_SECRET']->secret);

        foreach ($definitions as $key => $definition) {
            $this->assertEquals($definition, EnvironmentVariableCatalog::builtInDefinitions()[$key]);
        }
    }

    public function test_new_module_declaration_is_discovered_without_a_central_catalog_edit(): void
    {
        $directory = $this->temporaryModuleDirectory([
            'Example' => [
                ['key' => 'EXAMPLE_ENABLED', 'scope' => 'client', 'secret' => false],
            ],
        ]);

        try {
            $definitions = ModuleEnvironmentDefinitionLoader::definitions($directory);

            $this->assertSame('example', $definitions['EXAMPLE_ENABLED']->owner);
            $this->assertSame(EnvironmentVariableDefinition::SCOPE_CLIENT, $definitions['EXAMPLE_ENABLED']->scope);
        } finally {
            $this->removeTemporaryModuleDirectory($directory, ['Example']);
        }
    }

    public function test_two_modules_cannot_claim_the_same_key(): void
    {
        $directory = $this->temporaryModuleDirectory([
            'Example' => [
                ['key' => 'DUPLICATE_OWNERSHIP', 'scope' => 'client', 'secret' => false],
            ],
            'Another' => [
                ['key' => 'DUPLICATE_OWNERSHIP', 'scope' => 'root', 'secret' => false],
            ],
        ]);

        try {
            $this->expectException(InvalidArgumentException::class);
            ModuleEnvironmentDefinitionLoader::definitions($directory);
        } finally {
            $this->removeTemporaryModuleDirectory($directory, ['Example', 'Another']);
        }
    }

    public function test_malformed_module_definition_fails_closed(): void
    {
        $directory = $this->temporaryModuleDirectory([
            'Example' => [
                ['key' => 'EXAMPLE_INVALID', 'scope' => 'root', 'secret' => 'yes'],
            ],
        ]);

        try {
            $this->expectException(InvalidArgumentException::class);
            ModuleEnvironmentDefinitionLoader::definitions($directory);
        } finally {
            $this->removeTemporaryModuleDirectory($directory, ['Example']);
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $modules
     */
    private function temporaryModuleDirectory(array $modules): string
    {
        $directory = sys_get_temp_dir().'/engage-module-env-'.bin2hex(random_bytes(10));

        foreach ($modules as $module => $entries) {
            $path = $directory.'/'.$module.'/Environment';

            if (! mkdir($path, 0700, true) && ! is_dir($path)) {
                throw new InvalidArgumentException('Unable to create environment definition fixture directory.');
            }

            file_put_contents($path.'/definitions.php', '<?php return '.var_export($entries, true).';');
        }

        return $directory;
    }

    /** @param array<int, string> $modules */
    private function removeTemporaryModuleDirectory(string $directory, array $modules): void
    {
        foreach ($modules as $module) {
            $path = $directory.'/'.$module.'/Environment';
            @unlink($path.'/definitions.php');
            @rmdir($path);
            @rmdir($directory.'/'.$module);
        }

        @rmdir($directory);
    }
}
