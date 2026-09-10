# Deployment Orchestrator Contract

## Purpose

The deployment orchestrator automates repeatable host/server work, derives operational names from stable client identity, consumes application-owned deployment requirements, and stops only when an external provider dashboard or operator credential cannot safely be automated.

For Core, the machine authority is:

```bash
php artisan engage:deployment-plan --json
```

The launcher must not carry a second list of module requirements, provider requirements, or Horizon queues.

## Environment and database topology

Use this standard topology unless a client has an explicitly documented exception.

| Platform | Development | Staging | Production |
| --- | --- | --- | --- |
| Core | Local database | Local isolated database | Remote client database |
| SEO Sites | Local database | Shared production database in preview mode | Remote client database |
| Artist Sites | Local database | Shared production database in preview mode | Remote client database |

SEO/Artist staging is a preview surface over production content, not a disposable staging database.

For shared-data preview staging:

- use a distinct database credential from the production application;
- prefer read-only database privileges;
- do not run schema migrations, destructive refreshes, seeders, or production-side-effect workers from preview staging;
- treat schema changes as production-database changes and deploy them through a backward-compatible production migration sequence;
- keep preview-only access controls in place while allowing draft/unpublished content to be rendered for review.

Core staging remains isolated and disposable. Core production uses the remote authoritative client database.

## Database identities

Keep the operator/admin database account separate from application credentials.

Recommended model:

- one operator account may retain administrative access across client databases;
- each production application uses its own client-scoped database user;
- that application user is granted only the privileges it needs on the client's database (`client_database.*`), not unrelated client databases;
- shared-preview SEO/Artist staging should use a separate read-only credential when practical;
- remote database provisioning remains an operator gate unless a future database-management integration is explicitly approved.

Core staging is the exception: the launcher may provision the local staging database and local client-scoped application user through `sudo mysql` because that database is deliberately local/disposable.

## Deployment topology

The deployment explicitly records whether the platform owns the root website.

Supported Core-launch topology values:

- `core_services_only`: Core owns CRM and enabled Core public/service hosts; the root website is external and must not be changed.
- `managed_main_site`: the organization also owns the root website through an SEO Site, Artist Site, or another explicitly supported application.

The Core launcher records `managed_main_site` but does not point the root host at Core. SEO/Artist deployment entry points will eventually be orchestrated above this Core launcher.

Root-site ownership affects DNS instructions, certificate host lists, smoke checks, and which Nginx hosts the deployment is authorized to modify.

## Canonical naming

The launcher derives names from:

- environment;
- client repository URL/basename;
- root domain;
- optional explicit client-key override.

Example production identity:

```text
client repository    git@github.com:ImagineSocialGit/slam-dunk-crm.git
root domain          slamdunkhomeloans.com
client key           slam-dunk-crm
runtime stem         slam_dunk_crm
app path             /var/www/slamdunkhomeloans.com/engage-core
Redis prefix         slam_dunk_crm_
cache prefix         slam_dunk_crm_cache_
Horizon prefix       slam_dunk_crm_horizon:
Supervisor program   slamdunkhomeloans.com-horizon
```

Staging adds `_staging` to the runtime namespace while the staging root domain naturally keeps Supervisor/Nginx names distinct.

When a platform length limit applies, identifiers are truncated only through deterministic hash-suffixed normalization. Silent ambiguous clipping is not allowed.

The executable queue list remains application-owned through `QueueContract`/Horizon configuration. The launcher never accepts an operator-maintained queue list.

## Runtime environment files

The launcher creates minimal runtime files. It does not copy `.env.example` wholesale.

Root `.env` owns process/server values such as:

- `APP_ENV`;
- `APP_KEY`;
- `CLIENT_KEY`;
- DB host/transport;
- Redis transport;
- queue/cache/session process defaults;
- root logging/Horizon process overrides.

The selected-client `.env` owns client-varying values such as:

- URLs/root domain;
- database name/user/password;
- Redis/cache/Horizon prefixes;
- provider credentials;
- selected sender identities and client-owned public keys/secrets.

`engage:environment:sync --write-missing` remains the application authority for missing required variable names.

## Core deployment-plan machine contract

`engage:deployment-plan --json` supplies:

- environment;
- client key;
- enabled modules;
- covered deployment owners;
- readiness;
- resolved environment requirements;
- operator/external setup steps;
- present-but-unused environment keys.

Non-secret requirements may expose their `expected_value` to the launcher. Secret expected values are never serialized.

The launcher iterates the plan because provider/capability selectors may reveal additional requirements on a later pass.

## Operator/external setup steps

Deployment-plan contributors may implement `DeploymentSetupContributor`.

Current setup-step families include:

- Cloudflare Turnstile;
- DigitalOcean Spaces;
- Resend;
- Telnyx;
- Zoom;
- signed external Forms intake.

For each active step, the launcher:

