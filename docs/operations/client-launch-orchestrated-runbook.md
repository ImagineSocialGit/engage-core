# Core Client Launch — Orchestrated Runbook

## Purpose

This is the operator-facing path for a new Core staging or production environment after the client configuration has been tested, committed, and pushed.

The launcher automates repeatable server work and pauses only for external account/dashboard work or values that cannot safely be inferred.

## Before starting

Have:

- SSH access to the target server as the normal deploy user;
- `sudo` access for server configuration;
- GitHub access to the Core and client repositories;
- the exact root domain for this environment;
- the client repository URL;
- access to applicable third-party accounts (Resend, Telnyx, Zoom, DigitalOcean, Cloudflare, etc.);
- for production Core, a provisioned remote client database/application user or access to your remote DB operator account so you can create them during the DB pause.

Do not pre-invent Redis prefixes, Horizon process names, database recommendations, app paths, or queue lists. The launcher derives those.

## New environment

From a Core checkout containing the launcher:

```bash
bash scripts/operations/launch-client-environment.sh new
```

You may also provide the stable inputs up front:

```bash
bash scripts/operations/launch-client-environment.sh new \
  --environment production \
  --client-repo git@github.com:ImagineSocialGit/slam-dunk-crm.git \
  --root-domain slamdunkhomeloans.com \
  --topology managed_main_site \
  --main-site-type seo
```

The launcher prints the complete derived identity before mutating the target and asks for confirmation.

## Core staging database

Core staging uses a local isolated MySQL database.

The launcher:

- derives a staging database name and client-scoped application user;
- generates the application password without printing it;
- writes the credential directly to the selected-client `.env`;
- provisions the local database/user through `sudo mysql`;
- grants that application user only the local staging client database.

This staging database is disposable test/review data and is not authoritative client CRM data.

## Core production database

Core production uses the authoritative remote client database.

The launcher does **not** create remote databases/users. It pauses and tells you to provision them with your remote DB operator/admin account, then prompts for:

```text
remote DB host
remote DB port
client database name
client application DB username
client application DB password (hidden)
optional MySQL SSL CA path
```

Your global/operator account does not go into the client runtime environment.

## External provider pauses

The application deployment plan determines which pauses appear. The launcher brings the required Core callback hosts through Nginx, DNS, and TLS before running these provider-dashboard steps so a provider can reach the real HTTPS endpoint during setup.

A Zoom-enabled client, for example, receives the exact current Server-to-Server OAuth scopes, webhook endpoint, and native event subscriptions from the Webinars deployment contributor. After you complete the Zoom Marketplace work, the launcher asks for the related credential values with secrets hidden.

The same pattern applies to Resend, Telnyx, Spaces, Turnstile, and signed external Forms intake.

You can stop at any pause with Ctrl+C. The launcher records completed non-secret checkpoints and can resume later.

## Resume

The launcher prints the state-file path during `new`.

Resume with:

```bash
bash scripts/operations/launch-client-environment.sh resume ~/.local/state/engage/deployments/<state>.json
```

Completed phases and completed provider steps are not repeated unnecessarily.

## DNS/Nginx/TLS

After the application/runtime is ready, the launcher derives the Core-owned host list from the active modules and client URL configuration.

It generates one Nginx site pointing only those hosts at the Core `public/` directory.

It never points the root domain at Core simply because Core is installed.

The launcher prints exact A records such as:

```text
crm.example.com        -> SERVER_IP
webhooks.example.com   -> SERVER_IP
webinar.example.com    -> SERVER_IP
messaging.example.com  -> SERVER_IP
```

Only the hosts actually used by the current deployment are included.

After you create/update DNS, the launcher waits until every required host resolves to the confirmed server IP. It then installs Certbot packages when approved/needed and acquires/refreshes the Nginx certificate for the exact Core host list.

## Final verification

The launcher verifies:

- deployment-plan readiness;
- module migration status;
- `setup:validate`;
- Supervisor/Horizon process identity;
- Horizon status;
- Scheduler cron + `schedule:list`;
- CRM HTTPS response;
- CRM login HTTPS response.

