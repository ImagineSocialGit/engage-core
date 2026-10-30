# Private Package Workflow — Engage Core

## Purpose

This is the **local package creation and selected-client installation procedure** for new Engage Core private Composer packages. The single operator entry point is `scripts/package.sh`; it delegates development symlinks to the existing `dev-link-package.sh` and `dev-unlink-package.sh`. Package architecture and deployment authority remain in `docs/architecture/client-packages.md` and `docs/operations/deployment-runbook.md`.

Package repositories are separate from the Engage Core platform and selected-client repositories. Their **local directory basename, GitHub repository basename, and Composer package suffix must match**. The package vendor remains `imagine-social`; default GitHub owner is `ImagineSocialGit`. These identifiers are not the Core or SEO client key.

## Supported package shapes

| Type | Example | Generated runtime seam |
| --- | --- | --- |
| `integration` | `engage-integration-stripe` | Always-loaded integration service provider; no module |
| `vertical` | `engage-donations` | Optional module definition, module provider and migration directory |
| `library` | `engage-qr-rendering` | Composer autoload only; opt-in providers can be authored deliberately |

The scaffolder generates minimal valid source structure, not invented integrations, routes, schema, business features or runtime tests. The integration provider is an empty registration shell. A vertical's module is **available** when installed but is **not enabled** until selected by its client. Implement and validate the first vertical migration before enabling it. Generated PHP uses the `ImagineSocial\\...` PSR-4 namespace and does not install another Laravel runtime. The host supplies Laravel and public Core contracts.

## 1. Create a private package

From the **development** Core checkout:

```bash
cd /var/www/engage-core

./scripts/package.sh create engage-integration-stripe \
  --type integration \
  --description 'Stripe billing collection adapter for Engage Core' \
  --dry-run

./scripts/package.sh create engage-integration-stripe \
  --type integration \
  --description 'Stripe billing collection adapter for Engage Core'
```

For Donations:

```bash
./scripts/package.sh create engage-donations \
  --type vertical \
  --description 'Reusable nonprofit fundraising vertical for Engage Core'
```

For a provider-neutral utility, use `--type library`. The default package checkout location is `/var/www/<repository-basename>`, a sibling of `/var/www/engage-core`. If necessary, `--parent /alternative/parent` selects a different location; it does **not** rename the repository or package. `--owner` overrides the GitHub organization; `--dry-run` creates nothing.

Creation requires local Git identity, authenticated `gh`, and an available private GitHub repository name. When the default parent (`/var/www` on the usual workstation) is not writable by the developer, the command uses `sudo` **only** to run `mkdir -m 0700` for the exact package target and `chown` that new directory to the invoking user/group. All subsequent Git, GitHub, and file operations run as the developer, never as root. The command must not change permissions/ownership of `/var/www` or recursively chown application files. Privileged creation is limited to the Core checkout's default sibling directory; an alternate `--parent` must already be writable.

On a writable parent, the tool stages atomically and publishes on successful push. On the default non-writable parent, it builds directly inside the newly owned directory and removes it if creation fails before GitHub creation. If a private remote was created but push failed, the complete local checkout remains for explicit repair. Do not run `create` again against an existing repository.

Inspect `composer.json`, `engage-package.json`, source files, private GitHub repository and tracking branch. Do not add host `laravel/framework` or `illuminate/*` to runtime Composer dependencies.

## 2. Install into a selected Core client

Ensure the client already exists, `APP_ENV=local`, and root `CLIENT_KEY` selects the intended client. Do **not** edit root `composer.json` for a selected-client-only package.

```bash
./scripts/package.sh install holly-hatcher-ef-core \
  /var/www/engage-integration-stripe --dry-run

./scripts/package.sh install holly-hatcher-ef-core \
  /var/www/engage-integration-stripe --link
```

The installation operation:

1. Validates package naming, descriptor, PSR-4 mapping, source repository and remote `main` accessibility.
2. Creates/updates the selected client's nested `composer.json` with an explicit private VCS repository and `dev-main` requirement. Uses Composer **inside** that client to update the selected package and generate the new `composer.lock`, **then** validates `composer.json` and the updated lockfile together. Composer validation must not run between adding a new requirement and updating the lock: an existing lock is necessarily stale at that point. The package must already be pushed to the private remote.
3. Creates `config/client_package_registry.json` as structured, committed registration input and generates `config/client_packages.php`. It registers integration providers under `providers`, vertical modules under `modules`, **migration scopes only after explicitly enabled**, and environment key **definitions** under `environment`. It never inserts secret values. Fresh vertical scaffolds carry `"migration_scope": false` and may be installed without fake migrations.
4. Adds `/vendor/` to the selected client's `.gitignore` if missing. The client repository must never contain installed dependency binaries/source.
5. Verifies the Composer installation and class autoloading. `--link` then invokes the existing local symlink helper, leaving `composer.json`/`composer.lock` unchanged. For a registry-only `--sync-metadata` change where Composer requirements and lock are already valid, the installer updates only the managed registry/manifest and preserves an existing matching local symlink; it does not run `composer update`.
6. Enforces the shared **local** permission policy before linking or reporting success. Only the installer-managed **runtime configuration files** (`config/client_package_registry.json` and `config/client_packages.php`) are assigned the PHP-FPM group and mode `0640`; `config/` is assigned the PHP-FPM group with mode `2750`. The selected-client Git checkout root is **access-checked but never chgrp'd or chmod'd** by the helper. Client `composer.json` and `composer.lock` are build-time source files; they are neither changed nor required to be PHP-FPM-readable. All client configuration is checked under the actual PHP-FPM identity. An unrelated unreadable config file causes a path-specific error, without rewriting unrelated settings. Existing managed config files are repaired when repeating an already-installed operation.

