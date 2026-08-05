<?php

namespace App\Support\Modules\Migrations;

use InvalidArgumentException;

final readonly class MigrationScopeDefinition
{
    private const MIGRATION_FILENAME_PATTERN = '/^\d{4}_\d{2}_\d{2}_\d{6}_[a-z0-9_]+\.php$/D';

    /**
     * @param array<int, string> $migrationFiles
     */
    private function __construct(
        public string $key,
        public ?string $moduleKey,
        public string $path,
        public int $schemaVersion,
        public array $migrationFiles,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public static function platform(array $definition): self
    {
        return self::fromArray(
            key: 'platform',
            moduleKey: null,
            definition: $definition,
        );
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function module(
        string $moduleKey,
        array $definition,
    ): self {
        $moduleKey = trim($moduleKey);

        if ($moduleKey === '') {
            throw new InvalidArgumentException(
                'Module migration scope keys must be non-empty strings.',
            );
        }

        return self::fromArray(
            key: $moduleKey,
            moduleKey: $moduleKey,
            definition: $definition,
        );
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
        return in_array($migrationFile, $this->migrationFiles, true);
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

    /**
     * @param array<string, mixed> $definition
     */
    private static function fromArray(
        string $key,
        ?string $moduleKey,
        array $definition,
    ): self {
        $allowedFields = [
            'path',
            'schema_version',
            'migrations',
        ];
        $unknownFields = array_values(array_diff(
            array_map(
                static fn (int|string $field): string => (string) $field,
                array_keys($definition),
            ),
            $allowedFields,
        ));

        if ($unknownFields !== []) {
            sort($unknownFields);

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

        $schemaVersion = $definition['schema_version'] ?? null;

        if (! is_int($schemaVersion) || $schemaVersion < 1) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] schema_version must be a positive integer.",
            );
        }

        $migrationFiles = $definition['migrations'] ?? null;

        if (! is_array($migrationFiles) || $migrationFiles === []) {
            throw new InvalidArgumentException(
                "Migration scope [{$key}] migrations must be a non-empty array.",
            );
        }

        $normalizedFiles = [];

        foreach ($migrationFiles as $index => $migrationFile) {
            if (! is_string($migrationFile)) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] migration at index [{$index}] must be a string.",
                );
            }

            $migrationFile = trim($migrationFile);

            if (basename($migrationFile) !== $migrationFile
                || preg_match(self::MIGRATION_FILENAME_PATTERN, $migrationFile) !== 1
            ) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] contains invalid migration filename [{$migrationFile}].",
                );
            }

            if (in_array($migrationFile, $normalizedFiles, true)) {
                throw new InvalidArgumentException(
                    "Migration scope [{$key}] lists migration [{$migrationFile}] more than once.",
                );
            }

            $normalizedFiles[] = $migrationFile;
        }

        return new self(
            key: $key,
            moduleKey: $moduleKey,
            path: $path,
            schemaVersion: $schemaVersion,
            migrationFiles: $normalizedFiles,
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