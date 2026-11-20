<?php

namespace App\Support\Environment;

use App\Support\Environment\Data\EnvironmentVariableDefinition;
use InvalidArgumentException;

/**
 * Bootstrap-safe discovery for static, module-owned environment keys.
 *
 * This cannot depend on config(), the container, or deployment contributors:
 * client .env ownership is checked before module providers are registered.
 * The owning module contributes only a key, scope and sensitivity; owner is
 * derived from its source directory rather than repeated in every record.
 */
final class ModuleEnvironmentDefinitionLoader
{
    /**
     * @return array<string, EnvironmentVariableDefinition>
     */
    public static function definitions(?string $modulesDirectory = null): array
    {
        $directory = $modulesDirectory ?? dirname(__DIR__, 2).'/Modules';

        if (! is_dir($directory)) {
            throw new InvalidArgumentException('Module environment definition directory is missing.');
        }

        $paths = glob(rtrim($directory, '/').'/*/Environment/definitions.php');

        if ($paths === false) {
            throw new InvalidArgumentException('Unable to discover module environment definitions.');
        }

        sort($paths, SORT_STRING);
        $definitions = [];

        foreach ($paths as $path) {
            $moduleName = basename(dirname(dirname($path)));

            if (preg_match('/^[A-Z][A-Za-z0-9]*$/D', $moduleName) !== 1) {
                throw new InvalidArgumentException('Invalid module environment definition directory.');
            }

            $owner = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $moduleName));
            $entries = require $path;

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new InvalidArgumentException("Module [{$owner}] environment definitions must be a list.");
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)
                    || count($entry) !== 3
                    || ! is_string($entry['key'] ?? null)
                    || ! is_string($entry['scope'] ?? null)
                    || ! is_bool($entry['secret'] ?? null)
                ) {
                    throw new InvalidArgumentException("Module [{$owner}] has an invalid environment definition.");
                }

                $definition = new EnvironmentVariableDefinition(
                    key: $entry['key'],
                    scope: $entry['scope'],
                    owner: $owner,
                    secret: $entry['secret'],
                );

                if (isset($definitions[$definition->key])) {
                    throw new InvalidArgumentException(
                        "Module environment key [{$definition->key}] has multiple owners.",
                    );
                }

                $definitions[$definition->key] = $definition;
            }
        }

        return $definitions;
    }
}
