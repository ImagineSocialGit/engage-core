# Modular Migration Architecture

## Status

The platform migration-path and installation-ledger foundation is implemented.

The platform migration path is registered independently of module enablement. The ten original platform migrations retain their basenames and contents under:

```text
database/migrations/platform/
```

The platform path also contains the `module_installations` ledger migration.

All schema-managed modules under `database/migrations/modules/` have now been relocated into their registered owner directories:

```text
Core
Internal Notifications
Location
Portal
Forms
Documents
Commerce
Events
Workflow
Flow Routes
Tasks
Messaging
Inbound Messaging
Campaigns
Broadcasts
Webinars
Scheduling
```

The first four relocation slices moved seventy-one unchanged module migrations. The Scheduling cutover moved nine unchanged migrations and replaced its BookingHold creation migration with the authoritative clean-install definition.

Scheduling now owns ten migrations instead of eleven. The former follow-up migration:

```text
2026_08_04_190000_add_location_snapshots_to_booking_holds.php
```

is removed from the clean-install manifest because `location_type` and `location_details` are created directly by:

```text
2026_07_21_180101_create_booking_holds_table.php
```

The two Events migrations remain separate because they create two distinct tables and the external-reference migration also adds the deferred circular foreign key back to `events`. No same-batch follow-up alteration migration remains.

Mortgage was already isolated under its vertical path before this workstream, so the relocation batches do not move or rewrite its migrations.

No module-owned PHP migration files remain directly under `database/migrations/`.

The final migration-path selection cutover is implemented. `PlatformMigrationServiceProvider` now registers only the platform migration path during normal application bootstrap. Optional module directories are no longer registered merely because their code exists on disk.

Module installation and upgrade commands select module paths explicitly through `ModuleMigrationPlanner` and pass those selected directories directly to Laravel's migrator. Runtime module enablement and provider loading do not control schema discovery.

The test bootstrap separately registers every registry-owned non-vertical module path so `migrate:fresh` and `RefreshDatabase` continue constructing the complete test schema without restoring broad production discovery.

The read-only module migration planning and status foundation is now implemented. `ModuleMigrationPlanner` resolves strict dependency-ordered plans, maps schema-free modules to any schema-owning dependencies, rejects unknown dependencies and cycles, and keeps Scheduling independent from Location.

The read-only command:

```text
php artisan modules:status
php artisan modules:status scheduling
```

compares each selected module manifest with Laravel's migration repository and the `module_installations` ledger without mutating either source of state.

Locked selective module installation, installed-scope upgrades, and existing-database reconciliation are implemented through:

```text
php artisan modules:install scheduling
php artisan modules:migrate scheduling
php artisan modules:reconcile scheduling
```

All three write-capable commands resolve dependency order and share one global module-migration lock. Installation may adopt or create selected schema, migration upgrades require installed ledger state before any schema work, and reconciliation writes ledger state only after the complete selected closure is already current.

Migration readiness is now part of the shared setup-validation pipeline. Enabled schema-owning modules and their schema-owning dependencies must be migration-current, tracked as installed, and aligned with the current schema version and manifest hash before `setup:validate` can pass.

New-client installation orchestration is implemented through `engage:install`. The command explicitly runs the platform migration path, installs the requested dependency-ordered module schema through the existing locked executor, synchronizes presets, and finishes with setup validation. Each stage stops on failure and the complete workflow is designed to be safely rerunnable.

The operator/deployment workflow is now documented in `docs/operations/deployment-command-workflow.md`. It distinguishes new-client installation, normal existing-client upgrades, module additions, reconciliation, and controlled Project State rebuilds after the runtime path-selection cutover.

A read-only handoff script, `scripts/make-system-deployment-context-dump.sh`, now produces `file_dumps/EngageCore_system_deployment_context_dump.txt` so other threads can inspect the current deployment commands, migration architecture, high-level resolved setup, status/validation output, and canonical operational docs without reconstructing the system from feature-specific dependency cones.

## Goal

Engage Core must support two installation workflows without treating every optional module as mandatory schema:

```text
new client
    install platform + Core + the selected module dependency closure

existing client adds a module later
    install only that module and any missing declared dependencies
```

Normal request startup must never run migrations.

## State distinctions

Keep these concepts separate:

```text
available
    module code and definition exist

installed
    required module schema has completed successfully

current
    all migrations expected by the installed code have run

enabled
    selected-client configuration allows runtime use

provider-loaded
    provider loaded because the module is enabled or dependency-required

visible
    the enabled module deliberately exposes navigation or another product surface
```

