
# Engage Core — Deployment Command Workflow

## Purpose

This is the concise command-level authority for installing and upgrading Engage Core after the modular migration path-selection cutover.

Use it alongside:

- `client-staging-production-setup-checklist.md` for full server/client rollout steps;
- `deployment-safety-and-troubleshooting.md` for worker, Redis, reset, and incident safety;
- `project-state-transfer-runbook.md` for an approved Project State clean rebuild.

Normal application startup never runs migrations. Normal runtime bootstrap registers only the platform migration path. Optional module schema is selected explicitly by the module migration commands.

## Command ownership

```text
php artisan engage:install
    new-client/new-environment orchestration: platform + selected modules + presets + validation

php artisan migrate --force
    platform migrations only

php artisan modules:install [module] --force
    install one requested module dependency closure, including missing schema-owning dependencies

php artisan modules:migrate --force
    upgrade every ledger-installed module scope

php artisan modules:migrate [module] --force
    upgrade one installed module dependency closure

php artisan modules:reconcile [module] --force
    adopt already-current existing schema into the module installation ledger; runs no migrations

php artisan modules:status [module]
    read-only migration/ledger/manifest inspection

php artisan presets:sync
    materialize configured DB-owned definitions

php artisan setup:validate
    read-only readiness gate

php artisan engage:user:add
    create the first or an additional CRM login user through hidden password input

php artisan engage:user:password [email]
    explicitly reset an existing CRM login password through hidden password input
```

Do not use runtime module enablement, provider loading, or directory existence as a substitute for module installation state.

## Module-specific post-install command registry

This registry contains only enabled-module setup commands that are not already owned by `engage:install` or `presets:sync`. Run an entry only when its stated capability is configured for the environment. Future required module-owned setup commands must be added here and linked from the owning module/operations documentation.

### Forms — external-intake credential issuance

Condition: Forms is enabled for a server-to-server external intake client and the current environment does not already have its valid client ID/signing-secret pair.

```bash
php artisan forms:external-intake:issue-secret [client]
```

The optional client argument may be omitted when exactly one external intake client is configured. The command prints matching Engage Core and external-caller environment blocks without mutating either environment. Issue separate staging and production credentials, install each block in its matching environment, run `php artisan optimize:clear` in both applications, and rerun `php artisan setup:validate` in Core.

This command is safe to run when the current configured secret is blank or invalid. It may therefore follow an `engage:install` run whose final setup-validation stage reported the incomplete Forms external-client configuration.

No other current module requires a mandatory module-specific post-install Artisan command beyond `engage:install` and `presets:sync`.

## New client or new environment

After the intended client configuration, environment variables, dependencies, and assets are in place:

```bash
cd [APP_PATH]
php artisan optimize:clear
php artisan engage:install --force

# Run every applicable entry from the module-specific post-install registry.
# If an entry changes environment values, clear cached configuration again.

php artisan modules:status
php artisan setup:validate
```

`engage:install` defaults to the schema-owning portion of the configured enabled-module dependency closure and always includes Core. Use `--modules=` only when there is a deliberate reason to name an explicit superset; the installer rejects a selection that omits configured enabled schema.

The installer does not rewrite client module configuration. Correct configuration first, then rerun the same command. Completed stages are designed to be idempotent.

Interactive `engage:install` offers to create the first CRM user after the four installation stages complete. Automated deployments should use `--no-create-user` and create the login afterward from an interactive operator session:

```bash
php artisan engage:user:add
```

Do not store CRM login passwords in `.env` or pass them through deployment command arguments.

## Normal deployment to an existing client

For an already-installed database with real data:

```bash
cd [APP_PATH]
php artisan optimize:clear
php artisan migrate --force
php artisan modules:migrate --force
php artisan presets:sync
php artisan modules:status
php artisan setup:validate
```

Run `presets:sync` when preset/config definitions changed. It is safe to run as a deployment gate, but it should not be mistaken for schema migration.

If queued-job runtime code changed, restart Horizon using the actual Supervisor program after the code/schema/config steps and verify the live process path.

## Adding a module to an existing client

