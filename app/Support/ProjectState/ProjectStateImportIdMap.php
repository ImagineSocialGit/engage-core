<?php

namespace App\Support\ProjectState;

use RuntimeException;

class ProjectStateImportIdMap
{
    /** @var array<string, array<string, int|string>> */
    private array $importedIds = [];

    public function reset(): void
    {
        $this->importedIds = [];
    }

    public function remember(
        string $table,
        mixed $sourceId,
        mixed $targetId,
    ): void {
        if (($sourceId === null || $sourceId === '')
            || ($targetId === null || $targetId === '')
        ) {
            throw new RuntimeException(
                "Project-state table [{$table}] could not establish an ID mapping."
            );
        }

        $this->importedIds[$table][(string) $sourceId] = $targetId;
    }

    public function get(string $table, mixed $sourceId): int|string
    {
        $mapped = $this->importedIds[$table][(string) $sourceId] ?? null;

        if (! is_int($mapped) && ! is_string($mapped)) {
            throw new RuntimeException(
                "Project-state source ID [{$sourceId}] has not been imported for [{$table}]."
            );
        }

        return $mapped;
    }
}