Disabling a module must not remove its tables.

Schema installation must not be inferred from runtime provider loading.

## Ownership registry

`config/module_migrations.php` is the durable schema-ownership manifest.

Every registered scope declares:

```text
path
    repository-relative migration directory

schema_version
    compact module-level schema generation

migrations
    globally unique migration basenames owned by the scope
```

The platform scope owns shared application infrastructure that is not an optional feature module.

Module scopes must use keys already defined in `config/modules.php`.

Modules without owned schema are omitted from the migration registry. They remain valid runtime modules and do not require an installation ledger record merely to be enabled.

Current schema-free module definitions are:

```text
dashboard
integrations
```

## Directory layout

The active platform path is:

```text
database/migrations/platform/
```

Active module paths are:

```text
database/migrations/modules/core/
database/migrations/modules/internal_notifications/
database/migrations/modules/location/
database/migrations/modules/portal/
database/migrations/modules/forms/
database/migrations/modules/documents/
database/migrations/modules/media/
database/migrations/modules/commerce/
database/migrations/modules/events/
database/migrations/modules/workflow/
database/migrations/modules/flow_routes/
database/migrations/modules/tasks/
database/migrations/modules/messaging/
database/migrations/modules/inbound_messaging/
database/migrations/modules/campaigns/
database/migrations/modules/broadcasts/
database/migrations/modules/webinars/
database/migrations/modules/scheduling/
```

The Mortgage vertical retains:

```text
database/migrations/verticals/mortgage/
```

Mortgage remains isolated under its vertical path. It is excluded from the complete non-vertical test bootstrap and is selected only when a module migration command explicitly plans Mortgage.

A runtime module provider does not need to be enabled merely to locate or install that module's schema.

## Migration path selection

`PlatformMigrationServiceProvider` is registered in `bootstrap/providers.php` before runtime module bootstrap.

During normal application bootstrap it registers exactly:

```text
database/migrations/platform/
```

It does not register optional module or vertical migration paths from:

```text
directory existence
runtime module enablement
runtime provider loading
module_installations rows
```

Selected module schema is executed only when module migration commands explicitly pass the dependency-ordered scope paths to Laravel's migrator.

The provider does not:

- run migrations during application startup;
- discover optional module directories;
- enable modules;
- infer module installation state;
- mark installation ledger rows;
- call the migrator directly.

Testing uses a separate bootstrap policy. `Tests\TestCase::createApplication()` registers the registry-owned paths under `database/migrations/modules/` with Laravel's migrator after the application boots and before database-refresh test traits run. This keeps complete-schema test construction isolated from runtime path selection.

## Installation ledger

`module_installations` supplements Laravel's shared `migrations` table.

Laravel's table remains authoritative for individual migration files.

The module ledger stores compact module-level operational state:

```text
module_key
status
schema_version
manifest_hash
installed_at
last_migrated_at
timestamps
```

Supported initial statuses are:

```text
installing
installed
failed
```

`manifest_hash` is a SHA-256 digest of the registered scope identity, target path, schema version, and ordered migration manifest. It will allow later commands to detect drift between installed state and the code's expected migration contract.

The ledger does not replace migration batches, filenames, or Laravel's migration repository.

Existing databases are not marked installed during normal startup. `modules:install {module}` may explicitly adopt an already-current selected scope, while `modules:reconcile {module?}` performs deliberate existing-database adoption without running migrations.

An installation attempt uses these ledger transitions per selected scope:

```text
untracked, failed, drifted, or interrupted
    installing

successfully current
    installed

execution or verification failure
    failed
```

Existing `installed_at` and `last_migrated_at` values are preserved while an installed scope is retried or fails. A fully current scope with a current installed ledger contract is a true no-op.

## Migration planning

`ModuleMigrationPlanner` accepts one or more known module keys and produces:

```text
requested module keys
dependency-ordered module keys
schema-owning migration scopes in the same dependency order
```

Dependency traversal is strict for migration operations. It rejects:

```text
unknown requested modules
unknown declared dependencies
dependency cycles
empty migration-plan requests
```

Schema-free modules remain valid planning targets. For example, Integrations owns no migration scope but depends on Core, so its migration plan contains Core's scope while retaining Integrations in the resolved module closure. Reporting now owns its dedicated `database/migrations/modules/reporting` scope and resolves to Core + Reporting.

Scheduling resolves exactly to:

```text
core
scheduling
```

