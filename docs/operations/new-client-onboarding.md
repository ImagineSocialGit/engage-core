# New Client Onboarding — Engage Core + Engage SEO

## Purpose and authorities

This is the **one starting point** whenever creating a new client for Engage Core, Engage SEO, or both. It owns the sequence and the completion checklist, not the implementation details of all the underlying tools.

- **Core staging/production launch, update, audit and rollback:** `docs/operations/deployment-runbook.md` (sole operator deployment authority).
- **Core private packages, module/provider contribution and local symlinks:** `docs/architecture/client-packages.md`.
- **Core environment ownership, permissions and isolation:** `docs/operations/client-environment-reference.md`.
- **Provider accounts, DNS and service setup:** `docs/operations/client-third-party-services-checklist.md`.
- **Core/SEO database topology:** `docs/architecture/cross-platform-deployment-topology.md`.
- **Errors and drift:** `docs/operations/deployment-safety-and-troubleshooting.md`.

Do not substitute an archived Project State transfer workflow for a new-client launch, or edit version-controlled code/configuration on staging or production.

## 0. Define the identities and scope

Before running scripts, record:

- Legal client/business name, timezone, canonical public domain, owner and operator contact.
- Platform choice: `core`, `seo`, or both; required modules, verticals, integration packages and presets.
- **Separate names per platform** when both are used. Each client key, local client-repository directory basename and remote GitHub repository name must be identical for that platform; never assume both platforms use the same key.
- Which system owns each hostname, database, payment/provider account, webhook endpoint and customer-facing journey.
- Whether the owner has provided or approved access to domain, DNS, email, payment and other third-party accounts.
- Staging and production runtime topology, release acceptance requirements, and data/backup ownership.

Example (Holly Hatcher Education Foundation):

| Platform | Client key = repository basename = GitHub name |
| --- | --- |
| Core | `holly-hatcher-ef-core` |
| SEO | `holly-hatcher-ef-seo` |

Engage Core's nested client repository lives at `engage-core/client/<core-key>/`, not automatically at `/var/www/<core-key>`. If that distinction matters to a workstation layout, review the location before creating any remote repository. The GitHub basename still matches the nested repository directory basename.

## 1. Create the Core client in development

From the trusted development checkout:

```bash
cd /var/www/engage-core
bash -n scripts/create-client.sh
./scripts/create-client.sh holly-hatcher-ef-core America/Chicago \
  --name "Holly Hatcher Education Foundation" \
  --preset basic \
  --modules billing \
  --dry-run
```

Review the planned client key, paths, GitHub owner, preset and optional modules. `core` is always on and must **not** appear in the explicit optional-module list. `--modules` intentionally replaces the `basic` template's Tasks + Workflow module choice without editing generated files after creation.

After reviewing the plan:

```bash
./scripts/create-client.sh holly-hatcher-ef-core America/Chicago \
  --name "Holly Hatcher Education Foundation" \
  --preset basic \
  --modules billing
```

The command checks GitHub authentication, creates a **private** remote repository, commits and pushes the generated client config, and by default calls `bootstrap-client-local.sh`. That bootstrap selects the client in the **development** root `.env`, provisions an isolated local MySQL database/user via an interactive `sudo mysql -p`, runs `engage:install`, and verifies CLI/PHP-FPM runtime identity. It does **not** deploy to staging or production. The machine must have the required sudo and MySQL permissions before starting.

Use `--skip-local-bootstrap` when intentionally creating and pushing source first (e.g. to compose new Composer packages before installing the runtime). Resume later with:

```bash
./scripts/bootstrap-client-local.sh holly-hatcher-ef-core
```

**Safety:** `create-client.sh` publishes the GitHub repository before local bootstrap. A later bootstrap failure does not remove the remote/client checkout. Repair the reported issue and rerun the bootstrap; do not create a second remote. Local bootstrap changes the shared development checkout's selected `CLIENT_KEY` and its config cache; it does not leave every client active simultaneously.

## 2. Select modules and configure the client

The source of truth for optional module enablement is:

```text
client/<core-key>/config/modules.php
```

To discover the available built-in and installed-package modules:

```bash
./scripts/add-client-modules.sh holly-hatcher-ef-core --list
```