1. prints the contributor-owned reason/instructions;
2. pauses while the operator works in the external dashboard;
3. collects related environment values afterward;
4. hides secret input;
5. writes values directly to the correct runtime environment file;
6. records the step as completed in the non-secret launch state;
7. reruns the application plan.

The operator may stop with Ctrl+C at any pause and resume later from the state file.

## Core public hosts

The launcher provisions only Core-owned hosts.

Typical roles:

- CRM/admin host from `CRM_APP_URL` (default derived as `crm.<ROOT_DOMAIN>`);
- `messaging.<ROOT_DOMAIN>` when Messaging is enabled;
- `webinar.<ROOT_DOMAIN>` when Webinars is enabled;
- `webhooks.<ROOT_DOMAIN>` when enabled modules expose provider/server-to-server callbacks;
- the host from `SCHEDULING_APP_URL` when generic public Scheduling is deliberately enabled.

The root domain is never pointed at Core merely because Core is installed.

## Implemented Core launcher lifecycle

The current Core launcher supports:

```text
new
resume
update
add-modules
verify
audit
derive
```

A new environment follows this sequence:

1. collect/derive identity and topology;
2. clone/pull clean Core and client repositories;
3. install production dependencies and build assets;
4. create minimal root/client environment files;
5. provision the local database for Core staging, or collect remote DB credentials for Core production;
6. generate/preserve `APP_KEY`;
7. resolve the enabled-module host set from the current plan even while provider requirements are still incomplete;
8. generate the Nginx site for Core-owned hosts only and install/refresh the existing Core observability integration when available;
9. display exact DNS A records, wait for resolution, and acquire the exact Core TLS certificate through a deterministic Certbot webroot flow;
10. iterate `engage:deployment-plan --json` and `engage:environment:sync --write-missing`;
11. complete provider/external setup steps against already-live HTTPS callback hosts and securely collect values;
12. run `engage:install --force --no-create-user`, `modules:status`, and `setup:validate`;
13. optionally create the initial CRM owner through `engage:user:add`;
14. set runtime permissions;
15. install/update the derived Supervisor/Horizon program;
16. install the exact marked Laravel Scheduler cron entry;
17. run final deployment-plan, module, setup, Horizon, Scheduler, and HTTP checks.

## Launch state

The launcher writes a mode-0600 JSON state file under the deploy user's local state directory.

It contains only non-secret identity/checkpoint information such as:

- client/environment identity;
- derived names/paths;
- topology;
- completed phases;
- completed external setup-step fingerprints;
- DNS/certificate operator metadata.

Secrets never belong in the state file.

## Normal updates

`update <state-file>`:

- pulls approved Core/client commits;
- installs dependencies/builds assets;
- reruns the deployment-plan/setup-step loop for any newly required environment/provider values;
- runs platform migrations, installed-module migrations, preset sync, status, and setup validation;
- refreshes runtime processes/host configuration;
- runs safe verification.

Production tests are not part of this procedure.

## Later module additions

Module enablement is authored/tested in development and committed in the client repository.

After the approved commit is pulled:

```bash
bash scripts/operations/launch-client-environment.sh add-modules /path/to/state.json \
  --module scheduling
```

The launcher refuses a requested module that is not enabled by the pulled client configuration. For each requested module it runs the application-owned `modules:install <module> --force` dependency closure, then preset sync/status/setup validation and runtime/host verification.

This flow may activate new deployment-plan requirements or provider setup steps before schema installation.


## Existing-deployment audit contract

`audit` is state-file-independent and strictly non-mutating. It exists so deployments created before the orchestrator can be compared with the same canonical identity/runtime contract used by `new`.

Required identity inputs are:

```text
environment
client key
root domain
```

The checkout path is discovered from the canonical location and readable `/var/www` client checkouts, with `--app-path` available when a legacy layout is ambiguous.

The auditor may inspect:

- canonical versus actual checkout path;
- Core/client Git repository state, branch, origin, and cleanliness;
- root/client environment-file metadata and non-secret identity values;
- Core staging-local versus Core production-remote database topology;
- canonical database/runtime namespace identities;
- duplicate readable Redis/cache/Horizon prefixes across `/var/www`, using one pruned environment scan for all three namespace keys;
- `engage:deployment-plan --json`;
- `modules:status`;
- `setup:validate`;
- runtime-directory effective access without creating probe files;
- Supervisor/Horizon config/process state;
- exact Scheduler cron identity plus `schedule:list`;
- Core-owned Nginx hosts/document root/PHP-FPM socket from the authoritative `nginx -T` active configuration;
- `nginx -T` / `nginx -t`;
- live TLS hostname validation and expiry against the certificate Nginx actually serves;
- DNS resolution;
- deployment-plan provider/setup steps that still require external verification.

The result vocabulary is:

```text
PASS
INFO
WARNING
BREAKING
MANUAL VERIFICATION REQUIRED
```

