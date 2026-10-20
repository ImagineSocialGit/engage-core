# Engage Core — Deployment Runbook

## Purpose

This is the single operator-facing deployment guide for Engage Core.

Start here for:

- launching a new staging or production environment;
- updating an existing deployed client;
- auditing an existing deployment;
- repairing audit findings;
- adding a module later;
- verifying a deployment before handoff;
- identifying when a specialized troubleshooting runbook is required.

This document intentionally does not duplicate the complete environment-variable catalog, provider-dashboard instructions, incident-recovery detail, or internal deployment-orchestrator architecture.

Use these companion authorities (each owns its subject; this runbook does not duplicate their catalogs or internal command contracts):

```text
Need an environment variable or ownership rule?
    docs/operations/client-environment-reference.md

Need Resend, Telnyx, Zoom, Spaces, DNS, or Turnstile account/dashboard work?
    docs/operations/client-third-party-services-checklist.md

Something failed or runtime behavior is suspicious?
    docs/operations/deployment-safety-and-troubleshooting.md

Need command ownership or launcher internals?
    docs/architecture/deployment/
```

## Source/deployment boundary

Development is where application and version-controlled client configuration changes are made, tested, committed, and pushed.

Staging and production consume approved source. Do not edit normal PHP, Blade, JavaScript, or version-controlled client configuration directly on those targets.

Target-environment concerns remain target-owned:

```text
.env files
secrets
database credentials
Nginx
TLS
Supervisor
Scheduler cron
provider-dashboard configuration
DNS
other host/runtime configuration
```

## Choose the workflow

```text
New environment
    -> launch-client-environment.sh new

Existing launcher-managed deployment
    -> launch-client-environment.sh update <state-file>

Existing/pre-orchestrator deployment
    -> deploy approved source
    -> audit
    -> guided fix when needed
    -> audit again

Add a module
    -> launch-client-environment.sh add-modules <state-file> --module <module>

Incident / bootstrap / worker / queue / provider failure
    -> deployment-safety-and-troubleshooting.md
```

# 1. New environment

## Preferred path

From a current Engage Core checkout:

```bash
bash scripts/operations/launch-client-environment.sh new
```

Stable inputs may be supplied up front when useful:

```bash
bash scripts/operations/launch-client-environment.sh new \
  --environment production \
  --client-repo git@github.com:ImagineSocialGit/example-client-crm.git \
  --root-domain example.com \
  --topology core_services_only
```

The launcher prints the derived deployment identity before mutation and pauses for operator-owned work it cannot safely infer.

It may handle or coordinate:

```text
application/runtime path
Core + client repository identity
root and selected-client locked dependency installation, where applicable
early runtime-directory and secret-file prerequisites
asset build
environment-plan requirements
database setup pauses
module schema installation
preset synchronization
setup validation
runtime directories
Nginx/Core host ownership
TLS
Supervisor/Horizon
Scheduler
provider setup pauses
final verification
```

Do not manually invent alternate Redis prefixes, Horizon process names, queue inventories, or application paths merely because an older deployment used a different convention.

## Resume an interrupted launch

The launcher records a non-secret state file under the deploy user's state directory.

Resume with:

```bash
bash scripts/operations/launch-client-environment.sh resume ~/.local/state/engage/deployments/<state>.json
```

Completed phases and completed provider steps are not repeated unnecessarily.

## New-environment application ownership

`engage:install` owns new-environment application installation, as defined exclusively in `docs/architecture/deployment/command-ownership.md`.

Interactive installation may create the first CRM user. Automated deployment should use the launcher's chosen non-interactive path and create users explicitly when required.

# 2. Normal update of a launcher-managed deployment

Preferred command:

```bash
bash scripts/operations/launch-client-environment.sh update /path/to/state.json
```

The update path is responsible for coordinating the current deployment lifecycle:

```text
pull clean approved Core/client source
install production dependencies
build assets
resolve new deployment requirements
run platform migrations
run read-only module migration preflight
run installed-module migrations
sync presets
validate setup
refresh Horizon/Scheduler/Nginx/TLS when required
perform safe runtime verification
```

The update path does not run the production test suite.

Automated tests belong in development, staging, or CI. Production is validated with current-code readiness checks, audit, safe smoke tests, and runtime verification.

# 3. Manual update fallback for an existing deployment

