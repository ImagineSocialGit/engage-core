<?php

namespace App\Support\Modules\Migrations;

use RuntimeException;

final class ModuleMigrationBaselineBootstrapper
{
    public function __construct(
        private readonly ModuleInstallationRepository $installations,
    ) {}

    public function assess(
        ModuleMigrationStatus $status,
    ): ModuleMigrationBaselineBootstrapAssessment {
        $moduleKey = (string) $status->scope->moduleKey;
        $currentMigrationFiles = $status->scope->migrationFiles;

        if ($status->ledgerStatus !== ModuleInstallation::STATUS_INSTALLED
            || $status->integrityState !== ModuleMigrationStatus::INTEGRITY_BASELINE_MISSING
        ) {
            return ModuleMigrationBaselineBootstrapAssessment::blocked(
                "Module [{$moduleKey}] is not an installed legacy checksum-baseline candidate.",
            );
        }

        $appliedMigrationFiles = array_values(array_diff(
            $currentMigrationFiles,
            $status->pendingMigrationFiles,
        ));

        if ($appliedMigrationFiles === []) {
            return ModuleMigrationBaselineBootstrapAssessment::blocked(
                "Module [{$moduleKey}] legacy checksum baseline cannot be reconstructed because Laravel migration history contains no applied migrations for this installed scope.",
            );
        }

        $expectedAppliedPrefix = array_slice(
            $currentMigrationFiles,
            0,
            count($appliedMigrationFiles),
        );

        if ($appliedMigrationFiles !== $expectedAppliedPrefix) {
            return ModuleMigrationBaselineBootstrapAssessment::blocked(sprintf(
                'Module [%s] legacy checksum baseline cannot be reconstructed because applied Laravel migration history is not an exact prefix of the current migration inventory. Expected applied prefix [%s]; found [%s].',
                $moduleKey,
                $this->summarizeFiles($expectedAppliedPrefix),
                $this->summarizeFiles($appliedMigrationFiles),
            ));
        }

        $migrationChecksums = [];

        foreach ($appliedMigrationFiles as $migrationFile) {
            $migrationChecksums[$migrationFile] = $status->scope->checksum($migrationFile);
        }

        return ModuleMigrationBaselineBootstrapAssessment::ready(
            $migrationChecksums,
        );
    }

    /**
     * @param array<int, ModuleMigrationStatus> $statuses
     */
    public function bootstrap(array $statuses): void
    {
        foreach ($statuses as $status) {
            if ($status->ledgerStatus !== ModuleInstallation::STATUS_INSTALLED
                || $status->integrityState !== ModuleMigrationStatus::INTEGRITY_BASELINE_MISSING
            ) {
                continue;
            }

            $assessment = $this->assess($status);

            if (! $assessment->ready) {
                throw new RuntimeException(
                    $assessment->blocker
                        ?? 'Legacy module migration checksum baseline bootstrap is not safe.',
                );
            }

            $this->installations->establishMigrationChecksumBaseline(
                moduleKey: (string) $status->scope->moduleKey,
                migrationChecksums: $assessment->migrationChecksums,
            );
        }
    }

    /**
     * @param array<int, string> $files
     */
    private function summarizeFiles(array $files): string
    {
        return $files === []
            ? '-'
            : implode(', ', $files);
    }
}