Location is not included.

## Read-only migration status

`ModuleMigrationStatusInspector` keeps two questions separate:

```text
migration currency
    whether every migration basename in the current module manifest exists in Laravel's migrations table

ledger tracking
    whether module_installations records installed state with the current schema version and manifest hash
```

Migration states are:

```text
repository_missing
not_migrated
partial
current
```

Ledger rows may be absent even when all migrations are current. Existing databases therefore correctly report:

```text
migrations: current
ledger: untracked
contract: untracked
```

until `modules:reconcile` explicitly records the existing installation state.

The status command is read-only. It does not run migrations, create ledger rows, alter runtime enablement, or infer installation from provider loading.

## Selective module installation

`ModuleMigrationExecutor` consumes a dependency-ordered `ModuleMigrationPlan`. It requires the platform migration repository and `module_installations` ledger to exist before any module scope can run.

The executor acquires one cache-backed global lock:

```text
engage-core:module-migrations
```

A second module migration operation fails immediately while that lock is held. The lock covers the complete dependency plan, not individual migrations.

For each schema-owning scope, the executor:

```text
inspects migration and ledger state
skips a fully current and correctly tracked scope
records installing state for work that must be adopted, resumed, repaired, or migrated
verifies every registered migration file exists
runs only that scope's registered directory through Laravel's shared migrator
verifies the complete scope manifest is current
records installed state with the current schema version and manifest hash
```

If a scope fails, it is recorded as failed and execution stops immediately. Earlier dependency scopes that completed remain installed. Later scopes are not started.

The executor uses Laravel's one shared `migrations` table and does not alter runtime module enablement. It does not scan or run unselected module paths.

`modules:install {module}` exposes this executor. For Scheduling, the selected schema order is exactly:

```text
core
scheduling
```

Location remains unselected and untouched. A schema-free requested module may produce no direct scope while still installing any schema-owning dependencies in its plan.

## Selective module upgrades

`modules:migrate {module?}` uses the same dependency planner, shared migration repository, selected-path executor, and global lock as installation.

A targeted upgrade requires every schema-owning scope in the selected dependency closure to already have an `installed` ledger row. This requirement is preflighted for the complete plan before any scope enters `installing` state or any migration runs.

For each eligible installed scope, the executor:

```text
skips a current scope whose ledger contract is current
runs only the selected scope directory when manifest migrations are pending
refreshes schema_version and manifest_hash when the installed contract has drifted
preserves installed_at across upgrades and failures
updates last_migrated_at only when work or contract refresh occurs
records failed and stops later scopes when execution or verification fails
```

Omitting the module argument selects only module keys currently recorded as installed. It does not infer installation from available code, runtime enablement, provider loading, or current migration history.

For Scheduling, both targeted and bulk upgrade behavior can select:

```text
core
scheduling
```

Location remains untouched unless it has its own installed ledger row and is independently selected.

## Existing-database reconciliation

`modules:reconcile {module?}` records current module schemas in `module_installations` without invoking Laravel's migrator.

Targeted reconciliation resolves the requested dependency closure and preflights every schema-owning scope before writing any ledger rows. Each selected scope must already be migration-current. A partial, missing, failed, interrupted, or drifted selected scope rejects the operation.

A current untracked scope is recorded as installed with the current schema version and manifest hash. A current scope whose installed ledger contract is already current is a timestamp-preserving no-op. Reconciliation never repairs schema and never converts failed or installing state into installed state.

Bulk reconciliation inspects all registered module scopes and:

```text
rejects any partial scope before writing ledger rows
selects current scopes for dependency-ordered reconciliation
skips scopes with no recorded migrations, including absent optional vertical schema
leaves uninstalled modules untracked
```

This allows an existing non-Mortgage database built before the final path-selection cutover to adopt its current module manifests while leaving the absent Mortgage vertical uninstalled.

## Migration setup validation

`ModuleMigrationsSetupValidationContributor` is an app-level setup-validation contributor. It evaluates only:

```text
explicitly enabled modules
their dependency closure
schema-owning scopes within that closure
```

Schema-free modules do not create false installation requirements. A schema-free enabled module still requires any schema-owning dependencies to be installed and current.

For every selected schema scope, setup validation reports actionable errors for:

```text
missing Laravel migration repository
no recorded module migrations
partial module migration history
missing module_installations ledger
untracked module schema
interrupted installing state
failed installation state
installed schema-version or manifest-hash drift
```

A fully current and correctly tracked scope produces no migration finding.

