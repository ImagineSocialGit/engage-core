<?php

namespace App\Support\Runtime;

use RuntimeException;

/**
 * Selected-client runtime environment source, bound to the same private
 * generation and cache as the application's external root environment.
 *
 * This reader never writes a configuration generation or falls back to a
 * release-local .env when external mode has been selected.
 */
final class ExternalSelectedEnvironment
{
    public static function enabled(): bool
    {
        $directory = getenv('ENGAGE_RUNTIME_CONFIG_DIR');

        return is_string($directory) && $directory !== '';
    }

    public static function rootPath(): ?string
    {
        $directory = self::approvedDirectory();

        return $directory === null ? null : $directory.'/root.env';
    }

    public static function clientPath(): ?string
    {
        $directory = self::approvedDirectory();

        return $directory === null ? null : $directory.'/client.env';
    }

    private static function approvedDirectory(): ?string
    {
        $directory = getenv('ENGAGE_RUNTIME_CONFIG_DIR');
        if (! is_string($directory) || $directory === '') {
            return null;
        }

        $cachePath = getenv('APP_CONFIG_CACHE');
        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException('External selected-client configuration requires a dedicated cache path.');
        }

        // The root verifier also checks all path ancestors and root.env.
        ExternalRootEnvironment::assertApprovedPaths($directory, $cachePath);

        $path = $directory.'/client.env';
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Missing or unsafe external selected-client configuration file.');
        }

        $stat = lstat($path);
        $parent = lstat($directory);
        if (! is_array($stat) || ! is_array($parent)
            || ! in_array($stat['mode'] & 07777, [0600, 0640], true)
            || ($stat['nlink'] ?? 0) !== 1
            || ($stat['size'] ?? -1) > 1048576
            || ($stat['uid'] ?? -1) !== ($parent['uid'] ?? -2)
            || (($stat['mode'] & 0040) !== 0 && ($stat['gid'] ?? -1) !== ($parent['gid'] ?? -2))
        ) {
            throw new RuntimeException('Unsafe external selected-client configuration metadata.');
        }

        return $directory;
    }
}
