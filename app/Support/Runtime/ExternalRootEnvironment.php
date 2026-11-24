<?php

namespace App\Support\Runtime;

use Illuminate\Foundation\Application;
use RuntimeException;

/**
 * Opt-in, read-only external root environment binding.
 *
 * Infrastructure is responsible for creating and approving the generation;
 * this class cannot create secrets, directories, caches, or process settings.
 */
final class ExternalRootEnvironment
{
    public static function configure(Application $app): void
    {
        $directory = getenv('ENGAGE_RUNTIME_CONFIG_DIR');

        if ($directory === false || $directory === '') {
            return; // Existing local and deployed applications remain unchanged.
        }

        $cachePath = getenv('APP_CONFIG_CACHE');
        if (! is_string($cachePath) || $cachePath === '') {
            throw new RuntimeException('External runtime configuration requires a dedicated cache path.');
        }

        self::assertApprovedPaths($directory, $cachePath);
        $app->useEnvironmentPath($directory);
        $app->loadEnvironmentFrom('root.env');
    }

    /**
     * Validate layout and ownership metadata without reading secret contents.
     * Rechecked independently by Infrastructure immediately before cutover.
     */
    public static function assertApprovedPaths(string $directory, string $cachePath): void
    {
        if (! str_starts_with($directory, '/') || str_contains($directory, "\0")
            || str_contains($directory, '//') || str_contains($directory, '/../')
            || str_ends_with($directory, '/..') || str_contains($directory, '/./')
            || str_ends_with($directory, '/.') || str_ends_with($directory, '/')
        ) {
            throw new RuntimeException('Invalid external runtime configuration location.');
        }

        $revision = basename($directory);
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', $revision)
            || basename(dirname($directory)) !== 'config'
            || basename(dirname($directory, 2)) !== 'runtime'
            || basename(dirname($directory, 3)) !== 'shared'
        ) {
            throw new RuntimeException('Invalid external runtime configuration layout.');
        }

        $instance = dirname($directory, 4);
        $cacheDirectory = dirname($cachePath);
        if (basename($cachePath) !== 'config.php'
            || basename(dirname($cacheDirectory)) !== 'cache'
            || dirname($cacheDirectory, 2) !== $instance.'/shared/runtime'
            || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,127}\z/D', basename($cacheDirectory))
        ) {
            throw new RuntimeException('Invalid external runtime configuration cache location.');
        }

        // Reject symlinked ancestors, including symlinked /srv or shared parents.
        foreach ([$directory, $cacheDirectory] as $path) {
            $chain = [];
            for ($cursor = $path; $cursor !== '/'; $cursor = dirname($cursor)) {
                $chain[] = $cursor;
            }
            foreach (array_reverse($chain) as $part) {
                if (is_link($part) || ! is_dir($part)) {
                    throw new RuntimeException('Unsafe external runtime configuration directory.');
                }
            }
        }

        $configStat = lstat($directory);
        $cacheStat = lstat($cacheDirectory);
        if (! is_array($configStat) || ! is_array($cacheStat)) {
            throw new RuntimeException('External runtime configuration directories are unavailable.');
        }
        foreach ([$configStat, $cacheStat] as $stat) {
            $mode = $stat['mode'] & 07777;
            if (($mode & 0027) !== 0 || ($mode & 0100) === 0) {
                throw new RuntimeException('Unsafe external runtime configuration directory permissions.');
            }
        }

        self::assertPrivateFile($directory.'/root.env', 1048576, $configStat, true);
        if (file_exists($cachePath) || is_link($cachePath)) {
            self::assertPrivateFile($cachePath, 8388608, $cacheStat, false);
        }
    }

    /** @param array<string|int, mixed> $directoryStat */
    private static function assertPrivateFile(string $path, int $limit, array $directoryStat, bool $requireReadable): void
    {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('Missing or unsafe external runtime configuration file.');
        }

        $stat = lstat($path);
        if (! is_array($stat) || ! in_array($stat['mode'] & 07777, [0600, 0640], true)
            || ($stat['nlink'] ?? 0) !== 1 || ($stat['size'] ?? -1) > $limit
            || ($stat['uid'] ?? -1) !== ($directoryStat['uid'] ?? -2)
            || (($stat['mode'] & 0040) !== 0 && ($stat['gid'] ?? -1) !== ($directoryStat['gid'] ?? -2))
            || ($requireReadable && ! is_readable($path))
        ) {
            throw new RuntimeException('Unsafe external runtime configuration file metadata.');
        }
    }
}