Scheduling validation resolves:

```text
core
scheduling
```

Location is not selected unless independently enabled or required by another selected module. Migration validation is read-only and does not run migrations, reconcile ledger state, enable modules, or repair drift.

## New-client installation orchestration

`engage:install` is the client bootstrap entry point after client configuration has selected the intended runtime modules and preset package.

Supported forms are:

```text
php artisan engage:install
php artisan engage:install --modules=tasks,broadcasts,scheduling
php artisan engage:install --modules=scheduling --preset=basic
php artisan engage:install --create-user
php artisan engage:install --force --no-create-user
```

When `--modules` is omitted, the installer selects the schema-owning portion of the configured enabled-module dependency closure. Core is always included. When `--modules` is supplied, the comma-separated requested keys are dependency-planned through `ModuleMigrationPlanner`.

An explicit module selection may include additional available modules, but it may not omit a schema-owning module that current runtime configuration declares enabled or dependency-required. That mismatch is rejected before any database mutation. The installer never rewrites `config/modules.php`, client module configuration, or runtime enablement.

The four installation stages are:

```text
1. platform migrations
2. selected module installation
3. preset synchronization
4. setup validation
```

The platform stage invokes Laravel migration execution with the registered platform path explicitly, so the normal runtime path policy remains platform-only. The module stage delegates the complete dependency-ordered plan to `ModuleMigrationExecutor`; it does not reimplement migration locking, ledger transitions, selected-path execution, or verification.

Preset synchronization delegates to the existing `presets:sync` command. `--preset` is optional and, when supplied, is passed through as the preset package key. Setup validation delegates to the existing read-only `setup:validate` command.

After the four installation stages succeed, interactive installation may create the first CRM login user. `--create-user` requires that onboarding step and collects the password through hidden interactive input. `--no-create-user` skips onboarding and is the appropriate choice for non-interactive deployment automation or a controlled Project State rebuild where environment-owned users are recreated explicitly afterward. The two options are mutually exclusive. CRM user onboarding does not change module selection, migration execution, preset synchronization, or setup-validation semantics.

A non-zero result or exception from any stage stops the installer immediately. Later stages are not attempted. The operator receives the failed stage name and may rerun the same install command after correcting the reported problem. Rerun safety relies on the existing idempotent contracts:

```text
platform migrations
    Laravel skips recorded platform migrations

module installation
    current tracked scopes are no-ops and interrupted or partial scopes resume through the locked executor

preset synchronization
    preset-owned definitions use their existing sync ownership behavior

setup validation
    read-only
```

Installing Scheduling continues to resolve only:

```text
core
scheduling
```

Location is not installed unless independently requested or required by another configured module.

## Operator deployment workflow

The concise command authority is [`operations/deployment-command-workflow.md`](operations/deployment-command-workflow.md).

Use these top-level workflows:

```text
new client or new environment
    engage:install --force

existing installed client deployment
    migrate --force
    modules:migrate --force
    presets:sync when definitions changed
    modules:status
    setup:validate

add an optional module later
    migrate --force
    modules:install [module] --force
    presets:sync
    modules:status [module]
    setup:validate

existing pre-ledger schema
    modules:status
    modules:reconcile only when the selected scope is already current

controlled Project State clean rebuild
    migrate:fresh --force for the platform foundation
    engage:install --force for configured module schema, presets, and validation
    then follow the Project State validation/apply/resume runbook
```

After the final path-selection cutover, plain `migrate` and `migrate:fresh` do not reconstruct optional module schema. They operate on the platform migration path registered at runtime.

The repository's new-environment launch helper should call these existing commands rather than duplicate dependency planning, path selection, ledger transitions, preset composition, or validation logic. The current helper is `scripts/operations/launch-client-environment.sh`; `engage:install` remains the schema/preset/validation authority.

## Cross-thread deployment context dump

Run:

```bash
bash scripts/make-system-deployment-context-dump.sh
```

The output is:

```text
file_dumps/EngageCore_system_deployment_context_dump.txt
```

The dump intentionally combines:

```text
repository commit/branch identity
high-level resolved client/module configuration
relevant Artisan command inventory
read-only modules:status output
read-only setup:validate output
read-only schedule:list output
installation/migration command source
module migration registry/planner/executor/path policy source
module and migration configuration
canonical deployment, migration, troubleshooting, and Project State docs
related client/setup helper scripts when present
```