**Preservation rule:** If a client has an existing, hand-maintained `config/client_packages.php` without the managed registry, the installer refuses to overwrite it. Migrate that manifest deliberately using `docs/architecture/client-packages.md`, or continue to manage that client's Composer and manifest by hand. Managed manifests also fail closed if modified independently from the registry. Do not paste a new generated manifest over an existing production client.

## Local runtime file access and diagnostics

The single policy implementation is `scripts/operations/lib/client_file_permissions.py`.
Local commands honor `ENGAGE_CORE_WEB_USER` and `ENGAGE_CORE_WEB_GROUP` (both default to `www-data`). Do not run the entire workflow with `sudo`; a developer-owned path is required. When the developer cannot assign the PHP-FPM group directly, the helper invokes `sudo chgrp` only for the exact runtime file/directory requiring that group. On Linux, an owner who is not in that group may not be able to retain the `setgid` bit with an ordinary `chmod`; the helper then invokes `sudo chmod` for that exact **config** directory. It does **not** run `chown`, change checkout-root ownership/modes, recursively change `/var/www`, or make client configuration world-readable.

- **Managed package install:** Only `config/` and the two installer-generated config files are normalized. The client checkout root is checked for traversal, not rewritten; `composer.json` and `composer.lock` are left alone. The whole client config tree is checked read-only under the PHP-FPM identity; unrelated unreadable settings are reported for explicit repair.
- **New client creation:** The newly generated temporary scaffold is group-restricted; the shared helper then prepares its `config/` tree, including setgid inheritance, and checks actual PHP-FPM access before GitHub repository creation. It does not rewrite the scaffold's top-level Git checkout directory.
- **Local client bootstrap:** The selected client's `config/` subtree (not the Git checkout root) is normalized file-by-file to directories `2750` and files `0640`. Root and selected-client `.env` files are normalized to developer-owned, PHP-FPM-group-readable `0640` **before** environment-helper writes; group/mode are reapplied after each atomic environment update. The script also verifies the actual web-user boot.

The checks report the specific unreadable file or untraversable directory. This catches permission problems *before* Laravel's early error handler can mask the original issue as `Target class [config] does not exist`. These checks do not replace deployment preflight or detect every possible application configuration error; the staging/production deployment launcher remains authoritative for deployed runtime permissions.

Local diagnostics after package installation:

```bash
./scripts/package.sh verify holly-hatcher-ef-core
sudo -u www-data php artisan about --only=environment --no-ansi
sudo -u www-data php artisan config:show client.dashboard --no-ansi
```

To explicitly repair previously generated package-managed paths **without** a Composer update or database installation, run:

```bash
python3 scripts/operations/lib/client_file_permissions.py prepare-managed \
  client/holly-hatcher-ef-core
```

This does not rewrite client configuration values or touch the database.

## 3. Package metadata and credentials

The new package's `engage-package.json` declares its type, namespace, contributor class and selected-client environment **metadata**. Example for a Stripe integration **after implementation**:

```json
{
  "schema": 1,
  "name": "imagine-social/engage-integration-stripe",
  "kind": "integration",
  "namespace": "ImagineSocial\\EngageIntegrationStripe",
  "github_owner": "ImagineSocialGit",
  "environment": {
    "STRIPE_SECRET_KEY": {"owner": "stripe", "secret": true},
    "STRIPE_WEBHOOK_SECRET": {"owner": "stripe", "secret": true}
  },
  "service_provider": "ImagineSocial\\EngageIntegrationStripe\\StripeServiceProvider"
}
```

These are illustrative keys until the Stripe implementation establishes their exact requirements. The descriptor declares ownership only; actual values belong in the selected client's ignored `.env`. After deliberate descriptor changes and a corresponding package push, use `package.sh install ... --sync-metadata` to accept the revised committed registration shape. Do not silently mutate already-deployed package contracts; deploy the updated client manifest, lockfile and package commits together.

### Opting a vertical into migrations

A newly scaffolded vertical has `"migration_scope": false`, and its module class has **no** `migrationScope()` method. This is intentional: `database/migrations/.gitkeep` is not a migration. An enabled or disabled registered module whose manifest claims an empty migration directory prevents Core Artisan startup.