Use this only when the deployment is not yet managed by a launcher state file or when intentionally executing the underlying lifecycle directly.

Before mutation:

```text
confirm the intended Core commit
confirm the intended client commit
confirm both checkouts are clean
confirm the correct target environment
confirm provider/environment additions required by the new code are already available
```

Prepare the authorized runtime directory tree and secret-file access **before** Composer scripts or Artisan run. On fresh clones, `storage/` and required `bootstrap/cache/` directories may not exist in Git; create them with the verified deploy/runtime write-inheritance policy described by the environment contract.

Install the applicable selected-client lock file **before any root Composer hook that bootstraps Laravel**, then install the exact committed root lock. The selected-client Composer project is optional and must have both `composer.json` and a committed `composer.lock` when used:

```bash
# Only when client/<CLIENT_KEY>/composer.json and composer.lock exist:
(cd client/<CLIENT_KEY> && composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction)

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan optimize:clear
```

Do not manufacture a nested Composer project for clients that have none. Do not update committed lock files on a deployment target. Run build/install stages only when the approved deployment actually requires them; the manual fallback above gives the full sequence.

For an already-installed database with real data:

```bash
php artisan migrate --force
php artisan modules:preflight
php artisan modules:migrate --force
php artisan presets:sync
php artisan modules:status
php artisan setup:validate
```

Command and migration ownership, including the one-time checksum-baseline procedure, is maintained solely in `docs/architecture/deployment/command-ownership.md`.

If queued-job runtime code changed, restart the actual Supervisor-managed Horizon program after code/schema/config changes and verify the live process path.

Also verify the Scheduler entry and effective schedule.

Do not run `migrate:fresh` on a normal production update.

# 4. Audit an existing deployment

Audit is the final system-level verification path for both current and legacy deployments.

Before auditing, make sure the target server has the intended Core and client commits. Audit verifies source identity but does not pull source for you.

Example:

```bash
bash scripts/operations/launch-client-environment.sh audit \
  --environment production \
  --client-key <CLIENT_KEY> \
  --root-domain <ROOT_DOMAIN>
```

When automatic checkout discovery is ambiguous:

```bash
--app-path /actual/path/to/engage-core
```

Useful optional identity comparisons include:

```text
--client-repo URL
--crm-host HOST
--core-branch BRANCH
--client-branch BRANCH
--deploy-user USER
--web-user USER
--web-group GROUP
--scheduler-user USER
--server-ip IPV4
```

## Audit is read-only

Audit must not:

```text
write root/client .env files
change ownership or modes
run environment sync
migrate/install/reconcile modules
alter Redis
write Nginx configuration
issue certificates
reload services
write Supervisor configuration
change cron
change DNS/provider configuration
modify CRM/business data
```

It may inspect runtime/environment metadata, Git remote revisions, Nginx/TLS, Supervisor, cron, DNS, module state, setup validation, Horizon status, and the effective Laravel schedule.

Audit reports `PASS`, `INFO`, `WARNING`, `BREAKING`, or `MANUAL VERIFICATION REQUIRED`. Treat proven `BREAKING` conditions as blockers; external/manual facts remain operator-owned. Classification and repair eligibility are defined only in the orchestrator contract. Audit must still inspect file-secret security when source identity is not current, although Git provenance may block authoritative runtime attribution.

# 5. Fix audit findings

Preferred guided flow:

```bash
bash scripts/operations/launch-client-environment.sh fix \
  --environment production \
  --client-key <CLIENT_KEY> \
  --root-domain <ROOT_DOMAIN>
```

The guided flow:

```text
runs audit first
shows the repair plan
reviews INFO/WARNING drift
walks blocking environment requirements
uses hidden input for secrets
writes only operator-supplied/approved environment values
clears cached configuration after environment changes
asks before deterministic eligible repairs
runs a complete audit again
```

Dry-run without mutation:

```bash
bash scripts/operations/launch-client-environment.sh fix \
  --environment production \
  --client-key <CLIENT_KEY> \
  --root-domain <ROOT_DOMAIN> \
  --dry-run
```

Apply only deterministic safe repairs non-interactively:

```bash
bash scripts/operations/launch-client-environment.sh fix \
  --environment production \
  --client-key <CLIENT_KEY> \
  --root-domain <ROOT_DOMAIN> \
  --apply
```

