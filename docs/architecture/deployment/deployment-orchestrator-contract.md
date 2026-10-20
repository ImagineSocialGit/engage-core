# Deployment Orchestrator Contract

## Purpose

The deployment orchestrator automates repeatable host/server work, derives operational names from stable client identity, consumes application-owned deployment requirements, and stops only when an external provider dashboard or operator credential cannot safely be automated.

For Core, the machine authority is:

```bash
php artisan engage:deployment-plan --json
```

The launcher must not carry a second list of module requirements, provider requirements, or Horizon queues.

The cross-platform staging/production data model and database credential boundaries are owned by `docs/architecture/cross-platform-deployment-topology.md`. This Core orchestrator must not duplicate that model.

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
client repository    git@github.com:ExampleOrg/example-client.git
root domain          example.com
client key           example-client
runtime stem         example_client
app path             /var/www/example.com/engage-core
Redis prefix         example_client_
cache prefix         example_client_cache_
Horizon prefix       example_client_horizon:
Supervisor program   example.com-horizon
```

Staging adds `_staging` to the runtime namespace while the staging root domain naturally keeps Supervisor/Nginx names distinct.

When a platform length limit applies, identifiers are truncated only through deterministic hash-suffixed normalization. Silent ambiguous clipping is not allowed.

The executable queue list remains application-owned through `QueueContract`/Horizon configuration. The launcher never accepts an operator-maintained queue list.

Runtime `.env` key ownership, creation and effective-access policy are defined only in `docs/operations/client-environment-reference.md`. The launcher must consume those contracts without maintaining a parallel environment-variable list.

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

## Planner and synchronization invariants

`engage:deployment-plan` is observational. Its default operator output focuses on required keys and active persisted overrides, while `--verbose` expands the matrix and `--json` supplies exhaustive machine fields. Missing, blank, invalid, or persisted-client-mismatched **required** values produce a blocking exit status. Module and provider contributors may declare constrained allowed values; unsupported selections fail before infrastructure or schema mutation.

`EnvironmentVariableCatalog` determines legal names, root/client ownership and sensitivity during bootstrap; contributors determine whether the selected committed client actually needs them. Provider selectors may reveal more requirements, so the launcher reruns the plan after each secure value collection step. A secret expected value must never be serialized in machine results or recorded in the launch state.

`engage:environment:sync --write-missing` may only add absent required names to their catalog-owned environment file. It cannot invent values, replace existing settings, or delete unused keys. Provider requirements and current variable inventories live exclusively in `client-environment-reference.md`; the launcher must not duplicate them.

Package-contributed deployment/setup contributors participate through the documented registries. A module/environment owner with no requirements still needs an explicit zero-requirement contributor so missing planner coverage is detected. Contributors currently cover Core, Forms, Messaging, Inbound Messaging, Internal Notifications, Flow Routes, Webinars, Scheduling, Reporting, and Media; this list is **descriptive of the supplied snapshot**, not a second executable registry.

Deployment planning and application installation always evaluate the complete configured enabled runtime module set; an explicit `engage:install --modules=` schema selection cannot narrow provider/runtime readiness for other enabled capabilities. Unused known environment keys are informational and must not be removed automatically.

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

The launcher provisions **only** hosts resolved for enabled Core capabilities through the application-owned deployment plan. It never assumes ownership of the root website. Exact host derivation and environment URL keys belong to `client-environment-reference.md`; DNS, certificate, and external callback readiness are verified through the operator runbook and provider checklist.

## Implemented Core launcher lifecycle

The current Core launcher supports:

```text
new
resume
update
add-modules
verify
audit
fix
derive
```

The launcher follows dependency ordering rather than a second operator checklist:

1. Resolve and validate client/source identity and intended root-host ownership; pull only approved clean commits.
2. Establish restrictive secret-file and writable-runtime-directory prerequisites **before any Composer hook, package-provider bootstrap, or Artisan invocation**. Install the applicable selected-client locked Composer dependencies before any root Composer hook that bootstraps the Laravel application, then install the locked Core dependencies.
3. Derive the enabled-module Core-owned hosts from the application plan. Establish routing, DNS, and HTTPS callback reachability before asking an operator to complete provider webhook registration.
4. Iterate planner requirements and explicit operator/provider gates; collect values through hidden prompts and secure atomic environment updates.
5. Run the application-owned installation/schema/preset/readiness lifecycle only after its deployment preflight passes.
6. Reconcile only the necessary Nginx, Horizon, and Scheduler state once application readiness is proven, preserving healthy legacy identities.
7. Finish with **read-only** verification of source, security access, services, and enabled capabilities.

Do not build assets or restart shared services gratuitously when the approved deployed inputs and effective configuration have not changed. No launcher flow may widen `.env` permissions simply to make bootstrap succeed.

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

Routine update and module-addition command sequences belong exclusively in `docs/operations/deployment-runbook.md`. The orchestrator applies the same environment-plan, schema preflight, and postflight invariants when updating existing deployments.

## Existing-deployment audit contract

`audit` is state-file-independent and strictly non-mutating. It exists so deployments created before the orchestrator can be compared with the same canonical identity/runtime contract used by `new`.

Required identity inputs are:

```text
environment
client key
root domain
```

The checkout path is discovered from the canonical location and readable `/var/www` client checkouts, with `--app-path` available when a legacy layout is ambiguous.

The auditor checks Core/client Git cleanliness and read-only remote-current identity through `git ls-remote` without fetching or pulling. A source mismatch blocks **authoritative application-runtime interpretation and automatic fixes**, but it must **not suppress independent host-security inspection**: root/client secret-file ownership, other-access bits, and effective authorized/unauthorized readability must still be evaluated and reported. Do not misattribute source-dependent application failures when provenance is untrusted.

The auditor may inspect:

- canonical versus actual checkout path;
- Core/client Git repository state, branch, origin, cleanliness, and remote-current revision;
- root/client environment-file metadata and non-secret identity values;
- Core staging-local versus Core production-remote database topology;
- canonical database/runtime namespace identities;
- duplicate readable Redis/cache/Horizon prefixes across `/var/www`, using one pruned environment scan for all three namespace keys;
- `engage:deployment-plan --json`;
- `modules:status`;
- enabled-module migration state, installation-ledger state, and accepted migration-checksum integrity, using the application migration planner/status inspector read-only;
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

Migration inventory comes from direct-child PHP files inside the paths declared by `config/module_migrations.php`; the config does not duplicate filenames or schema-version counters. The derived schema version is compatibility metadata only. The accepted checksum map in `module_installations` is the history-integrity contract used by audit, preflight, setup validation, and the module executor.

Automatic schema repair may run platform migrations and then preflight, but it must not auto-normalize checksum drift. Changed, removed, or unmanaged applied migration history remains a manual investigation blocker.

Environment secrets in staging and production may **never** be other-readable, group-writable, or other-writable. Access is validated for the actual deploy, PHP-FPM, and Horizon users, and denied for unrelated identities. A file with `0664` is a proven security defect, not a healthy staging convention. The canonical access model and permitted `0640`/`0600`-style arrangements are owned by `client-environment-reference.md`; the auditor tests their effects without repairing the files.

Shared `www-data` read access does not establish tenant separation; report this as an architectural isolation risk rather than silently changing running PHP-FPM or Horizon identities in an audit/fix pass. Runtime-directory write suitability is likewise judged by effective writer access, not only metadata.

## Audit-driven fix contract

`fix` is the state-file-independent reconciliation companion to `audit`.

The normal operator flow is:

```text
pull current Core + client source
audit
fix
  -> review non-breaking drift
  -> supply/approve blocking environment values
  -> confirm eligible deterministic repairs