To add optional modules without overwriting existing selections:

```bash
./scripts/add-client-modules.sh holly-hatcher-ef-core donations --dry-run
./scripts/add-client-modules.sh holly-hatcher-ef-core donations
```

Run the Donations commands **only after** its private Composer package is installed and its module is contributed through `client_packages.php`. Packages are not enabled simply because Composer installed them. Commit client-config changes to the **client repository**, not the platform repository.

Client `.env` contains local runtime credentials and is ignored by Git. Keep root process keys in root `.env`; client-scoped keys belong in `client/<core-key>/.env`. Never copy `.env.example` wholesale or commit secrets. Run the plan to discover required values:

```bash
php artisan engage:deployment-plan
php artisan engage:environment:sync --write-missing
php artisan engage:deployment-plan
```

Do not add Messaging, Events, Forms, Tasks, Workflow, Commerce, or other modules to a new client without a concrete workflow requiring them.

## 3. Scaffold, install, and develop private Composer packages

Use the **single** Core package workflow, documented in `docs/operations/private-package-workflow.md`, after choosing the client. Each package is a separate private Git repository, not part of the Core or client Git histories. All generated package, directory and GitHub repository basenames match.

Create a new package only once; `--dry-run` performs no mutations:

```bash
./scripts/package.sh create engage-integration-stripe \
  --type integration \
  --description 'Stripe billing collection adapter for Engage Core' --dry-run
./scripts/package.sh create engage-integration-stripe \
  --type integration \
  --description 'Stripe billing collection adapter for Engage Core'
```

For the optional nonprofit vertical, use `--type vertical` with `engage-donations`. Provider-neutral libraries use `--type library`.

With `CLIENT_KEY=holly-hatcher-ef-core` selected in the local Core `.env`, install only the approved package from its published private remote into the **client-owned** nested Composer project:

```bash
./scripts/package.sh install holly-hatcher-ef-core \
  /var/www/engage-integration-stripe --dry-run
./scripts/package.sh install holly-hatcher-ef-core \
  /var/www/engage-integration-stripe --link
./scripts/package.sh verify holly-hatcher-ef-core
```

This keeps `composer.json`/`composer.lock` in the client repository, adds the selected-client package manifest from package metadata, and uses the existing local-only link helper. The CLI **refuses** to replace an existing hand-maintained `client_packages.php`; migrate that client deliberately rather than overwriting it.

During development, use `package.sh link` and `package.sh unlink` to switch between local source and the committed lock-pinned package version. Changes to package dependencies/autoload require Composer reconciliation, not only a symlink. Commit the package, client configuration and platform repositories separately. **Never deploy symlinks to staging/production.**

Only after an optional vertical's module definition and schema are implemented, installed and registered should its module key be enabled via `add-client-modules.sh`. Follow the package workflow guide for environment key declarations, metadata sync and safe deployment.

## 4. Validate the Core development runtime

After applying package, module or schema changes, select the client, clear stale config, reconcile approved schema and run focused tests **against a dedicated MySQL test database**:

```bash
php artisan optimize:clear
php artisan engage:deployment-plan
php artisan modules:preflight
php artisan modules:status
php artisan setup:validate
```

Use `modules:install <module>` or `modules:migrate` only as prescribed in `docs/architecture/deployment/command-ownership.md`. Running `engage:refresh` or schema-destructive commands is permitted only on verified disposable development databases, never production or a production-backed SEO preview. Validate actual CRM/operator surfaces and server-to-server behavior manually, not by brittle UI-copy assertions.

For payments: use Stripe test keys, test webhooks, test checkout cards and asynchronous completion cases. The Core webhook reconciliation is authoritative; a browser success return is not evidence of a settled donation. Test QR codes against the stable SEO `/donate` URL, never a temporary Stripe session URL. Check receipt/acknowledgment rules separately.

Before deployment, commit and push the **platform**, **private packages** and **client** repositories, then ensure the client's lockfile references reachable approved package commits. The staging server must be able to fetch those locked packages with its authorized credentials.

## 5. Set up Engage SEO, when selected

Engage SEO has its **own** client key, client repository, local installation and deployment lifecycle. Do not create its client by renaming or copying the Core client repository.