Useful category limiting:

```text
--only environment
--only schema
--only nginx
--only horizon
--only scheduler
--all-safe
```

`--all-safe` excludes guided provider/environment-value collection and selects only deterministic safe repairs.

Automatic repair does not own:

```text
provider credentials
provider dashboards
remote database account provisioning
CRM/business data
Project State
ambiguous Nginx/process ownership
destructive schema operations
deliberate healthy legacy normalization
```

Every mutating fix pass must end with a fresh audit.

# 6. Add a module later

Enable and test the module in development first. Commit and push the client configuration that intentionally enables it.

For a launcher-managed deployment:

```bash
bash scripts/operations/launch-client-environment.sh add-modules /path/to/state.json \
  --module <module>
```

The launcher resolves new environment/provider requirements, installs the requested module dependency closure, syncs presets, validates, refreshes runtime/hosts, and verifies.

Underlying command ownership when operating manually:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan modules:install <module> --force
php artisan presets:sync
php artisan modules:status <module>
php artisan setup:validate
```

`modules:install` resolves schema-owning dependencies. It must not install unrelated optional modules merely because their migration directories exist.

Module-specific post-install commands are registered only in `docs/architecture/deployment/command-ownership.md`; run them when their recorded conditions apply, never as a blanket step.

# 7. Provider and external-system work

The deployment plan can identify required provider setup, but static server checks cannot prove every provider-dashboard permission or real external event.

Use:

```text
docs/operations/client-third-party-services-checklist.md
```

Provider setup may remain `MANUAL VERIFICATION REQUIRED` after an otherwise healthy audit. Resolve or deliberately verify each applicable item before launch.

# 8. Horizon and Scheduler

Supervisor is the lifecycle owner for Horizon on the supported deployment path.

Before restarting, discover the actual program name:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
```

Restart the exact client program:

```bash
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

Restart after PHP changes that affect queued-job execution, payload rendering, gates, providers, or other long-running worker behavior.

Scheduler must also be active:

```bash
sudo crontab -u <DEPLOY_USER> -l
php artisan schedule:list
```

Do not assume healthy Horizon proves Scheduler is configured.

# 9. Final deployment gate

The **single** deployment completion gate is below. Run only feature-specific checks that apply to the selected client; detailed provider acceptance belongs in `client-third-party-services-checklist.md`, and failure diagnosis belongs in the troubleshooting guide.

```text
[ ] Core and selected client are on the approved, clean commits
[ ] correct CLIENT_KEY, module configuration, preset, timezone, APP_ENV, APP_DEBUG and preserved APP_KEY
[ ] secure root/client .env permissions: each authorized runtime can read; unrelated users cannot
[ ] selected-client .env passes key-ownership validation
[ ] correct database identity and isolated Redis/cache/Horizon namespaces
[ ] modules:preflight (where required), modules:status and setup:validate are satisfactory
[ ] Horizon Supervisor runs the approved checkout and consumes every executable queue
[ ] Scheduler entry is present exactly once and schedule:list is understood
[ ] Core-owned CRM/public/webhooks hosts resolve to the intended Nginx document root with valid TLS
[ ] login/CRM HTTPS smoke passes
[ ] enabled Messaging: representative email/SMS delivery and consent/STOP/HELP safeguards pass
[ ] enabled Inbound Messaging: inbound email/SMS correlation and provider webhook signatures pass
[ ] enabled Webinars: registration, provider join links, attendance, ended/recording events, and conditional follow-up pass
[ ] provider-dashboard, credential, and externally verified items are resolved or deliberately accepted
[ ] final read-only audit reports no BREAKING finding, including secret-access violations
```

# 10. What not to do

Do not:

```text
run php artisan test as the production deployment gate
regenerate APP_KEY casually
flush Redis indiscriminately
run migrate:fresh on a normal production update
use Project State export/import as a normal deployment or recovery shortcut
edit application/client source directly on staging/production
assume preset sync rewrites already-scheduled message payloads
assume Horizon health proves Scheduler health
assume provider credentials prove provider dashboard/webhook readiness
normalize healthy legacy deployment identity merely for cosmetic consistency
```

When the normal path fails, stop improvising and use:

```text
docs/operations/deployment-safety-and-troubleshooting.md
```