mandatory re-audit
```

`fix` reruns the full read-only audit before planning or changing anything. It refuses to operate when Core/client source is not clean and remote-current, or when the audited checkout is not the active CRM owner. It never pulls source itself.

Plain `fix` defaults to **interactive reconciliation**. `--dry-run` is non-interactive and non-mutating. `--apply` is non-interactive and applies only the deterministic safe registry. Production mutation, whether triggered by guided environment entry or a deterministic repair, requires typing the root domain once before the first change.

`--only` can restrict the pass to `environment`, `schema`, `runtime`, `nginx`, `horizon`, and `scheduler`; `env`/`provider`/`providers`, `supervisor`, `cron`, and `tls` are accepted aliases. `--all-safe` selects only `schema,runtime,nginx,horizon,scheduler` and deliberately excludes operator-supplied environment/provider values.

### Guided environment and review behavior

The audit classification remains authoritative: `INFO` and `WARNING` do not become automatic mutations. Guided fix may nevertheless surface relevant non-breaking drift/warnings for an operator decision. The fast path is to keep them as-is for the current pass; if the operator rejects one, generic fix stops rather than normalizing it blindly.

Blocking deployment-plan environment requirements are different from automatic repairs. Guided fix may:

- show the application-owned setup-step reason/instructions associated with the blocking keys;
- show non-secret expected values supplied by the deployment contract;
- enforce allowed-value constraints through the existing environment requirement prompt logic;
- accept required secrets through hidden input;
- write only the operator-supplied/approved value to the deployment-plan-owned root or selected-client environment file;
- clear cached configuration and immediately rerun audit before server/runtime repairs continue.

This does **not** permit credential invention. Provider dashboards, credential issuance, DNS changes, account creation, and real-event verification remain external/operator actions. The launcher is allowed to wait for the operator to retrieve a value and enter it; it is not allowed to fabricate one.

### Deterministic automatic repair registry

Only `BREAKING` findings may enter this registry:

- **schema** — when the auditor proves enabled module schema/ledger breakage, run platform migrations first, then use the existing application-owned `modules:install` machinery for only the enabled schema scopes that are not fully current/tracked, followed by preset sync and validation. It never writes migration-ledger rows directly and never installs disabled optional scopes merely because `modules:status` lists them.
- **runtime** — restore effective deploy/web write access only when the active runtime directories are actually `BREAKING`.
- **nginx** — when a required Core hostname has no application-serving owner, add a supplemental Core site and dedicated certificate without rewriting a functional shared/legacy site. DNS must already resolve directly to the server and Certbot must already be available. Ambiguous ownership, wrong-root ownership, DNS changes, and TLS-only cases without the safe missing-host prerequisite remain manual.
- **horizon** — reuse the single existing Supervisor config that already points at the audited checkout; do not rename a healthy legacy program or silently replace process-manager ownership.
- **scheduler** — install the exact marked scheduler entry only when none exists for the active checkout.

Interactive mode asks before each eligible deterministic repair. `--apply` performs the same safe registry without those prompts. Horizon and Scheduler repairs remain deferred until both the deployment plan and `setup:validate` are green after preceding schema/environment repairs.

Canonical Redis/cache/Horizon names, database names/users, checkout paths, Supervisor program names, cron markers, Nginx filenames, or equivalent identities are never automatic fix candidates merely because they differ from a new deployment.

Hard exclusions from automatic mutation remain:

- source pulling or branch changes;
- remote database credentials or account provisioning;
- provider credential/secret generation;
- provider dashboard configuration;
- DNS changes;
- CRM/business data;
- Project State;
- destructive production schema/state operations;
- normalization of healthy legacy names/paths/prefixes.

A finding is not automatically fixable merely because the auditor can detect it. The automatic registry first requires `BREAKING`, then classifies ownership, safety, prerequisites, and restart/reload needs. Guided review/value entry is a separate human-in-the-loop path and does not weaken those automatic-mutation rules.

Every mutating fix attempt ends with a fresh audit. If environment values change, fix re-audits before proceeding to runtime services. If a deterministic repair step fails, the launcher re-audits the resulting state before stopping so the next decision is based on what actually changed.


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