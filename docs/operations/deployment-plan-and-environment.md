# Deployment Plan and Environment Contract

Engage Core separates version-controlled client/product decisions from deployment-specific runtime values.

```text
Development
  client config + enabled modules + provider/capability selections
  -> test
  -> commit/push

Staging / production
  pull committed Core + client repositories
  -> resolve deployment plan
  -> reconcile only required runtime environment values
  -> install/migrate/sync
  -> validate/smoke
```

Staging and production are deployment targets. Do not edit source or client config there.

## `.env.example` is a catalog, not an install template

The root `.env.example` and `docs/config-templates/client-environment.example` document the complete supported environment vocabulary. They are intentionally broader than any single deployment.

Do **not** create a runtime environment with:

```bash
cp .env.example .env
cp client/example/.env.example client/example/.env
```

Doing so creates irrelevant values, hides module/provider ownership, and makes later environment drift harder to reason about.

## Resolve only what the committed build needs

Use:

```bash
php artisan engage:deployment-plan
```

The command is read-only. It resolves enabled modules and their registered deployment contributors, then reports required/defaulted/optional environment values without displaying secret contents.

Machine-readable output is available with:

```bash
php artisan engage:deployment-plan --json
```

A missing, blank, or persisted-identity-mismatched required value causes a non-zero exit status so deployment automation can stop before runtime work continues. For example, an explicitly selected `CLIENT_KEY` cannot silently disagree with the value persisted in root `.env`.

## Add only missing required variable names

Use:

```bash
php artisan engage:environment:sync --write-missing
```

The synchronizer:

- writes a missing key only to its catalog-owned root or selected-client environment file;
- never overwrites an existing value;
- never removes unused values automatically;
- never invents secret values;
- creates new environment files as `0640`;
- preserves an existing file's mode;
- writes the active `CLIENT_KEY` when it is already known non-secret runtime state.

After synchronization, populate blank required values. If `APP_KEY` is blank, generate it with:

```bash
php artisan key:generate
```

Then clear cached configuration when appropriate and rerun:

```bash
php artisan engage:deployment-plan
```

## Environment ownership versus deployment necessity

`EnvironmentVariableCatalog` is bootstrap-safe and answers:

- whether Engage Core recognizes a key;
- whether it belongs in root `.env` or `client/[CLIENT_KEY]/.env`;
- which subsystem owns the key;
- whether the value is sensitive.

Deployment contributors answer a different question:

> Does this committed client build actually need this variable?

That distinction is required because selected-client `.env` loading happens before Laravel service providers and module contributor tags are available.

## Adding a module

Module/config changes are authored in development and committed. A staging or production deployment must not modify `client/[CLIENT_KEY]/config/modules.php` to make the target work.

After a commit adds a module, the target resolves the new deployment plan. New runtime obligations appear as a delta. The operator fills only those new values and reruns the plan before installation/verification continues.

## Unused values

The plan may report present-but-unused keys, but only for subsystem owners whose deployment contributor coverage is active. This prevents false positives while deployment contributors are introduced module-by-module.

Unused values are informational. They are never deleted automatically.

## Client environment loader

`ClientEnvironmentLoader` uses the same bootstrap-safe catalog to enforce client ownership. It clears every legal client-owned value before applying the selected client's `.env`, including when that `.env` file does not yet exist. This prevents stale root or previously selected client values from leaking across clients.

## Current contributor coverage

The foundation begins with:

- Core
- Forms

Forms also closes one important ambiguity in the current runtime model: when the committed preset selects one or more public Forms, `FORMS_EXTERNAL_INTAKE_ENABLED` becomes an explicit required deployment decision. A fresh target therefore cannot silently treat missing configuration as "disabled" and skip the signing-credential requirements. Once that value is deliberately set to `true`, the Forms contributor exposes the caller identity, signing secret, source/provider, and allowed-form requirements.

Additional module/provider contributors should be added from fresh dependency cones as their deployment requirements are implemented. The Bash launcher should not encode those requirements itself.