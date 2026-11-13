# Core selected-client Composer package revisions

This local workflow extends `scripts/package.sh`. It does not own package
registration, client setup, module enablement, credential provisioning, or
remote deployment. It operates on an existing Core client's own `composer.json`,
`composer.lock`, `vendor/`, and Composer-installed package metadata.

## Invariants

- `--plan` is read-only. It does not select a client, invoke Composer, alter
  `.env`, contact GitHub, update a lockfile, or repair vendor files.
- `--apply` requires the exact fingerprint from a reviewed plan. It rechecks
  the source files, selected-client identity, installed revision, and local
  environment before changing Composer artifacts.
- An **update** advances one already-required package using targeted Composer
  dependency resolution. Any change to another locked package fails and
  triggers attempted restoration of the original lock and vendor install.
- A **repair** reinstalls from the existing lockfile; it cannot change package
  requirements or advance package revisions.
- Neither operation changes the client package registry, generated PHP
  manifest, module activation, or provider credentials. Manually maintained
  manifests are preserved. Use the existing managed install and explicit
  metadata-sync workflow for declared registration changes.
- The selected package must not be symlinked. First restore Composer management
  using `./scripts/package.sh unlink CLIENT_KEY vendor/package` (local only).
  The operation refuses modified tracked files in an installed source checkout.
- For a clean Composer-managed Git source checkout, a missing `composer` remote
  is restored from **the currently reviewed `composer.lock` source URL** during
  approved apply. A conflicting existing remote is rejected, not overwritten.
- The client Composer manifest must remain unchanged. A successful update must
  match the new lock reference to the installed Git HEAD or Composer's installed
  package reference. In a dist install, installed.json is provenance metadata;
  it does **not** independently attest every extracted PHP file's bytes.
- This is **local-only**. Do not run it directly on staging or production.
  Remote source releases must be deployed from approved immutable commits and
  locked client dependencies through Infrastructure.

## Update an already installed package

Start in `/var/www/engage-core` with `APP_ENV=local` and the requested client
selected by the root `CLIENT_KEY`. The package must be published and accessible
to Composer. An example using arbitrary identifiers:

```bash
./scripts/package.sh update client-one-core imagine-social/engage-example --plan --json
```

Review the installed state and plan fingerprint. If the selected package is
linked, restore its locked installation before planning the update.

```bash
./scripts/package.sh update client-one-core imagine-social/engage-example \
  --apply --confirm-plan REVIEWED_FINGERPRINT --json
```

After a successful update, inspect the selected client's `composer.lock` diff,
run the package's standalone tests with `./scripts/test-package.sh`, run the
appropriate consuming-host integration tests, and commit the client lockfile
separately from the package source repository. The update does not push code,
change module activation, or automatically run migrations.

## Recover an interrupted source install or missing Composer remote

Use the same entrypoint, selecting `repair` rather than `update`:

```bash
./scripts/package.sh repair client-one-core imagine-social/engage-example --plan --json
./scripts/package.sh repair client-one-core imagine-social/engage-example \
  --apply --confirm-plan REVIEWED_REPAIR_FINGERPRINT --json
```

Repair reads the committed lock reference and reinstalls **exactly that
revision**. The plan accepts a missing or mismatched installation, but refuses
unsafe symlinks, modified tracked package files, and a conflicting Git remote.
A repaired install must match the original lockfile and installed revision.

If an update fails, the operation tries to restore the original lockfile and
Composer-installed vendor state. It still returns failure even if the rollback
succeeds; review the error and re-plan. If recovery cannot prove the installed
revision matches the restored lock, stop. Do not commit a partial update, edit
PHP files in `vendor`, or treat the update as successful. Resolve the indicated
Composer/network/permissions problem and run a fresh reviewed `repair` plan.

## Ownership and limitations

The package checkout owns published package source. The **selected client**
owns its Composer manifest, lockfile, and managed package declarations.
`package.sh install` registers packages and optionally synchronizes package
metadata, but is not a version-update operation. `package.sh update` and
`package.sh repair` handle the revision stage for an existing direct Core
package dependency only. They do not replace the SEO/Artist package lifecycle,
provide a dependency solver themselves, or guarantee application-level
compatibility. Host-facing integration tests remain necessary.