# Engage Core Bash / Runtime Authority Audit

Basis: current scripts supplied with the August 27, 2026 Core dependency/config material.

## Principle

Bash may orchestrate repository/host operations, but it should not become a second authority for:

- module dependency resolution;
- module enablement semantics;
- selected-client environment ownership;
- provider-specific runtime requirements;
- setup-validation rules;
- migration scope ownership.

Those belong in Core/PHP contracts that Bash can invoke.

## `scripts/create-client.sh`

**Current issue:** the script embeds a large client `.env.example` and tells the operator to copy it wholesale into a real `.env`.

**This batch:** fixes the environment behavior. The script now copies the canonical *reference* template only to the new repo's `.env.example`, never to `.env`, and points the operator to `engage:environment:sync --write-missing`.

**Remaining follow-up:** client scaffold/module defaults are still authored directly by Bash. That is acceptable as a development-only tool for now, but a later `engage:client:create`/similar Core command would remove the remaining PHP-config-generation duplication.

## `scripts/add-client-modules.sh`

**Assessment:** high application-authority duplication.

The script currently boots Core but still contains its own module-selection/write workflow, rewrites `client/<key>/config/modules.php`, manages rollback, and then invokes setup validation.

**Recommended follow-up:** move the actual authoring operation into a development-only Artisan command. Preserve the good atomic-write and rollback semantics in PHP. Keep Bash, if desired, only as a tiny convenience wrapper.

A future module-authoring command should show the resulting deployment-plan delta but must not write staging/production environment values.

## `scripts/audit-db-bloat.sh`

**Assessment:** valuable specialized audit with duplicated module-resolution machinery.

Keep the database/storage audit itself. Later replace its private `config/modules.php` dependency-closure implementation with a Core machine-readable module-resolution command/service.

This is lower urgency than runtime deployment authority because the audit is read-only developer tooling.

## `scripts/dev-reset-test-client-database.sh`

**Assessment:** thin-wrapper candidate, not pure duplication.

`engage:refresh` already owns the destructive local/testing-only wipe + reinstall workflow. The Bash helper adds one useful extra safety constraint: it refuses to run unless the selected client is `test-client`.

**Recommended follow-up:** retain the test-client-specific guard if desired, but reduce the destructive implementation to a wrapper around `php artisan engage:refresh` rather than separately invoking `db:wipe` and instructing the user to install afterward.

## `scripts/dump-all-config.sh`

**Assessment:** keep.

This is collection tooling, not runtime authority. No material architectural redundancy found.

## `scripts/dump-client-files.sh`

**Assessment:** keep.

This is focused collection tooling and remains useful for client-repo review/handoffs.

## `scripts/dump-integration-files.sh`

**Assessment:** keep.

This is focused integration evidence collection. Its evidence-only scan is intentionally not a runtime dependency resolver.

## `scripts/process-images.sh`

**Assessment:** keep as development tooling, but stop sourcing `.env` directly in a future cleanup.

The script currently sources root `.env` only to resolve `CLIENT_KEY`. It should eventually accept an explicit client argument or query Core for the selected client instead of treating `.env` as a shell program.

Its image transformation/manifest workflow is otherwise specialized and not duplicated by the deployment-plan foundation.

## `scripts/upload-images.sh`

**Assessment:** strong refactor target.

The script:

- sources root and client `.env` files directly;
- maintains a private required-variable list for Spaces/CDN configuration;
- passes storage credentials on the `s3cmd` command line.

That duplicates environment-contract knowledge and puts secrets into process arguments.

**Recommended follow-up:** replace the upload operation with an Artisan command that uses Laravel's configured filesystem disk. The deployment/environment system can then own readiness, while the upload command consumes already-resolved application configuration without re-parsing `.env`.

## Dependency-cone scripts

**Assessment:** keep now; consolidate later.

Several dependency-cone/audit scripts independently calculate module dependency closure from `config/modules.php`. Because they are offline/read-only developer tooling, this is not a deployment correctness blocker.

Once Core exposes a stable machine-readable module-resolution command/service, migrate these scripts to consume it so dependency semantics exist in one place.

## Prototype `scripts/operations/launch-client-environment.sh`

The earlier prototype was intentionally not applied. Do not promote it to canonical status.

Rebuild the launcher after deployment-plan contributor coverage is sufficiently complete. The final launcher should be deliberately boring:

```text
verify repos/host
-> pull committed Core + client changes
-> ask Core for deployment plan
-> optionally sync missing key names
-> stop for unresolved values/secrets
-> run existing install/migrate/sync commands
-> configure generic host process infrastructure
-> run Core-provided verification/smokes
```

It should contain no module/provider matrix.