Create the SEO client from the SEO checkout using its verified client bootstrap script and SEO-specific baseline; verify that the resulting local repository basename and private GitHub repository name match the SEO client key. For Holly Hatcher, both must be `holly-hatcher-ef-seo`.

**SEO command verification required:** the exact current SEO creation/launch command interface has not been included in the Core source snapshots reviewed for this document. Inspect the current SEO `scripts/create-client.sh` and its deployment runbook before adding literal SEO commands here. Do not invent a cross-platform one-line deployment command or assume Core launch scripts manage SEO.

For the combined public workflow, SEO owns the donation page and its branded form; Core owns the donation/payment state; Stripe owns the sensitive payment controls. Integrate via explicit configured authenticated interfaces. Keep Core keys and Stripe secret keys out of client-side JavaScript.

## 6. Launch Core staging

**Use only the current Core deployment authority** rather than ad hoc SSH checkout editing:

```bash
cd /var/www/engage-core
bash scripts/operations/launch-client-environment.sh new
```

Select the verified **staging** environment, `holly-hatcher-ef-core` client repository, root domain, Core-owned hosts and topology in the interactive launcher. The launcher stages the approved Core/client source, dependencies and locked packages, isolated staging database, environment requirements, module migrations, Nginx, TLS, Horizon, Scheduler and final verification, stopping for operator-owned work. Save the non-secret launcher state file for `resume`, `update` and `add-modules` operations.

Core staging is an isolated development/test database. **SEO/Artist staging is different:** by documented default it previews production content on a shared production database; do not run migrations, refreshes, seeders or background side-effect workers against that preview. Confirm the actual SEO topology before exposing a staging URL.

Validate Core staging with the launcher's read-only audit and the feature-specific acceptance paths in the deployment runbook, including signed webhook delivery and reconciliation where applicable. Never use live payment credentials during payment test exercises.

## 7. Launch production

Production requires approved code and committed client configuration, working private-package access, provider ownership and DNS/TLS readiness, a backup/restore plan, and green staging acceptance. Ensure live provider keys and webhook endpoints are configured only for the intended production runtime.

Use the same **new** launcher workflow with production selected:

```bash
bash scripts/operations/launch-client-environment.sh new
```

Follow `docs/operations/deployment-runbook.md` for interactive pauses, verified environment values, app/server ownership, migrations, runtime process setup and final audit. Production is **deploy-only**. Do not manually patch repository source or run `engage:refresh`, `migrate:fresh`, or production-side tests. Use the launcher's `update` and `add-modules` commands for subsequent approved changes; preserve the generated state files.

For SEO production, use its own approved deployment pipeline after separately verifying SEO's source and database topology. The cross-platform operator must prove public URLs resolve to the correct owning applications, then exercise a real end-to-end payment in a controlled manner before accepting live donations.

## 8. Completion gate and recordkeeping

Mark a client launched only when all of the following are true:

- [ ] Each platform has the intended client key, local repository basename and matching private remote repository basename; source refs are recorded.
- [ ] The right module closure, package versions, service providers, migrations and Composer lockfiles are committed and installed; no local package symlinks remain on targets.
- [ ] Dev, staging and production have intentionally separate secrets, account permissions, app keys, DB identities and cache/Redis/Horizon prefixes; verified filesystem permissions deny unauthorized readers.
- [ ] Only the intended service owns each public hostname and Nginx document root; DNS/TLS/redirects and canonical URLs are verified.
- [ ] Core staging is isolated; SEO preview staging's actual database privileges and write/worker restrictions are verified.
- [ ] Setup validation, migration preflight/status, host/process audit, Horizon and Scheduler checks pass for the enabled capabilities.
- [ ] End-to-end forms, payments, receipts, webhooks and QR links pass their applicable acceptance tests without relying on browser redirects as financial evidence.
- [ ] Client-owned third-party accounts, support ownership, operator access and handoff records are documented.
- [ ] Backups/restoration, update/rollback ownership, monitoring, incident contacts and launch acceptance are recorded.

This page is a **workflow entry point**. Keep all deep command, provider, environment and architecture detail in the authority documents linked above. If one of those authorities changes, update this sequence instead of copying or forking the original instructions.