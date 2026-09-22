<?php

namespace App\Support\Modules\Migrations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class ModuleMigrationPreflightInspector
{
    public function __construct(
        private readonly Migrator $migrator,
        private readonly ModuleMigrationStatusInspector $statuses,
    ) {}

    public function inspect(
        ModuleMigrationPlan $plan,
    ): ModuleMigrationPreflightResult {
        if (! $this->migrator->repositoryExists()) {
            return new ModuleMigrationPreflightResult(
                statuses: [],
                blockers: [
                    'Laravel migration history is missing. Run platform migrations before operating on module schema.',
                ],
                warnings: [],
            );
        }

        if (! Schema::hasTable('module_installations')) {
            return new ModuleMigrationPreflightResult(
                statuses: [],
                blockers: [
                    'The module installation ledger is missing. Run platform migrations before operating on module schema.',
                ],
                warnings: [],
            );
        }

        if (! Schema::hasColumn('module_installations', 'migration_checksums')) {
            return new ModuleMigrationPreflightResult(
                statuses: [],
                blockers: [
                    'The module installation ledger does not support migration checksums. Run platform migrations before operating on module schema.',
                ],
                warnings: [],
            );
        }

        $statuses = $this->statuses->inspectScopes($plan->migrationScopes);
        $blockers = [];
        $warnings = [];

        foreach ($statuses as $status) {
            $moduleKey = (string) $status->scope->moduleKey;

            if ($status->integrityState === ModuleMigrationStatus::INTEGRITY_UNAVAILABLE) {
                $blockers[] = "Module [{$moduleKey}] migration integrity is unavailable.";

                continue;
            }

            if ($status->integrityState === ModuleMigrationStatus::INTEGRITY_DRIFT
                && $status->recordedMigrationChecksums === null
                && $status->changedAppliedMigrationFiles === []
                && $status->missingRecordedMigrationFiles === []
                && $status->untrackedAppliedMigrationFiles === []
            ) {
                $blockers[] = "Module [{$moduleKey}] contains an invalid accepted migration checksum baseline.";
            }

            if ($status->changedAppliedMigrationFiles !== []) {
                $blockers[] = sprintf(
                    'Module [%s] contains changed applied migration files [%s]. Add a new migration instead of editing applied history.',
                    $moduleKey,
                    implode(', ', $status->changedAppliedMigrationFiles),
                );
            }

            if ($status->missingRecordedMigrationFiles !== []) {
                $blockers[] = sprintf(
                    'Module [%s] is missing previously accepted migration files [%s].',
                    $moduleKey,
                    implode(', ', $status->missingRecordedMigrationFiles),
                );
            }

            if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED
                && $status->untrackedAppliedMigrationFiles !== []
            ) {
                $blockers[] = sprintf(
                    'Module [%s] contains applied migrations absent from its accepted checksum baseline [%s].',
                    $moduleKey,
                    implode(', ', $status->untrackedAppliedMigrationFiles),
                );
            }

            if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED
                && is_array($status->recordedMigrationChecksums)
            ) {
                $recordedButUnapplied = array_values(array_intersect(
                    array_keys($status->recordedMigrationChecksums),
                    $status->pendingMigrationFiles,
                ));
                sort($recordedButUnapplied, SORT_STRING);

                if ($recordedButUnapplied !== []) {
                    $blockers[] = sprintf(
                        'Module [%s] is missing Laravel migration-history rows for previously accepted migrations [%s].',
                        $moduleKey,
                        implode(', ', $recordedButUnapplied),
                    );
                }
            }

            if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED
                && $status->integrityState === ModuleMigrationStatus::INTEGRITY_BASELINE_MISSING
            ) {
                if ($status->pendingMigrationFiles !== []) {
                    $blockers[] = sprintf(
                        'Module [%s] has no accepted checksum baseline and has pending migrations [%s]. Establish the baseline in a schema-current deployment before adding module migrations.',
                        $moduleKey,
                        implode(', ', $status->pendingMigrationFiles),
                    );
                } else {
                    $warnings[] = "Module [{$moduleKey}] has no accepted checksum baseline. This schema-current operation may establish it.";
                }
            }
        }

        return new ModuleMigrationPreflightResult(
            statuses: $statuses,
            blockers: $blockers,
            warnings: $warnings,
        );
    }

    public function assertSafe(ModuleMigrationPlan $plan): void
    {
        $result = $this->inspect($plan);

        if ($result->safe()) {
            return;
        }

        throw new RuntimeException(
            'Module migration preflight failed: '.$result->summary(),
        );
    }
}