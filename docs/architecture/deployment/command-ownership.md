# Engage Core — Deployment Command Ownership

## Purpose

This maintainer-facing document defines what each deployment-related Artisan command owns.

It intentionally does not repeat the complete operator workflow. Operators should use:

```text
docs/operations/deployment-runbook.md
```

## Source/runtime boundary

Normal application bootstrap does not run migrations.

After the modular migration path-selection cutover:

```text
normal runtime migration registration
    platform path only

optional module schema
    selected explicitly by module installation/migration commands
```

Runtime module enablement, provider loading, and migration-directory existence are not substitutes for installation-ledger state.

## Command ownership

### `engage:deployment-plan`

```text
read-only
resolves environment/runtime requirements for the configured client/module set
reports blocking names/reasons without exposing secret values
contributes external/provider setup steps
does not mutate environment files or infrastructure
```

### `engage:environment:sync --write-missing`

```text
adds missing required variable names only
does not invent values
does not overwrite existing values
does not delete values
```

### `engage:install`

New-client/new-environment application installer.

Owns these stages:

```text
1. deployment preflight
2. platform migrations
3. configured schema-owning module installation
4. preset synchronization
5. setup validation
6. optional interactive CRM-user creation after successful installation
```

`--no-create-user` is the non-interactive/controlled-rebuild choice when the environment-owned CRM user is created separately.

An explicit `--modules=` selection may add schema scope, but may not omit configured enabled schema required by the runtime deployment plan.

### `migrate --force`

```text
platform migrations only
```

Do not treat it as an all-module migration command.

### `modules:install <module> --force`

```text
installs the requested module's schema-owning dependency closure
uses registered migration manifests
adopts an already-current untracked scope through the normal executor
runs only required pending registered migrations
does not install unrelated optional modules
```

### `modules:migrate --force`

```text
upgrades every ledger-installed module scope
does not install arbitrary enabled-but-uninstalled optional modules
```

### `modules:migrate <module> --force`

```text
upgrades one installed module dependency closure
```

### `modules:reconcile <module> --force`

```text
adopts already-current schema into the module installation ledger
runs no migrations
must not be used to conceal partial or missing schema
```

### `modules:status [module]`

```text
read-only
inspects migration files, ledger state, schema version, and manifest identity
```

### `presets:sync`

```text
materializes configured DB-owned definitions
is not schema migration
may create/update preset-owned records according to ownership contracts
does not imply that every contributed definition is activated for runtime use
```

### `setup:validate`

```text
read-only readiness gate
validates current schema/config/provider/runtime contracts represented by contributors
errors block handoff
warnings require deliberate review
```

### `engage:user:add`

```text
creates an operational CRM login through hidden password input
user credentials are not environment configuration
```

### `engage:user:password <email>`

```text
explicitly resets one CRM login password through hidden input
```

## Pre-ledger databases

Reconciliation is valid only when the selected schema is already fully current.

For mixed existing states:

```text
current
partial
not_migrated
```

do not bulk-reconcile.

The safe executor path is:

```text
run platform migration so module_installations exists
use modules:install for enabled schema scopes that require installation/adoption
allow the normal executor to adopt current scopes
allow the normal executor to run registered pending migrations for partial/not_migrated scopes
ignore disabled optional scopes
```

The audit/fix path automates this decision and must never edit installation-ledger rows directly.

## Module-specific post-install registry

Only commands not already owned by `engage:install` or `presets:sync` belong here.

### Forms external-intake credential issuance

Condition:

```text
Forms enabled
server-to-server external intake configured
valid environment-specific client ID/signing-secret pair not yet installed
```

Command:

```bash
php artisan forms:external-intake:issue-secret [client]
```

The command prints matching Core/caller environment blocks and does not mutate either environment.

After installing values:

```bash
php artisan optimize:clear
php artisan setup:validate
```

No other current module requires a mandatory module-specific post-install command beyond the normal install/preset lifecycle.

## Failure semantics

```text
platform migration failure
    correct the database/migration problem and rerun

module installation failure
    inspect modules:status, correct the registered schema/path issue, rerun the installer

module upgrade failure
    inspect modules:status, correct the affected installed scope, rerun modules:migrate

preset synchronization failure
    correct preset/config ownership/contracts, rerun presets:sync or the owning installer stage

setup validation failure
    correct the reported readiness issue; do not bypass it

current schema with no ledger row
    reconcile only after status proves the scope is fully current
```

Never mark ledger rows manually merely to clear an error.

Never run optional module migration directories with broad ad-hoc `migrate --path` commands.

## Launcher integration contract

The host-side launcher orchestrates these commands. It does not replace their ownership.

The launcher must:

```text
derive runtime identity and requirements
use deployment-plan output rather than duplicate application logic
call the existing schema/preset/validation commands
pause for external/operator-owned work
audit after mutation
```

See:

```text
docs/architecture/deployment/deployment-orchestrator-contract.md
docs/architecture/deployment/deployment-plan-and-environment.md
docs/operations/deployment-runbook.md
```