It deliberately does not read or append `.env` files, credentials, private keys, database rows, Redis contents, or Project State export payloads. Because client identifiers and validation diagnostics are useful context, review the generated dump before sharing it outside the project.

## Relocation and consolidation compatibility

Unchanged migrations preserve existing Laravel history through these rules:

```text
keep each migration basename unchanged
keep each migration's contents unchanged
move it to the registered owner path
remove the old root copy
continue using Laravel's one shared migrations table
```

Laravel records migration basenames rather than source directories. An already-recorded unchanged migration therefore remains complete after relocation and is not replayed from its new path.

Scheduling is explicitly pre-rollout, so its BookingHold chain is consolidated differently:

```text
nine unchanged Scheduling migrations keep their basenames and contents
BookingHold creation becomes the authoritative final clean-install schema
the later location-snapshot alteration migration is removed
fresh databases run ten Scheduling migrations
```

An existing development database that already ran the removed follow-up migration keeps its historical row in Laravel's `migrations` table and already has the two columns. Normal forward migration remains safe. Because the removed migration file no longer exists, do not use rollback as a preservation strategy for that pre-rollout development schema; use the project-state export/import and fresh-migration workflow when a reset is required.

Current relocation and path-selection contract tests prove:

- all registered platform files exist only in the platform directory;
- normal runtime startup policy selects only the platform migration path;
- the test bootstrap registers all seventeen schema-managed paths under `database/migrations/modules/`;
- the Mortgage vertical path remains outside the complete non-vertical test bootstrap;
- all registered module files exist only in their owner directories;
- no module-owned PHP migration remains in the legacy root;
- every registered basename is present in Laravel's migration repository on a fresh test schema;
- rerunning each relocated path creates no new migration records;
- BookingHold location snapshot columns exist from the authoritative creation migration;
- the removed Scheduling follow-up migration is absent from both the registry and filesystem.

## Current ownership contract

The registry classifies all 120 current migration files exactly once after the Media foundation migration is added.

Ownership totals:

```text
platform                      11
core                          11
relationships                  1
messaging                     14
inbound_messaging              8
internal_notifications         2
tasks                          3
scheduling                    11
portal                         4
forms                          4
documents                      4
media                          1
commerce                       5
location                       4
events                         2
workflow                       1
flow_routes                    9
campaigns                      8
broadcasts                     3
webinars                      10
reporting                      1
mortgage                       3
```

Scheduling remains schema version 2, but the module is still pre-rollout. The range-duration policy columns are therefore consolidated into the authoritative clean-install service migration:

```text
2026_04_15_195860_create_bookable_services_table.php
```

That create migration owns `duration_mode`, `minimum_duration_minutes`, and `maximum_duration_minutes`. The former `2026_08_10_040000_add_range_duration_policy_to_bookable_services.php` file is intentionally removed and must not remain in the migration manifest or be recreated merely for history symmetry. Disposable development databases created before this consolidation should use the approved clean-rebuild/install workflow rather than relying on `modules:migrate scheduling` to replay a migration that no longer exists.

Scheduling and Location remain independent optional schema scopes:

```text
Scheduling depends_on Core
Location depends_on Core
Scheduling does not depend_on Location
```

A future optional application-level bridge may combine their capabilities without changing module installation dependencies.

## Migration-history policy

Before first real rollout, a module migration chain may be consolidated into an authoritative clean-install schema.

After a migration has reached an environment whose data must be preserved:

```text
existing migration filename and behavior become immutable
future changes use append-only migrations
```

Moving an immutable migration to its owner directory must preserve its basename and contents so Laravel's shared `migrations` table continues recognizing it.

Do not create separate Laravel migration tables per module.

## Command status

Implemented commands:

```text
php artisan modules:status {module?}
php artisan modules:install {module} [--force]
php artisan modules:migrate {module?} [--force]
php artisan modules:reconcile {module?} [--force]
php artisan engage:install [--modules=...] [--preset=...] [--create-user|--no-create-user] [--force]
```

The optional `modules:status` argument limits inspection to that module's dependency-ordered migration plan. Omitting it inspects every registered schema-owning module.

The three module-mutating commands use production confirmation unless `--force` is supplied and delegate lock ownership, selected-path execution, verification, and ledger transitions to `ModuleMigrationExecutor`.

`modules:install` may install or adopt the requested closure. `modules:migrate` upgrades only already-installed scopes. `modules:reconcile` writes ledger state only for schema that is already current and never runs migrations.