First deploy the client configuration that intentionally enables the module. Then install that module explicitly:

```bash
cd [APP_PATH]
php artisan optimize:clear
php artisan migrate --force
php artisan modules:install [module] --force
php artisan presets:sync
php artisan modules:status [module]
php artisan setup:validate
```

`modules:install` resolves schema-owning dependencies first. It does not install unrelated optional modules. For example, Scheduling resolves to Core + Scheduling and does not install Location.

## Upgrading one installed module

Use the targeted upgrade when only one installed dependency closure should be considered:

```bash
php artisan migrate --force
php artisan modules:migrate [module] --force
php artisan modules:status [module]
php artisan setup:validate
```

The platform migration step remains separate because platform schema is not a module scope.

## Existing database created before the installation ledger

Use reconciliation only when the database already contains the complete current migration history for the scope. Reconciliation does not repair or create schema.

```bash
php artisan modules:status [module]
php artisan modules:reconcile [module] --force
php artisan modules:status [module]
php artisan setup:validate
```

Bulk `modules:reconcile --force` may be used for a known existing database only after status has been reviewed. Partial scopes block reconciliation.

## Controlled Project State clean rebuild

Project State is not a normal deployment mechanism. Follow `project-state-transfer-runbook.md` for backup, write freeze, Redis cleanup, export, import, resume, and provider reconciliation.

At the schema-rebuild point, the command boundary is:

```bash
php artisan migrate:fresh --force
php artisan engage:install --force --no-create-user
php artisan modules:status
php artisan engage:user:add
```

After the path-selection cutover, `migrate:fresh` reconstructs the platform foundation only. `engage:install` then installs the configured module schema, materializes presets, and runs setup validation before the Project State file is validated/applied. `--no-create-user` keeps the environment-owned CRM user outside the imported application state; recreate the intended owner explicitly with `engage:user:add`.

Keep Horizon and the Laravel Scheduler stopped through validation and apply. Resume imported work only when the import actually creates pending resume items and the operator deliberately releases them.

Never use this destructive sequence as a routine production deployment.

## Failure recovery

```text
platform migration failure
    correct the platform migration/database issue, then rerun the same command

module installation failure
    inspect modules:status; correct the failing migration/path/schema issue; rerun modules:install or engage:install

module upgrade failure
    inspect modules:status; correct the failing installed scope; rerun modules:migrate

preset synchronization failure
    correct preset/config contracts; rerun presets:sync or the same engage:install command

setup validation failure
    do not bypass the finding; correct config/schema/provider readiness, then rerun setup:validate

interrupted installing ledger state
    rerun modules:install for that scope; the executor verifies/resumes the selected closure

current schema with no ledger row
    use modules:reconcile only after status proves the scope is current
```

Do not mark ledger rows manually merely to clear an error. Do not run optional module directories directly with broad `migrate --path` commands.

## Deployment-script integration contract

A future automated deploy script should orchestrate these commands rather than duplicate their internals. In particular, it should not implement its own module dependency traversal, migration path discovery, installation-ledger writes, preset composition, or validation rules.

The normal existing-client deployment integration points are:

```text
checkout/dependencies/assets/environment
    -> optimize:clear
    -> migrate --force
    -> modules:migrate --force
    -> presets:sync when definitions changed
    -> modules:status
    -> setup:validate
    -> Horizon/Scheduler/process verification as applicable
    -> production-safe smoke checks
```

The new-client integration point is `engage:install --force` after configuration and dependencies are complete.

## Cross-thread deployment context dump

Run:

```bash
bash scripts/make-system-deployment-context-dump.sh
```

The script writes:

```text
file_dumps/EngageCore_system_deployment_context_dump.txt
```

The dump is designed to be attached to another implementation/deployment thread. It includes safe repository identity, selected high-level client/module configuration, read-only module/validation output, the relevant command/migration/config source files, and the canonical deployment/project-state documentation.

The script deliberately does not read or append `.env` files, credentials, private keys, database rows, Redis contents, or Project State export payloads. Review any generated diagnostic output before sharing because client identifiers and validation messages are intentionally included.