Provider verification that requires a real provider event remains a deliberate final human smoke: for example sending a real staging-safe email/SMS, receiving a signed callback, or exercising a Zoom registration/ended-event path.


## Auditing an existing deployment

Existing pre-orchestrator deployments do not need a launch state file before they can be inspected.

Run:

```bash
bash scripts/operations/launch-client-environment.sh audit \
  --environment staging \
  --client-key rob-the-mortgage-coach \
  --root-domain staging.robthemortgagecoach.com
```

If the deployment lives outside the canonical derived path and automatic discovery is ambiguous, add:

```bash
--app-path /actual/path/to/engage-core
```

Useful optional comparisons include:

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

`audit` is a hard read-only boundary. It does not create a launcher state file and does not:

- write root/client `.env` files;
- change ownership or modes;
- run environment sync;
- migrate/install/reconcile modules;
- alter Redis;
- write Nginx configuration;
- issue certificates;
- reload services;
- write Supervisor configuration;
- change cron;
- change DNS/provider configuration;
- modify CRM/business data.

It may read environment metadata and non-secret identity keys, inspect Git/Nginx/live-TLS/Supervisor/cron state, perform DNS lookups, run the bootstrap-safe client-environment ownership diagnostic, and run application commands whose contracts are read-only (`engage:deployment-plan --json`, `modules:status`, `setup:validate`, `horizon:status`, and `schedule:list`).

The report uses:

```text
PASS
INFO
WARNING
BREAKING
MANUAL VERIFICATION REQUIRED
```

Only `BREAKING` means the auditor has proved a current runtime/security/isolation contract is actually failing on the active Engage Core deployment. `INFO` records harmless legacy/canonical drift. `WARNING` records something unusual, incomplete, or worth review that has not been proved to break the platform. `MANUAL VERIFICATION REQUIRED` is reserved for facts the host cannot safely prove by itself.

Only `BREAKING` findings make the normal audit result fail and only `BREAKING` findings may enter the future automatic-fix registry. A different legacy name, path, prefix, process label, or metadata convention must never become fix work merely because `new` would derive a different value today.

The audit uses canonical naming/topology as a **reference for new deployments**, not a normalization mandate for existing ones. Functional runtime contracts are authoritative. In particular, `CRM_APP_URL` remains authoritative unless `--crm-host` is supplied, because the CRM hostname is not required to use the literal `crm.` label.

For staging environment files, the current proven convention is:

```text
owner: deploy user
group: deploy user's primary group
mode: 0664
```

Runtime directories are a separate contract. Audit checks effective deploy-user and PHP-FPM-user write access without creating probe files. Canonical `deploy-user:web-group 2775` metadata differences are informational when effective access is valid.

For production environment-file mode/group, audit reports the current metadata for manual verification rather than silently extending the staging-proven `0664` convention to secret-bearing production files.

The deployment plan contributes provider setup steps to the report as `MANUAL VERIFICATION REQUIRED`, because provider dashboards and real provider events cannot be proven solely from local environment values.

### Initial audit exercise order

Before adding automated repair, exercise the auditor against:

1. Slam Dunk staging as an older active Engage Core deployment;
2. Thompson Square staging as the known environment-file-metadata drift case;
3. Buddy's staging as the fresh launcher-created reference environment.

Do not manually normalize Thompson Square first. Its different but potentially functional metadata is useful evidence that the auditor reports legacy drift without treating it as repair work unless effective access is actually broken.

## Audit-driven fix path

`fix` is intentionally not enabled until the read-only auditor has been exercised against the reference deployments above.

The planned operator flow is:

```text
audit
  ↓
findings
  ↓
fix --dry-run
  ↓
exact proposed safe changes
  ↓
fix --apply
  ↓
audit again
```

The future fix command may automate only explicitly safe deterministic remediation for a `BREAKING` finding, such as repairing proven runtime write access, restoring a required active Horizon process, restoring a required Scheduler entry, or repairing Core-owned Nginx/TLS state that is demonstrably preventing the active deployment from working.

It must not rename or rewrite a healthy legacy database identity, Redis/cache/Horizon prefix, checkout path, Supervisor program, cron marker, Nginx filename, process user, or other operational identity solely to match the convention used by `new`.

