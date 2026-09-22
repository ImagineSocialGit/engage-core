<?php

namespace App\Support\Modules\Migrations;

use InvalidArgumentException;

final readonly class MigrationScopeDefinition
{
    private const MIGRATION_FILENAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/D';

    /**
     * @param array<int, string> $migrationFiles
     * @param array<string, string> $migrationChecksums
     */
    private function __construct(
        public string $key,
        public ?string $moduleKey,
        public string $path,
        public int $schemaVersion,
        public array $migrationFiles,
        public array $migrationChecksums,
    ) {}

    /** @param array<string, mixed> $definition */
    public static function platform(array $definition): self
    {
        return self::fromArray('platform', null, $definition);
    }

    /** @param array<string, mixed> $definition */
    public static function module(string $moduleKey, array $definition): self
    {
        $moduleKey = trim($moduleKey);

        if ($moduleKey === '') {
            throw new InvalidArgumentException(
                'Module migration scope keys must be non-empty strings.',
            );
        }

        return self::fromArray($moduleKey, $moduleKey, $definition);
    }

    public function isPlatform(): bool
    {
        return $this->moduleKey === null;
    }

    public function isModule(): bool
    {
        return $this->moduleKey !== null;
    }

    public function owns(string $migrationFile): bool
    {
        return array_key_exists($migrationFile, $this->migrationChecksums);
    }

    public function targetPath(string $migrationFile): string
    {
        if (! $this->owns($migrationFile)) {
            throw new InvalidArgumentException(
                "Migration scope [{$this->key}] does not own migration [{$migrationFile}].",
            );
        }

        return $this->path.'/'.$migrationFile;
    }

    public function checksum(string $migrationFile): string
    {
        if (! $this->owns($migrationFile)) {
            throw new InvalidArgumentException(
                "Migration scope [{$this->key}] does not own migration [{$migrationFile}].",
            );
        }

        return $this->migrationChecksums[$migrationFile];
    }

    /** @param array<string, mixed> $definition */
    private static function fromArray(
        string $key,
        ?string $moduleKey,
        array $definition,
    ): self {
        $unknownFields = array_values(array_diff(
            array_map(
                static fn (int|string $field): string => (string) $field,
                array_keys($definition),
            ),
            ['path'],
        ));

        if ($unknownFields !== []) {
            sort($unknownFields, SORT_STRING);

            throw new InvalidArgumentException(sprintf(
                'Migration scope [%s] contains unsupported field(s): [%s].',
                $key,
                implode(', ', $unknownFields),
            ));
        }

        $path = $definition['path'] ?? null;

        if (! is_string($path)) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] path must be a string.",
            );
        }

        $path = trim($path);

        if (! self::validPath($path)) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] path must be a normalized repository-relative directory under database/migrations.",
            );
        }

        $migrationsRoot = realpath(database_path('migrations'));
        $absolutePath = realpath(base_path($path));

        if (! is_string($migrationsRoot)
            || ! is_string($absolutePath)
            || ! is_dir($absolutePath)
        ) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] directory [{$path}] does not exist.",
            );
        }

        $rootPrefix = rtrim($migrationsRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        if ($absolutePath !== $migrationsRoot
            && ! str_starts_with($absolutePath, $rootPrefix)
        ) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] path must resolve beneath database/migrations.",
            );
        }

        $discovered = glob($absolutePath.DIRECTORY_SEPARATOR.'*.php');

        if (! is_array($discovered) || $discovered === []) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] directory [{$path}] contains no migration files.",
            );
        }

        $checksums = [];

        foreach ($discovered as $absoluteFile) {
            $filename = basename($absoluteFile);

            if (preg_match(self::MIGRATION_FILENAME_PATTERN, $filename) !== 1) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] contains invalid migration filename [{$filename}].",
                );
            }

            if (! is_file($absoluteFile) || ! is_readable($absoluteFile)) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] contains unreadable migration [{$filename}].",
                );
            }

            $checksum = hash_file('sha256', $absoluteFile);

            if (! is_string($checksum)) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] could not hash migration [{$filename}].",
                );
            }

            $checksums[$filename] = strtolower($checksum);
        }

        ksort($checksums, SORT_STRING);
        $files = array_keys($checksums);

        return new self(
            key: $key,
            moduleKey: $moduleKey,
            path: $path,
            schemaVersion: count($files),
            migrationFiles: $files,
            migrationChecksums: $checksums,
        );
    }

    private static function validPath(string $path): bool
    {
        if ($path === ''
            || str_contains($path, '\\')
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_ends_with($path, '/')
        ) {
            return false;
        }

        return preg_match(
            '/^database\/migrations(?:\/[a-z0-9_-]+)+$/D',
            $path,
        ) === 1;
    }
}