`INFO` is harmless historical/canonical drift. `WARNING` is unproven risk, incomplete evidence, or a deliberate review item. `BREAKING` is reserved for a current active-runtime, security, or isolation contract that the auditor can actually prove is failing. Only `BREAKING` findings fail the normal audit result and only `BREAKING` findings may feed the automatic-fix registry.

Nginx runtime ownership is determined from application-serving blocks with a document root. Redirect-only/Certbot companion blocks may repeat the same `server_name`, but they do not count as a second application owner. Multiple distinct application document roots claiming the same CRM hostname are `BREAKING`.

The audit implementation must not call mutating launcher helpers or commands. In particular it must not write environment files/state, run `engage:environment:sync --write-missing`, change permissions, migrate/install schema, alter Nginx/Supervisor/cron, issue certificates, reload services, clear Redis, or change provider/DNS state.

For staging `.env` files, the currently proven convention is deploy-user ownership, deploy-user primary group, and mode `0664`. Runtime-directory correctness is tested through effective deploy/web write access; metadata divergence from the launcher convention is `INFO` when access is still effective.

The staging-proven `.env` mode is not automatically declared the production secret-file mode. Production mode/group remains an explicit review item until that contract is separately proven.

## Audit-driven fix contract

Automated `fix` remains gated on real audit results from an older active Engage Core staging client, the Thompson Square metadata-drift case, and the fresh Buddy's deployment.

The required future flow is:

```text
audit
fix --dry-run
fix --apply
audit
```

`fix --dry-run` must describe every proposed mutation before application. `fix --apply` must require explicit operator intent.

Potential safe deterministic fix classes exist only when the corresponding audit finding is `BREAKING`, for example:

- restoring effective runtime-directory access required by the active process identities;
- repairing Core-owned Nginx/TLS state that prevents a required active host from working;
- restoring a missing/broken active Horizon runtime after process ownership is proven;
- restoring a required Scheduler entry when scheduled work would otherwise not run.

Canonical Redis/cache/Horizon names, database names/users, checkout paths, Supervisor program names, cron markers, Nginx filenames, or equivalent identities are never fix candidates merely because they differ from a new deployment.

Hard exclusions from silent fix behavior include:

- remote database credentials or account provisioning;
- provider credentials/secrets;
- provider dashboard configuration;
- DNS changes;
- CRM/business data;
- Project State;
- destructive production schema/state operations.

A finding is not automatically fixable merely because the auditor can detect it. The fix registry first requires `BREAKING`, then must classify ownership, safety, prerequisites, and whether a restart/reload is required before exposing an apply action. `INFO`, `WARNING`, and `MANUAL VERIFICATION REQUIRED` are categorically excluded from automatic fix.


## Remaining higher-level work

The Core launcher deliberately does not yet deploy an SEO Site or Artist Site. A later host/client orchestrator can call compatible sub-deployment entry points for those platforms while retaining the topology/data-mode rules defined here.

Shared-production-data SEO/Artist preview staging must remain protected from migrations, destructive resets, and production-side-effect workers even when that higher-level orchestrator is added.

## Legacy deployment audit classification

The read-only auditor must distinguish four different states:

1. **live current Core defect** — the audited Engage Core checkout owns the CRM hostname
   and a required runtime contract is actually broken;
2. **legacy canonical drift** — a populated path/name/prefix differs from today's
   deterministic naming but remains a potentially valid existing identity;
3. **inactive/pre-cutover Engage Core checkout** — the checkout exists but the CRM
   hostname is currently served by another application;
4. **external/manual state** — provider-dashboard or infrastructure facts that cannot be
   proven safely from the host.

Only the first category can become an automatic-fix candidate, and only after the
specific finding is classified `BREAKING`. Legacy canonical drift is not normalized at
all merely for consistency. A pre-cutover checkout owned by another live application
requires a migration/cutover plan rather than a generic repair.

The auditor discovers CRM host ownership from the active `nginx -T` configuration and
reasons at the individual `server`-block level. Redirect-only blocks do not establish
application ownership. A root/main-site block in the same Nginx file is valid when it
points at a different application document root; the Core root-domain guard fails only
when the root hostname itself is actually served from the Core document root. When a
different document root owns the CRM host, missing Core Supervisor/Scheduler/Nginx
entries for the inactive checkout are not counted as live-service failures.

TLS correctness is judged from the certificate actually served for each Core hostname,
not from whether the deploy user can directly read the configured certificate file.
Nginx may legitimately rely on privileged master-process access to certificate material.

Before ordinary Artisan checks, the auditor runs the bootstrap-safe selected-client
environment ownership diagnostic. If that diagnostic proves an early bootstrap failure,
the root cause is reported once and dependent application commands are marked
unevaluated rather than emitting repeated secondary framework exceptions.

Omitted root/process values with documented Core defaults, including Redis host/database
defaults, are valid. A duplicate namespace found only by scanning another environment
file is a warning until concurrent runtime use is independently established. An isolated,
functioning legacy namespace remains valid even when its formatting differs from the
current derivation rule.