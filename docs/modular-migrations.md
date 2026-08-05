# Modular Migration Architecture

## Status

The ownership registry foundation is implemented.

This phase does not move migration files, register new migration paths, create an installation ledger, or change migration execution. Existing Laravel migration behavior remains unchanged until later cutover batches.

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
    future repository-relative migration directory

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
reporting
```

## Target directory layout

```text
database/migrations/platform/
database/migrations/modules/core/
database/migrations/modules/{module-key}/
database/migrations/verticals/{vertical-key}/
```

The Mortgage vertical retains:

```text
database/migrations/verticals/mortgage/
```

Migration paths are discovered by future app-level installation infrastructure, not by requiring a runtime module provider to be enabled first.

## Current ownership contract

The registry classifies all 94 current migration files exactly once.

Ownership totals:

```text
platform                      10
core                           6
messaging                     12
inbound_messaging              2
internal_notifications         2
tasks                          3
scheduling                    11
portal                         4
forms                          4
documents                      4
commerce                       5
location                       4
events                         2
workflow                       1
flow_routes                    9
campaigns                      3
broadcasts                     2
webinars                       8
mortgage                       2
```

Scheduling and Location remain independent optional schema scopes:

```text
Scheduling depends_on Core
Location depends_on Core
Scheduling does not depend_on Location
```

A future optional application-level bridge may combine their capabilities without changing module installation dependencies.

## Registry runtime boundary

`ModuleMigrationRegistry` currently provides validated metadata only.

It validates:

- platform and module definition shape;
- known module keys;
- normalized repository-relative target paths;
- positive schema versions;
- valid migration basenames;
- unique target paths;
- exactly one owner per configured migration basename.

It does not currently:

- call Laravel's migrator;
- register migration paths;
- inspect the database migration repository;
- decide whether a module is installed;
- enable or disable modules;
- create directories;
- move migration files.

## Migration-history policy

Before first real rollout, a module migration chain may be consolidated into an authoritative clean-install schema.

After a migration has reached an environment whose data must be preserved:

```text
existing migration filename and behavior become immutable
future changes use append-only migrations
```

Moving an immutable migration to its owner directory must preserve its basename and contents so Laravel's shared `migrations` table continues recognizing it.

Do not create separate Laravel migration tables per module.

## Planned commands

These commands are architectural targets and are not implemented by the registry foundation:

```text
php artisan modules:install {module}
php artisan modules:migrate {module?}
php artisan modules:status
php artisan modules:reconcile
php artisan engage:install --modules=...
```

The future coordinator must resolve dependency order from `ModuleManager`, run only selected paths, use one Laravel migration repository, lock installation work, and record module-level installation state.

Installing Scheduling must resolve:

```text
core
scheduling
```

It must not install Location.

## Testing policy

Production and test migration discovery have different needs.

Future test bootstrap may register platform and all module migration paths so `RefreshDatabase` can build the complete test schema.

Production runtime must not register every optional path merely because module code exists.

The current registry contract test scans the existing migration tree and proves every PHP migration basename matches exactly one registry owner. The test remains valid while files move because ownership is based on immutable migration basenames rather than current directories.

## Next implementation slice

The next batch introduces the platform migration path and module installation ledger while retaining a safe compatibility path for existing root migrations.