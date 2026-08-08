<?php

namespace App\Support\Modules\Migrations;

final readonly class ModuleMigrationStatus
{
    public const MIGRATIONS_REPOSITORY_MISSING = 'repository_missing';

    public const MIGRATIONS_NOT_MIGRATED = 'not_migrated';

    public const MIGRATIONS_PARTIAL = 'partial';

    public const MIGRATIONS_CURRENT = 'current';

    public const LEDGER_MISSING = 'ledger_missing';

    public const LEDGER_UNTRACKED = 'untracked';

    public const CONTRACT_UNAVAILABLE = 'unavailable';

    public const CONTRACT_UNTRACKED = 'untracked';

    public const CONTRACT_CURRENT = 'current';

    public const CONTRACT_DRIFT = 'drift';

    /**
     * @param array<int, string> $pendingMigrationFiles
     */
    public function __construct(
        public MigrationScopeDefinition $scope,
        public string $migrationState,
        public int $expectedMigrationCount,
        public int $ranMigrationCount,
        public array $pendingMigrationFiles,
        public string $ledgerStatus,
        public string $contractState,
        public ?int $recordedSchemaVersion,
        public ?string $recordedManifestHash,
    ) {}

    public function current(): bool
    {
        return $this->migrationState === self::MIGRATIONS_CURRENT;
    }

    public function ledgerCurrent(): bool
    {
        return $this->ledgerStatus === ModuleInstallation::STATUS_INSTALLED
            && $this->contractState === self::CONTRACT_CURRENT;
    }

    public function progress(): string
    {
        return $this->ranMigrationCount.'/'.$this->expectedMigrationCount;
    }

    public function pendingSummary(int $limit = 2): string
    {
        if ($this->pendingMigrationFiles === []) {
            return '-';
        }

        $visible = array_slice($this->pendingMigrationFiles, 0, max(1, $limit));
        $summary = implode(', ', $visible);
        $remaining = count($this->pendingMigrationFiles) - count($visible);

        return $remaining > 0
            ? $summary." (+{$remaining} more)"
            : $summary;
    }
}