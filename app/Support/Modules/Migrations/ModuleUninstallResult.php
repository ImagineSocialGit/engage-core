<?php

namespace App\Support\Modules\Migrations;

final readonly class ModuleUninstallResult
{
    /**
     * @param array<int, string> $rolledBackMigrationFiles
     */
    public function __construct(
        public string $moduleKey,
        public array $rolledBackMigrationFiles,
        public bool $alreadyUninstalled,
        public bool $usedAppliedDriftOverride,
    ) {}

    public function rolledBackMigrationCount(): int
    {
        return count($this->rolledBackMigrationFiles);
    }
}