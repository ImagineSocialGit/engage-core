<?php

namespace App\Support\SetupValidation\Contributors;

use App\Support\Modules\Migrations\MigrationScopeDefinition;
use App\Support\Modules\Migrations\ModuleInstallation;
use App\Support\Modules\Migrations\ModuleMigrationRegistry;
use App\Support\Modules\Migrations\ModuleMigrationStatus;
use App\Support\Modules\Migrations\ModuleMigrationStatusInspector;
use App\Support\Modules\ModuleManager;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;

final class ModuleMigrationsSetupValidationContributor implements SetupValidationContributor
{
    private const SOURCE = 'modules.migrations';

    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ModuleMigrationRegistry $registry,
        private readonly ModuleMigrationStatusInspector $inspector,
    ) {}

    public function findings(): iterable
    {
        $scopes = [];

        foreach ($this->modules->enabledKeysWithDependencies() as $moduleKey) {
            $scope = $this->registry->module($moduleKey);

            if (! $scope instanceof MigrationScopeDefinition) {
                continue;
            }

            $scopes[$scope->key] = $scope;
        }

        if ($scopes === []) {
            return;
        }

        foreach ($this->inspector->inspectScopes(array_values($scopes)) as $status) {
            yield from $this->migrationFindings($status);
            yield from $this->integrityFindings($status);
            yield from $this->ledgerFindings($status);
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function migrationFindings(ModuleMigrationStatus $status): iterable
    {
        if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_CURRENT) {
            return;
        }

        $moduleKey = (string) $status->scope->moduleKey;

        if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_REPOSITORY_MISSING) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.repository_missing',
                message: "Enabled module [{$moduleKey}] cannot validate migration state because Laravel's migration repository is missing. Run platform migrations first.",
            );

            return;
        }

        if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_NOT_MIGRATED) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.not_migrated',
                message: "Enabled module [{$moduleKey}] has no recorded migrations. Run [php artisan modules:install {$moduleKey}].",
            );

            return;
        }

        if ($status->migrationState === ModuleMigrationStatus::MIGRATIONS_PARTIAL) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.partial',
                message: "Enabled module [{$moduleKey}] has pending discovered migrations. Run [php artisan modules:migrate {$moduleKey}] when installed, or [php artisan modules:install {$moduleKey}] when untracked.",
            );

            return;
        }

        yield $this->error(
            status: $status,
            code: 'app.modules.migrations.state_invalid',
            message: "Enabled module [{$moduleKey}] has unsupported migration state [{$status->migrationState}].",
        );
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function integrityFindings(ModuleMigrationStatus $status): iterable
    {
        $moduleKey = (string) $status->scope->moduleKey;

        if ($status->integrityState === ModuleMigrationStatus::INTEGRITY_UNAVAILABLE) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.integrity_unavailable',
                message: "Enabled module [{$moduleKey}] cannot validate accepted migration checksums. Run platform migrations first.",
            );

            return;
        }

        if ($status->integrityState === ModuleMigrationStatus::INTEGRITY_BASELINE_MISSING) {
            if ($status->pendingMigrationFiles !== []) {
                yield $this->error(
                    status: $status,
                    code: 'app.modules.migrations.integrity_baseline_missing',
                    message: "Enabled module [{$moduleKey}] has no accepted migration checksum baseline and also has pending migrations.",
                );
            } else {
                yield $this->warning(
                    status: $status,
                    code: 'app.modules.migrations.integrity_baseline_missing',
                    message: "Enabled module [{$moduleKey}] has no accepted migration checksum baseline yet.",
                );
            }

            return;
        }

        if ($status->changedAppliedMigrationFiles !== []) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.applied_file_changed',
                message: "Enabled module [{$moduleKey}] contains an applied migration whose contents changed after acceptance.",
            );
        }

        if ($status->missingRecordedMigrationFiles !== []) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.recorded_file_missing',
                message: "Enabled module [{$moduleKey}] is missing a previously accepted migration file.",
            );
        }

        if ($status->untrackedAppliedMigrationFiles !== []) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.applied_file_untracked',
                message: "Enabled module [{$moduleKey}] contains an applied migration absent from its accepted checksum baseline.",
            );
        }
    }

    /** @return iterable<int, SetupValidationFinding> */
    private function ledgerFindings(ModuleMigrationStatus $status): iterable
    {
        if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLED) {
            if ($status->contractState === ModuleMigrationStatus::CONTRACT_DRIFT
                && ! $status->integrityBlocked()
            ) {
                $moduleKey = (string) $status->scope->moduleKey;

                yield $this->error(
                    status: $status,
                    code: 'app.modules.migrations.contract_drift',
                    message: "Enabled module [{$moduleKey}] has an installed ledger contract that does not match the current discovered migration contract. Run [php artisan modules:migrate {$moduleKey}].",
                    meta: [
                        'expected_manifest_hash' => $this->registry->manifestHash(
                            $status->scope,
                        ),
                        'recorded_manifest_hash' => $status->recordedManifestHash,
                    ],
                );
            }

            return;
        }

        $moduleKey = (string) $status->scope->moduleKey;

        if ($status->ledgerStatus === ModuleMigrationStatus::LEDGER_MISSING) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.ledger_missing',
                message: "Enabled module [{$moduleKey}] cannot validate installation state because [module_installations] is missing. Run platform migrations first.",
            );

            return;
        }

        if ($status->ledgerStatus === ModuleMigrationStatus::LEDGER_UNTRACKED) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.untracked',
                message: "Enabled module [{$moduleKey}] is not tracked as installed. Run [php artisan modules:reconcile {$moduleKey}] for an existing current schema or [php artisan modules:install {$moduleKey}] to install it.",
            );

            return;
        }

        if ($status->ledgerStatus === ModuleInstallation::STATUS_INSTALLING) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.installing',
                message: "Enabled module [{$moduleKey}] has interrupted installing state. Resume with [php artisan modules:install {$moduleKey}].",
            );

            return;
        }

        if ($status->ledgerStatus === ModuleInstallation::STATUS_FAILED) {
            yield $this->error(
                status: $status,
                code: 'app.modules.migrations.failed',
                message: "Enabled module [{$moduleKey}] has failed installation state. Resolve the migration failure and rerun [php artisan modules:install {$moduleKey}].",
            );

            return;
        }

        yield $this->error(
            status: $status,
            code: 'app.modules.migrations.ledger_status_invalid',
            message: "Enabled module [{$moduleKey}] has unsupported installation-ledger status [{$status->ledgerStatus}].",
        );
    }

    /** @param array<string, mixed> $meta */
    private function error(
        ModuleMigrationStatus $status,
        string $code,
        string $message,
        array $meta = [],
    ): SetupValidationFinding {
        return $this->finding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            status: $status,
            code: $code,
            message: $message,
            meta: $meta,
        );
    }

    /** @param array<string, mixed> $meta */
    private function warning(
        ModuleMigrationStatus $status,
        string $code,
        string $message,
        array $meta = [],
    ): SetupValidationFinding {
        return $this->finding(
            severity: SetupValidationFinding::SEVERITY_WARNING,
            status: $status,
            code: $code,
            message: $message,
            meta: $meta,
        );
    }

    /** @param array<string, mixed> $meta */
    private function finding(
        string $severity,
        ModuleMigrationStatus $status,
        string $code,
        string $message,
        array $meta = [],
    ): SetupValidationFinding {
        $moduleKey = (string) $status->scope->moduleKey;

        return new SetupValidationFinding(
            severity: $severity,
            code: $code,
            message: $message,
            source: self::SOURCE,
            path: $status->scope->path,
            module: $moduleKey,
            context: [
                'module_key' => $moduleKey,
                'migration_state' => $status->migrationState,
                'migration_progress' => $status->progress(),
                'pending_migrations' => $status->pendingMigrationFiles,
                'ledger_status' => $status->ledgerStatus,
                'contract_state' => $status->contractState,
                'integrity_state' => $status->integrityState,
                'changed_applied_migrations' => $status->changedAppliedMigrationFiles,
                'missing_recorded_migrations' => $status->missingRecordedMigrationFiles,
                'untracked_applied_migrations' => $status->untrackedAppliedMigrationFiles,
                'expected_schema_version' => $status->scope->schemaVersion,
                'recorded_schema_version' => $status->recordedSchemaVersion,
            ],
            meta: $meta,
        );
    }
}