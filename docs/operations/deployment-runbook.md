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
- identifying when a special Project State or troubleshooting runbook is required.

This document intentionally does not duplicate the complete environment-variable catalog, provider-dashboard instructions, incident-recovery detail, or internal deployment-orchestrator architecture.

Use these companion authorities:

```text
Need an environment variable or ownership rule?
    docs/operations/client-environment-reference.md

Need Resend, Telnyx, Zoom, Spaces, DNS, or Turnstile account/dashboard work?
    docs/operations/client-third-party-services-checklist.md

Something failed or runtime behavior is suspicious?
    docs/operations/deployment-safety-and-troubleshooting.md

Doing an approved destructive Project State clean rebuild?
    docs/operations/project-state-transfer-runbook.md

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

Approved clean rebuild preserving supported Project State
    -> project-state-transfer-runbook.md

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
dependency installation
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

`engage:install` is the application-level installation authority.

Its four installation stages are:

```text
1. platform migrations
2. configured schema-owning module installation
3. preset synchronization
4. setup validation
```

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

Install dependencies and build assets:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan optimize:clear
```

For an already-installed database with real data:

```bash
php artisan migrate --force
php artisan modules:migrate --force
php artisan presets:sync
php artisan modules:status
php artisan setup:validate
```

Important ownership:

```text
migrate
    platform migrations only

modules:migrate
    ledger-installed module scopes only

presets:sync
    configured DB-owned definitions; not schema migration

modules:status
    read-only schema/ledger/manifest inspection

setup:validate
    read-only application readiness gate
```

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

## Audit classifications

```text
PASS
    verified healthy

INFO
    harmless or historical/canonical drift

WARNING
    unusual or incomplete, but not proven to break the platform

MANUAL VERIFICATION REQUIRED
    provider/external/operator fact the host cannot safely prove

BREAKING
    current runtime/security/isolation contract is proven broken
```

Only `BREAKING` fails the normal audit result and only `BREAKING` findings may enter the automatic deterministic repair registry.

Do not normalize healthy legacy names, paths, prefixes, process labels, or metadata merely to make them look like a fresh launcher-created deployment.

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

# 7. Module-specific post-install commands

Run only module-owned setup commands not already covered by `engage:install` or `presets:sync`.

Current registry:

## Forms external-intake credentials

Condition: Forms is enabled for a server-to-server external intake client and the environment does not already have a valid client ID/signing-secret pair.

```bash
php artisan forms:external-intake:issue-secret [client]
```

The command prints matching Core and external-caller environment blocks without mutating either environment.

After installing new values:

```bash
php artisan optimize:clear
php artisan setup:validate
```

Use distinct staging and production credentials.

No other current module requires a mandatory module-specific post-install Artisan command beyond the normal install/preset lifecycle.

# 8. Provider and external-system work

The deployment plan can identify required provider setup, but static server checks cannot prove every provider-dashboard permission or real external event.

Use:

```text
docs/operations/client-third-party-services-checklist.md
```

for:

```text
GitHub deploy access
DNS
Turnstile
DigitalOcean Spaces
Resend
Telnyx
Zoom
```

Provider setup may remain `MANUAL VERIFICATION REQUIRED` after an otherwise healthy audit. Resolve or deliberately verify each applicable item before launch.

# 9. Horizon and Scheduler

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

# 10. Production-safe smoke checks

Run only the checks applicable to the enabled client capabilities.

Infrastructure:

```text
CRM/login responds over HTTPS
required Core hosts resolve correctly
SSL is valid
DB is reachable
Redis is reachable
expected prefixes are isolated
Horizon is running the intended checkout
all required queues are consumed
Scheduler exists and schedule:list is correct
setup:validate passes
```

Messaging, when enabled:

```text
transactional email reaches sent/delivered
marketing sender resolves
Resend lifecycle webhook works
inbound reply works when Inbound Messaging email is enabled
transactional SMS sends
marketing SMS sender resolves
Telnyx inbound/signature behavior works when used
STOP/HELP protections remain active
```

Webinars, when enabled:

```text
public registration creates/reuses Contact
registration is stored
correct Zoom event adapter is used
provider registrant is created
personalized join URL is stored
confirmation/reminders are planned
consent behavior is correct
ended webhook is accepted for the event type in use
attendance resolves
attended/missed automation runs
recording.completed resolves playback when required
post-event follow-up waits for required conditions
```

Use the specialized provider and troubleshooting docs for deeper verification.

# 11. Controlled Project State clean rebuild

Project State is not a normal deployment/update mechanism.

For an approved destructive clean rebuild that must preserve supported CRM state, use only:

```text
docs/operations/project-state-transfer-runbook.md
```

At a high level, that flow owns:

```text
freeze writes
stop Horizon/Scheduler
take independent DB backup
export immutable Project State
clear only the exact stale Redis runtime namespace
deploy intended code/client config
migrate:fresh --force
engage:install --force --no-create-user
recreate environment-owned CRM user
Validate Only
Apply Import
verify inert imported state
restore runtime services
resume imported work deliberately
audit
reopen traffic
```

Do not substitute this sequence for a routine production deployment.

# 12. Final deployment gate

Before calling a deployment complete:

```text
[ ] intended Core commit deployed
[ ] intended client commit deployed
[ ] CLIENT_KEY / preset / modules / timezone correct
[ ] APP_ENV and APP_DEBUG correct
[ ] APP_KEY preserved
[ ] environment files readable by required process identities
[ ] selected-client .env contains only client-owned keys
[ ] database identity correct
[ ] Redis/cache/Horizon isolation correct
[ ] modules:status reviewed
[ ] setup:validate passes
[ ] Horizon process/path healthy
[ ] every runtime queue is consumed
[ ] Scheduler cron and schedule:list verified
[ ] DNS/Nginx/TLS healthy
[ ] enabled providers verified
[ ] applicable safe smoke checks pass
[ ] Project State work completed/resumed when applicable
[ ] final audit contains no BREAKING finding
[ ] MANUAL VERIFICATION REQUIRED items are deliberately resolved or accepted
```

# 13. What not to do

Do not:

```text
run php artisan test as the production deployment gate
regenerate APP_KEY casually
flush Redis indiscriminately
run migrate:fresh on a normal production update
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