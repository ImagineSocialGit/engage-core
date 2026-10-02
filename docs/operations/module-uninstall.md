# Module Uninstall

`modules:uninstall` is the destructive inverse of module schema installation for a module that should return to a clean, non-migrated state.

Use it only when module-owned data may be destroyed intentionally.

```bash
php artisan modules:uninstall scheduling --force
```

## Required state

Before uninstalling a module:

1. remove the module from the selected client's enabled module configuration and deploy that source/configuration change normally;
2. clear cached configuration on the target environment;
3. verify no enabled module requires the target module;
4. verify no installed module schema depends on the target module;
5. take a database backup;
6. separately verify any application-level references that the generic module migration layer cannot discover, such as polymorphic references into the module's domain records.

The command refuses Core and other always-on modules.

The command also refuses a module that remains in the effective runtime-enabled dependency closure or has another ledger-installed module depending on it.

## What the command removes

The command resolves the target's registered module migration scope and uses Laravel's shared Migrator to reset only that path.

Laravel runs the applied migrations found in the selected path in reverse migration order. Each successful `down()` removes its row from the shared `migrations` repository.

After every migration in the target scope is no longer recorded as applied, Engage Core removes that module's `module_installations` row.

The desired terminal state is:

```text
module migration rows: none
module_installations row: none
module schema: removed by the migration down() methods
runtime module: disabled by client configuration
```

A later `modules:install <module> --force` therefore performs a normal clean installation from the then-current authoritative module schema.

## Locking and interrupted uninstall

Uninstall shares the same global module-migration lock as install, migrate, and reconcile. Two module schema mutations cannot intentionally run at the same time.

MySQL DDL is not transactionally atomic. A migration `down()` may commit schema changes before a later rollback fails.

For that reason, the installation-ledger row is preserved until every applied migration in the selected scope has rolled back successfully. If rollback fails partway through, inspect:

```bash
php artisan modules:status <module>
```

Correct the rollback problem and rerun the uninstall. Laravel's migration repository records which `down()` operations already completed, so the next reset operates on the remaining applied migrations.

## Migration integrity

By default, uninstall refuses when an already-applied migration file differs from the checksum accepted when the module was installed. Current `down()` behavior may no longer match the code that originally created the schema.

For an intentionally disposable or pre-rollout module, an operator may explicitly accept that risk after taking a backup:

```bash
php artisan modules:uninstall scheduling \
  --force \
  --allow-applied-drift
```

`--allow-applied-drift` bypasses only changed checksums for migration files that are still present and already applied.

It does **not** bypass:

- missing previously accepted migration files;
- applied migration files absent from the accepted checksum baseline;
- missing Laravel migration history or module installation infrastructure;
- runtime enablement;
- installed module dependents.

Those states require repair rather than guessing through a destructive operation.

## Scope boundary

`modules:uninstall` owns module migration state. It does not edit selected-client `modules.enabled`, remove Composer packages, delete module configuration files, purge provider credentials, or infer arbitrary polymorphic/application references.

Package-contributed module migration scopes use the same command. Their selected-client vendor migration directory remains the registered scope, so the package must still be installed and available while its schema is being uninstalled.