It must never silently change remote database credentials, provider credentials/secrets, provider dashboards, DNS, CRM/business data, Project State, or destructive schema/runtime state.


## Normal deployment after launch

Use:

```bash
bash scripts/operations/launch-client-environment.sh update /path/to/state.json
```

The update path:

- pulls only clean approved commits;
- installs production dependencies/builds assets;
- resolves new deployment requirements/provider steps;
- runs platform migrations;
- runs migrations only for already-installed module scopes;
- syncs presets;
- validates;
- refreshes Horizon/Scheduler/Nginx/TLS when needed;
- performs safe runtime verification.

It does not run the production test suite.

## Adding a module later

Enable/test the module in development, commit it in the client repository, and push it first.

Then run, for example:

```bash
bash scripts/operations/launch-client-environment.sh add-modules /path/to/state.json \
  --module scheduling
```

The launcher first resolves any new environment/provider setup obligations exposed by the pulled configuration. It then installs the requested module's schema/dependency closure through `modules:install`, syncs presets, validates, refreshes runtime/hosts, and verifies.

## Root-site ownership

When starting a new Core environment choose one:

```text
core_services_only
managed_main_site
```

`core_services_only` means the root website is external. The launcher must not modify it.

`managed_main_site` records that an SEO Site, Artist Site, or another managed application owns the root website. The current Core launcher still does not point that root domain at Core; the future higher-level site orchestrator will deploy the owning site application separately.

## Audit classification for legacy and pre-cutover installs

Existing-client audit distinguishes **canonical drift** from **live-service defects**.

A noncanonical checkout path, database name/user, runtime prefix, Supervisor program
name, Nginx filename, or equivalent operational identity is `INFO` when it remains a
valid existing value. The audit does not require an older deployment to be renamed
merely to match today's launcher naming.

Before evaluating Core runtime services, audit resolves the enabled Nginx owner of the
CRM hostname from the authoritative `nginx -T` configuration dump. It reasons about
individual application-serving `server` blocks rather than treating an entire site file
as one application. Redirect-only/Certbot companion blocks that repeat the same
`server_name` do not count as a second application owner, and a managed main-site block
may coexist in the same Nginx file while pointing at a different document root. If the
application-serving CRM block points at another checkout, the selected Engage Core
checkout is treated as an inactive/pre-cutover candidate. Runtime-directory gaps and
missing dependencies are then cutover-readiness information/warnings, while missing
Core Supervisor/Scheduler/Nginx state is not misreported as a defect in the currently
served application.

If `vendor/autoload.php` is absent, audit reports Composer-runtime availability once and
does not repeat the same root cause as independent deployment-plan, modules, setup, and
schedule command failures. When dependencies exist, audit runs the bootstrap-safe
`ClientEnvironmentLoader` diagnostic before normal Artisan checks. A client `.env`
ownership violation is reported directly and subsequent Laravel command failures are
suppressed as consequences of that already-proved bootstrap failure.

Redis database/host settings that are omitted and therefore use Core defaults are valid.
Duplicate prefix values found in another `.env` remain warnings until active concurrent
runtime use is proven; file duplication by itself is not proof of a live Redis collision.
The auditor checks cache/Redis/Horizon duplicate values in one pruned `/var/www` environment
scan so large dependency/runtime trees are not traversed three separate times. A
noncanonical prefix that is isolated and functional is never fix-eligible.

A CRM hostname actively served by a legacy application is `INFO` describing current
runtime ownership and a future migration/cutover boundary. It is not a broken Engage
Core deployment and is never eligible for generic `fix --apply`. Multiple enabled Nginx
owners for the same CRM hostname, by contrast, are `BREAKING` because ownership is
actually ambiguous and unsafe.

TLS is validated against the certificate actually served locally by Nginx for each
required Core hostname using SNI/hostname verification. Deploy-user readability of the
certificate file is not treated as a runtime requirement because Nginx may legitimately
read certificate material through its privileged master process.

The auditor also performs an HTTPS CRM login smoke for the active Engage Core checkout.
This lets harmless differences such as a noncanonical PHP-FPM socket remain
informational when the application is actually serving requests successfully.