When the vertical has **real** schema to install, add the properly named PHP migration file(s) under `database/migrations/`, implement the package class's `migrationScope()` method returning `['path' => 'vendor/<vendor>/<package>/database/migrations']`, and change `engage-package.json` to `"migration_scope": true`. The installer fails before changing a client if a true flag points to a package directory with no PHP migrations. After committing/pushing the package, sync the client's managed metadata with `package.sh install CLIENT_KEY /absolute/path/to/package --sync-metadata`; update the client Composer lock to the new pushed revision before staging/production. Keep the vertical disabled until its schema and runtime contracts are ready. Existing managed vertical metadata without the flag remains compatible: the legacy behavior is to register the scope, without silently rewriting its committed manifest.

Legacy packages without `engage-package.json` are **not retroactively rewritten** by this tool. Use the existing documented manual installation pattern unless explicitly migrating them.

## 4. Local edit, validation, and return to lock-pinned source

```bash
./scripts/package.sh link holly-hatcher-ef-core /var/www/engage-integration-stripe
./scripts/package.sh verify holly-hatcher-ef-core
```

A local link replaces the selected client's installed `vendor/imagine-social/<package>` directory with a symlink to the local checkout. Source edits take effect immediately, but changes to Composer requirements/autoload need a normal Composer update and re-link. The existing linker refuses mismatched package names, absent Composer requirements, non-local environments or a different selected client.

To verify the **remote, lock-pinned package version**, restore the Composer-managed install:

```bash
./scripts/package.sh unlink holly-hatcher-ef-core imagine-social/engage-integration-stripe
./scripts/package.sh verify holly-hatcher-ef-core
```

The existing unlink helper checks remote source access and restores the locked revision without rewriting the lockfile. Always test deployment-like behavior **unlinked**, after pushing intended package changes and updating the selected-client lockfile. Never deploy a dev symlink to staging or production.

Inspect and commit each repository independently:

- **Package repository:** PHP source, descriptor, schema and package tests as applicable.
- **Client repository:** `composer.json`, `composer.lock`, `config/client_package_registry.json`, `config/client_packages.php`, `.gitignore`, and module enablement.
- **Core repository:** platform contracts and scripts only.

## 5. Enabling a new vertical

Only after the vertical's code and migration scope are implemented, installed and registered:

```bash
./scripts/add-client-modules.sh holly-hatcher-ef-core donations --dry-run
./scripts/add-client-modules.sh holly-hatcher-ef-core donations
php artisan engage:deployment-plan
php artisan modules:preflight
```

Use the module installation command prescribed by `docs/architecture/deployment/command-ownership.md`, then `php artisan setup:validate` and a manual CRM/route smoke test. Do not create a migration only to make an empty scaffold look installed. For dev-only unpublished modules, consolidate schema within the original module migration before production shipment.

## 6. Staging and production

Server deployment must use the selected client's **committed Composer lockfile** with a readable private VCS source and authorized **read-only** deployment credentials. The deployment launcher owns dependency installation, runtime environment reconciliation, module migrations, and service boot. Do not run `scripts/package.sh` on staging or production, edit server-side client source files, change Composer versions ad hoc or deploy local symlinks. See `docs/operations/deployment-runbook.md` for the authoritative launch/update sequence.

## 7. Failure handling

- **Remote created, push failed:** the package checkout is preserved; repair SSH/GitHub authentication, push `main`, and then install. Do not recreate the remote.
- **Composer install/validate failed:** selected-client source files are restored to their pre-install contents. Check Composer output and any partially downloaded `vendor/` data before retrying; the tool does not delete the entire client vendor directory. If a new requirement caused Composer validation to report that the old lock was stale, install the Core package workflow correction and retry the same install command; do not manually rewrite the client lockfile or recreate the GitHub repository.
- **Manifest already hand-maintained:** stop and reconcile explicitly; the package manager will not overwrite handcrafted PHP.
- **Package metadata changed:** use `--sync-metadata` only after reviewing/pushing the package metadata and source contract.
- **Local link failed:** the Composer installation and client manifest remain installed. Fix the link preflight and retry `package.sh link`; do not rerun GitHub creation. A link failure during `php artisan view:clear` can leave the symlink **already established**; inspect with `readlink -f` before attempting a relink.
- **New vertical scaffold fails Artisan startup with `contains no migration files`:** this was caused by the early package workflow registering an empty scope. Update the existing scaffold descriptor/class to `"migration_scope": false` and a module definition without `migrationScope()`, then run `package.sh install CLIENT_KEY /absolute/path/to/package --sync-metadata --link` using the corrected package workflow. This regenerates the managed manifest without fake migrations or Composer reinstall, even when the local link already exists. Do not edit the generated `client_packages.php` by hand.
- **Wrong client or environment:** switch root `CLIENT_KEY` explicitly and recheck actual selected database before changing a client.