`engage:install` is the new-client orchestration command. It confirms once in production, explicitly runs platform migrations, installs the selected module closure through `ModuleMigrationExecutor`, delegates preset materialization to `presets:sync`, and delegates final readiness checks to `setup:validate`.

`php artisan setup:validate` blocks readiness when enabled schema-owning modules or their schema-owning dependencies are not installed and current. Its findings direct operators to the appropriate platform migration, module installation, migration upgrade, or reconciliation command.

Installing Scheduling resolves:

```text
core
scheduling
```

It does not install Location.

## Testing policy

The platform path is active in every environment.

Normal runtime and deployment bootstrap registers only the platform path.
Optional module and vertical paths are not added to Laravel's global
migration-path list merely because their code exists or their runtime provider
is enabled.

Module operations use explicit selected paths:

```text
modules:install
    runs only the dependency-ordered schema scopes selected by the requested module

modules:migrate
    runs only selected scopes that are already ledger-installed

modules:reconcile
    runs no migrations
```

The test base class deliberately adds every registry-owned path under
`database/migrations/modules/` to Laravel's migrator after application
bootstrap. This happens before `RefreshDatabase`, preserving complete
non-vertical schema construction for the suite. Mortgage remains excluded
unless a test explicitly selects its vertical path.

Run automated tests with the ordinary command:

```bash
php artisan test
```

Do not require callers to prefix `APP_ENV=testing`. The Artisan test bootstrap
sets the testing environment before Laravel loads selected-client environment
values, so the outer Artisan process and the PHPUnit process use the same
testing boundary.

Every `php artisan test` process also acquires a non-blocking lock keyed to the
effective PHPUnit test database before Laravel boots. A second suite targeting
the same destructive test database must fail immediately rather than running
concurrently. This protects `RefreshDatabase`, installer tests, and other schema
operations from cross-process table drops/rebuilds. Separate suites may run
concurrently only when they use different test databases.

`scripts/run-tests-to-dump.sh` uses the same ordinary Artisan test path. It does
not maintain a separate `APP_ENV=testing` wrapper or a second shell-only
database lock.

Outside the testing environment, plain `php artisan migrate` and
`php artisan migrate:fresh` remain platform-only. New-client full setup uses
`engage:install`; later module schema creation and upgrades continue to use the
explicit module commands.

Permanent migration tests should prove current architecture and operator
behavior, not preserve one-time migration-history transitions indefinitely.

Keep durable coverage for:

```text
migration registry ownership and safe registered paths
runtime startup selecting only platform migrations
test bootstrap registering the complete non-vertical module schema
dependency planning and schema-free module behavior
read-only module status and manifest/pending-state inspection
module installation dependency closure and idempotence
failure stopping later installation scopes
interrupted installation recovery
global module-migration locking
current-schema reconciliation and absent-vertical handling
modules:migrate refusing untracked scopes
contract-drift ledger refresh without replaying current migrations
setup-validation handling of untracked, installing, failed, and drifted scopes
schema-free enabled modules producing no false migration findings
engage:install empty-database orchestration and configured-scope enforcement
preset/setup-validation failure boundaries during installation
```

Do not keep permanent tests whose only purpose is to:

```text
delete one named historical migration row and replay that migration
drop tables from one named historical migration to prove an old upgrade
prove a one-time migration relocation by rerunning moved paths
preserve a pre-rollout migration consolidation assertion
manufacture a historical partial state tied to one specific migration filename
```

Those checks may be useful while a migration reorganization is being developed
or rolled out, but they should be removed after the transition is established
unless the scenario represents a durable supported operator contract.

Use `RefreshDatabase` and normal transactional isolation for current-state
behavior whenever possible. Do not run `migrate:fresh` in every test setup merely
to manufacture a clean baseline. Explicit destructive schema rebuilding belongs
only in tests whose permanent contract genuinely requires it.

## Operational closeout and remaining follow-up

The modular migration workstream now has explicit ownership, installation state, selective execution, reconciliation, setup validation, runtime/test path separation, new-client orchestration, and an operator deployment workflow. The deployment-context dump provides a compact handoff artifact for future threads.

Client configuration generation or mutation remains separate from `engage:install`; the installer consumes configured runtime intent rather than rewriting it. Deployment helpers should orchestrate the documented commands rather than reimplementing module migration logic; `scripts/operations/launch-client-environment.sh` follows that boundary.

The known Messaging migration rollback-order defect remains a separate cleanup item. It does not block forward installation or deployment, but its `down()` path should be corrected before rollback behavior is treated as supported.