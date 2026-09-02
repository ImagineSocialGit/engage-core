a# Engage Core — Client Staging & Production Setup Checklist

## Purpose

This is the canonical operational checklist for bringing a new Engage Core client from local configuration through staging validation and production launch.

Use it for a new client installation, a new environment for an existing client, or a migration from a legacy application into Engage Core.

This checklist intentionally separates:

1. local/developer preparation;
2. third-party service setup;
3. staging server deployment;
4. staging validation;
5. production server deployment;
6. production smoke testing and launch.

Third-party provider work is detailed in `client-third-party-services-checklist.md`.
Environment-variable ownership and staging/production differences are detailed in `client-environment-reference.md`.
Operational failure modes and destructive-reset safety are detailed in `deployment-safety-and-troubleshooting.md`.
The canonical command-level install and deployment sequence is detailed in `operations/deployment-command-workflow.md`.

## Authority

When deployment documentation and executable behavior disagree, use this order:

1. database schema for persisted fields;
2. runtime DTOs, actions, services, consumers, handlers, and resolvers;
3. registered config/token contracts;
4. setup validation and runtime tests;
5. default/client config;
6. templates and prose documentation.

Do not preserve a stale deployment assumption merely because it appears in an older checklist.

---

# Placeholder conventions

Replace every placeholder before staging or production handoff.

```text
<CLIENT_KEY>                 Example: example-client
<ROOT_DOMAIN>                Example: example.com
<STAGING_ROOT_DOMAIN>        Example: staging.example.com
<APP_PATH>                   Example: /var/www/<ROOT_DOMAIN>/engage-core
<CLIENT_PATH>                Example: /var/www/<ROOT_DOMAIN>/engage-core/client/<CLIENT_KEY>
<DEPLOY_USER>                Example: deploy
<WEB_USER>                   Example: www-data
<PHP_BIN>                    Example: /usr/bin/php8.3
<SUPERVISOR_PROGRAM>         Example: <ROOT_DOMAIN>-horizon
<CLIENT_HORIZON_PROGRAM>     Actual Supervisor program that runs this client's Horizon process
<GITHUB_ORG>                 Example: YourGitHubOrg
<ENGAGE_CORE_REPO>           Example: engage-core
<CLIENT_REPO>                Example: <CLIENT_KEY>
<GITHUB_SSH_HOST_ALIAS>      Example: github-<CLIENT_KEY>-deploy
<DB_NAME>                    Example: engage_core_<CLIENT_KEY>_production
<DB_USER>                    Example: engage_core_<CLIENT_KEY>
<REDIS_PREFIX>               Example: <CLIENT_KEY>_production_
<HORIZON_PREFIX>             Example: <CLIENT_KEY>_production_horizon:
```

---

# Phase 0 — Client preparation and local validation

Complete this before provisioning or changing a server.

## 1. Confirm client identity and package composition

- [ ] `CLIENT_KEY` is final and matches the client directory/repository identity.
- [ ] `client/{CLIENT_KEY}/config/client.php` selects the intended preset and stable client timezone.
- [ ] `client/{CLIENT_KEY}/config/modules.php` explicitly selects runtime product modules.
- [ ] Client-facing contact labels are correct.
- [ ] Required client config files exist.
- [ ] No placeholder domains, sender addresses, phone numbers, provider IDs, or secrets remain in client configuration.

Keep these concepts separate:

```text
CLIENT_KEY
    selects client/{CLIENT_KEY} and therefore the active client environment and configuration

client config/client.php
    selects preset composition and stable client timezone

client config/modules.php
    selects explicitly enabled runtime product modules

DB-owned selections/bindings
    decide which synced definitions actually run
```

Enabling a module must not be treated as automatically activating every preset it contributes.

## 2. Confirm required feature/provider matrix

Decide explicitly whether the client needs:

```text
Messaging / email
SMS
Inbound Messaging
Internal Notifications
Broadcasts
Campaigns
Webinars
FlowRoutes / Routes
Tasks
Reporting
other optional modules
```

Then decide which external services are required:

```text
GitHub repository access/deploy keys
DNS provider
DigitalOcean Spaces
Resend
Telnyx
Zoom
```

Do not provision provider credentials for a feature that is not part of the intended client package unless there is a deliberate shared-infrastructure reason.

## 3. Review client config against current Core contracts

When applicable, review:

- [ ] Messaging email definitions.
- [ ] Messaging SMS definitions.
- [ ] Permission-invitation public copy/config.
- [ ] Webinar schedule profiles.
- [ ] Webinar post-event behavior.
- [ ] Campaign presets and channel variants.
- [ ] FlowRoute presets and trigger bindings.
- [ ] Task templates.
- [ ] Contact statuses.
- [ ] Forms preset groups and server-owned submission mappings when Forms is enabled.
- [ ] External Forms caller allowlist/HMAC environment when Engage Sites will call Core.
- [ ] Channel + purpose consent intent for every selected FormVersion that can grant Messaging consent; acknowledgement-domain mapping is optional context/copy configuration only.
- [ ] Client key/token extensions.

For an Artist Sites client using the reusable Core form, confirm before deployment:

```text
selected preset package includes groups.forms = ['artist_updates']
FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS includes artist_updates
email_marketing_consent maps to email / marketing when Messaging is enabled
sms_marketing_consent maps to sms / marketing if the selected artist_updates
  contract retains SMS consent
optional acknowledgement-domain mappings do not alter those permission boundaries
```

Do not switch the public Artist Sites destination to Core yet. Package selection and Core readiness come first.

Core rules that matter before deployment:

```text
Messaging templates own reusable copy and delivery-template metadata.
Owning modules own lifecycle timing/conditions/enablement.
Campaign presets own campaign timing and progression, not reusable message copy.
Webinar schedule profiles own Webinar lifecycle timing.
Preset composition and runtime module availability remain separate decisions.
SMS availability is a Messaging channel-availability decision, not merely provider credentials.
Normal Broadcasts remain consent-gated.
Imported-contact permission invitations are a distinct Messaging-owned flow.
```

## 4. Run local validation

Use the actual repository-supported commands. At minimum:

```bash
composer install
npm ci
npm run build
php artisan optimize:clear
php artisan presets:sync
php artisan setup:validate
```

Also run focused and adjacent tests for the modules/configs being introduced or changed.

Staging/client handoff rule:

```text
setup:validate errors must be resolved.
warnings must be understood and intentionally accepted.
```

## 5. Commit and push the intended deployment state

- [ ] Engage Core changes committed and pushed, if any.
- [ ] Client repository/config changes committed and pushed.
- [ ] Deployment branch/tag/commit identified.
- [ ] No uncommitted local-only config is required for the deployment to work.

Staging and production are deployment targets, not source-editing environments. Make application/client config changes in development, test them there, commit/push them, and deploy by pulling the approved revision. Direct server edits are reserved for environment files, server/process configuration, secrets, and emergency operational recovery—not normal PHP/config/source changes.

---

# Phase 1 — Third-party services

Complete the relevant sections in:

```text
client-third-party-services-checklist.md
```

At minimum, determine and record environment-specific values for:

```text
DNS and hostnames
GitHub deploy access
DigitalOcean Spaces
Resend
Telnyx
Zoom
```

Staging and production must not accidentally share provider webhook endpoints, credentials, numbers, buckets, or sender identities unless sharing is deliberate and documented.

---

# Phase 2 — Staging server deployment

Staging is a first-class deployment gate. Do not treat production as the first realistic integration test.

For repeatable new-environment provisioning after the Core checkout exists, `scripts/operations/launch-client-environment.sh` may automate the repository/dependency/env-permission/install/runtime checks in phased steps using `docs/operations/launch-client-environment.example.conf`. The helper does not own secrets, DNS, Nginx, TLS, provider accounts, or database-user creation; the detailed checklist below remains authoritative.

## 6. Provision the staging application path

Example:

```text
/var/www/<STAGING_ROOT_DOMAIN>/engage-core
```

Verify the intended deployment user, web user, PHP version, PHP-FPM socket, Composer, Node/npm, MySQL client access, Redis access, Nginx, Supervisor, and required PHP extensions.

Also verify the host has enough memory and disk headroom for the deployment toolchain:

```bash
free -h
swapon --show
df -h /
```

Composer, npm, and Vite builds can create short-lived memory spikes. Swap is not an Engage Core application requirement, but a small production host with limited RAM and no swap should be corrected before relying on in-place server builds. Choose swap size from the actual host capacity and workload; do not copy one client's value blindly.

## 7. Inspect SSH keys and host aliases before cloning

Useful checks:

```bash
ls -la ~/.ssh
cat ~/.ssh/config
ssh -T git@<GITHUB_SSH_HOST_ALIAS>
```

Do not assume `github.com` is the correct SSH host when deploy keys use client-specific aliases.

## 8. Clone the Engage Core and client repositories

Use the actual approved repository layout.

Example pattern:

```bash
git clone git@<GITHUB_SSH_HOST_ALIAS>:<GITHUB_ORG>/<ENGAGE_CORE_REPO>.git <APP_PATH>
```

Install or clone the client package in the location expected by Engage Core. When the client directory is its own repository, verify Core and the client repository independently; a clean/current Core checkout does not prove the nested client checkout is current.

```bash
cd <APP_PATH>
git status
git branch --show-current
git log -1 --oneline --decorate
git remote -v

cd client/<CLIENT_KEY>
git status
git branch --show-current
git log -1 --oneline --decorate
git remote -v
```

Before syncing presets, verify effective client identity:

```bash
php artisan tinker --execute="dump([
    'client_key' => config('client.key'),
    'client_preset' => config('client.preset'),
    'client_timezone' => config('client.timezone'),
    'enabled_modules' => config('modules.enabled'),
]);"
```

## 9. Install dependencies and build assets

Typical staging/production sequence:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Use the project's actual supported deployment commands if they differ.

## 10. Set permissions

Keep application source owned by the ordinary deployment user. Do not recursively give the checkout to the web server.

The root and selected-client `.env` files must be readable by both the deployment/Artisan identity and PHP-FPM without being world-readable. For the common `<DEPLOY_USER>` + `www-data` deployment:

```bash
sudo chown <DEPLOY_USER>:<WEB_GROUP> <APP_PATH>/.env <APP_PATH>/client/<CLIENT_KEY>/.env
sudo chmod 640 <APP_PATH>/.env <APP_PATH>/client/<CLIENT_KEY>/.env
```

Do not use `0600` merely because the files contain secrets when PHP-FPM runs as another user and loads them directly. CLI success does not prove the web runtime can read the same environment.

Verify every process identity that must create or update runtime files can write where required:

```text
storage/
bootstrap/cache/
```

Typical deployments may use different identities for:

```text
deployment / Scheduler user
PHP-FPM user
Supervisor/Horizon user
```

A directory showing `775` is not sufficient proof when those identities have different primary groups. A newly created file can still be writable only by its creator and creator group.

Before handoff, perform a real cross-user write check in a disposable file:

```text
deploy user creates -> web/worker user updates
web/worker user creates -> deploy user updates
```

Both directions must succeed when both identities are expected to write that tree.

Use the server's deliberate shared-write policy—such as shared groups with inherited group ownership or POSIX default ACLs—rather than repeatedly applying broad `chmod`/`chown` after deploys. When POSIX ACLs are used, apply access to existing files/directories and default ACLs to directories so future files inherit the same writable identities.

Do not blindly copy ownership/ACL commands between servers. Confirm the actual deployment, web, Scheduler, and worker users first.

A robust baseline when the checkout owner is `<DEPLOY_USER>` and PHP-FPM/Horizon use `<WEB_GROUP>` is:

```bash
sudo chown -R <DEPLOY_USER>:<WEB_GROUP> storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;
```

The setgid directory bit keeps newly created runtime files in the shared group. A recursive `775` policy may also work on an existing tree but gives execute permission to ordinary files and does not by itself prove future cross-user group inheritance. Keep the two-way write test as the authority.

## 11. Create the staging root and client environments

Start from the root `.env.example` and the selected client's `.env.example`.

Required staging differences:

```env
APP_ENV=staging
APP_DEBUG=false
```

Use the root `.env` for:

```text
CLIENT_KEY
APP_ENV
APP_DEBUG
APP_KEY
DB connection host/port
Redis host/port/database indexes
queue/process tuning
logging
staging access
process-wide storage/provider controls such as FILESYSTEM_DISK and webhook timestamp tolerances
```

Use `client/{CLIENT_KEY}/.env` for:

```text
APP_URL and host URLs
DB database/credentials
CACHE_PREFIX
REDIS_PREFIX
HORIZON_PREFIX
provider credentials and webhook secrets
sender identities and phone numbers
PROJECT_STATE_ADMIN_EMAIL when the owner-only transfer surface is deliberately enabled
storage credentials/bucket/CDN URL
other selected-client deployment values
```

Do not leave placeholder values such as `DOMAIN`, `CHANGE_ME`, empty required sender addresses, or blank provider secrets in an environment intended for handoff testing.

The selected-client environment is strictly validated. Do not copy root/process keys into `client/{CLIENT_KEY}/.env` merely because they relate to the client. A root/client ownership mismatch can fail during very early bootstrap before Laravel's normal exception reporting is fully available.

After creating the files, verify read access without printing secrets:

```bash
test -r .env && test -r client/<CLIENT_KEY>/.env
sudo -u <WEB_USER> test -r .env
sudo -u <WEB_USER> test -r client/<CLIENT_KEY>/.env
```

## 12. Generate the staging application key

For a new environment only:

```bash
php artisan key:generate
```

Do not regenerate a key after encrypted application data exists unless key rotation is deliberate and supported.

## 13. Configure and verify MySQL

The current deployment path is MySQL 8.

Verify root `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
```

Verify selected client `.env`:

```env
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Then test the application connection before migrations.

## 14. Configure and verify Redis isolation

Current stack expectations:

```env
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

Use an environment-specific namespace and/or Redis DB separation.

Recommended root `.env` values:

```env
REDIS_DB=0
REDIS_CACHE_DB=1
```

Recommended selected client `.env` values:

```env
REDIS_PREFIX=<CLIENT_KEY>_staging_
CACHE_PREFIX=<CLIENT_KEY>_staging_cache_
HORIZON_PREFIX=<CLIENT_KEY>_staging_horizon:
```

If Redis is shared with other apps or environments, uniqueness is mandatory.

Inspect effective config when needed:

```bash
php artisan tinker --execute="dump([
    'queue_default' => config('queue.default'),
    'redis_default' => config('database.redis.default'),
    'redis_cache' => config('database.redis.cache'),
    'queue_redis' => config('queue.connections.redis'),
    'cache_prefix' => config('cache.prefix'),
    'horizon_prefix' => config('horizon.prefix'),
    'horizon_use' => config('horizon.use'),
    'horizon_environment' => config('horizon.environments.'.app()->environment()),
]);"
```

## 15. Configure staging access protection

When the staging access middleware/gate is used, set:

```env
STAGING_USER=
STAGING_PASSWORD=
```

Use strong unique credentials. Do not reuse production application-user passwords.

## 16. Configure DNS, Nginx, and SSL

Typical deployment roles:

```text
<root domain>
    standard site domain

<Core admin subdomain>.<root domain>
    human-facing Engage Core administration; commonly crm or app

webinar.<root domain>
    public Engage Core Webinar host when enabled

webhooks.<root domain>
    public Engage Core webhook and signed integration host
```

Use the actual environment topology; do not assume the Core admin label is always `crm` or that staging must use the exact same naming pattern as production. `CRM_APP_URL` remains the canonical Core environment key even when the deployed admin hostname uses `app`.

For every hostname:

- [ ] DNS resolves to the intended staging server.
- [ ] Each Core-owned hostname points to the intended Engage Core `public/` directory.
- [ ] The standard site domain points to its owning site application rather than Engage Core when the products are deployed together.
- [ ] PHP-FPM socket/version is correct.
- [ ] SSL is valid.
- [ ] No hostname still points to a legacy checkout.

Validate before reload:

```bash
sudo nginx -t
```

After `.env` changes:

```bash
php artisan optimize:clear
php artisan route:list
```

Confirm route hosts are correct. In particular, the CRM route domain must match the hostname from `CRM_APP_URL`; do not assume it is always `crm.<ROOT_DOMAIN>`.

After SSL is valid, use real application routes for HTTP smoke checks:

```bash
curl -sS -D - -o /dev/null https://<CORE_ADMIN_HOST>/
curl -sS -D - -o /dev/null https://<CORE_ADMIN_HOST>/login
```

A guest redirect to login/staging access is healthy. For the webhooks host, `/` may legitimately be 404 because no root route exists. When external Forms is enabled, the useful unsigned transport smoke is:

```bash
curl -sS -o /tmp/forms-smoke.json -w '%{http_code}\n' \
  -H 'Accept: application/json' \
  https://webhooks.<ROOT_DOMAIN>/forms/<FORM_KEY>
```

A `401` `authentication_failed` response proves DNS, SSL, Nginx, PHP-FPM, Laravel host routing, the Forms route, and its authentication middleware are all being reached.

## 17. Configure Supervisor/Horizon

A Supervisor program should point to the exact intended checkout.

Generic example:

```ini
[program:<SUPERVISOR_PROGRAM>]
process_name=%(program_name)s
command=<PHP_BIN> <APP_PATH>/artisan horizon
directory=<APP_PATH>
autostart=true
autorestart=true
user=<WEB_USER>
redirect_stderr=true
stdout_logfile=<APP_PATH>/storage/logs/horizon.log
stopwaitsecs=3600
```

Verify all three paths:

```text
command=
directory=
stdout_logfile=
```

Reload using the server's operational process, for example:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart <SUPERVISOR_PROGRAM>
```

Then verify the actual process path:

```bash
ps aux | grep "[a]rtisan horizon"
```

Do not trust Supervisor config alone. Confirm the process that is actually running.

Before restarting Horizon, inspect the actual Supervisor program name instead of guessing it:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
```

Use the exact matching program name as:

```text
<CLIENT_HORIZON_PROGRAM>
```

Operational rule:

> After deploying PHP changes that affect queued job execution, job validation, payload rendering, gates, providers, or other queue-worker runtime behavior, restart the Supervisor-managed Horizon process so all workers load the new code.

```bash
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

Supervisor is the lifecycle source of truth for this deployment path. Do not substitute an Artisan Horizon lifecycle command for the Supervisor restart when Supervisor owns the process.

## 18. Configure the Horizon queue list explicitly

The current executable/configured queue set includes:

```text
default
notifications
confirmation_messages
opt_in_messages
reminders
post_event
marketing
emails
sms
webinars
webhooks
```

Use an explicit `HORIZON_SUPERVISOR_1_QUEUES` value until Core's built-in Horizon defaults are confirmed to reflect every executable queue path.

Example:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,opt_in_messages,reminders,post_event,marketing,emails,sms,webinars,webhooks
```

Current runtime notes:

```text
emails is an active queue path.
Webinar waitlist delivery uses notifications; there is no canonical separate waitlist queue requirement.
Do not preserve an old campaigns queue requirement from stale Webinar nurture config.
```

Horizon must consume every queue the current runtime can actually dispatch to. Verify effective runtime configuration rather than trusting a historical `.env` queue list.

### Configure Laravel Scheduler

Engage Core uses Laravel Scheduler for database-backed recovery and outbox reconciliation. Redis/Horizon remains the primary delayed-job execution path, but the Scheduler must run so due message-chain enrollments, stale delivery claims, and unpublished outbox events can be recovered.

Install exactly one cron entry for this client deployment under the intended deployment/process user:

```cron
* * * * * cd <APP_PATH> && <PHP_BIN> artisan schedule:run >> /dev/null 2>&1
```

Verify the entry and the effective schedule:

```bash
sudo crontab -u <DEPLOY_USER> -l
cd <APP_PATH>
<PHP_BIN> artisan schedule:list
```

Do not run `schedule:work` in parallel with the cron entry. The working directory and environment must resolve the intended `CLIENT_KEY`, database, Redis namespace, and client configuration.

## 19. Apply staging schema

For a brand-new staging database after client configuration is complete, use the full installer:

```bash
php artisan engage:install --force
```

That command applies platform migrations, installs the configured schema-owning module dependency closure, synchronizes presets, and runs setup validation. It does not rewrite runtime module configuration.

For an existing staging database, use the normal upgrade path:

```bash
php artisan migrate --force
php artisan modules:migrate --force
```

After the modular migration path-selection cutover, plain `migrate` is platform-only. `modules:migrate` upgrades only ledger-installed module scopes. Do not substitute a broad `migrate --path` scan of optional module directories.

A destructive reset is acceptable only when the environment data is disposable and queued Redis state has been handled first. See `deployment-safety-and-troubleshooting.md`.

## 20. Sync DB-owned definitions

Run the canonical orchestrator when preset/config definitions changed or when this stage was not already completed by `engage:install`:

```bash
php artisan presets:sync
```

Rerunning preset sync after a successful `engage:install` is allowed but normally redundant unless configuration changed afterward.

Current sync architecture may materialize, when selected/enabled:

```text
ContactStatus definitions
Task templates
Forms definitions/immutable published versions
Messaging template presets/assignments/catalog entries
Webinar schedule profiles/items
Campaigns/steps/variants
FlowRoute capabilities
FlowRoutes/points/bindings
```

Do not assume an old list of separate sync commands remains necessary. Use the current orchestrator and only run extra commands when current source explicitly requires them.

## 20A. Run applicable module-specific post-install commands

`engage:install` owns platform/module schema installation, selected preset materialization, and its initial setup-validation pass. `presets:sync` owns configured DB definitions. Do not rerun older module-specific sync commands for work already covered by those orchestrators.

Run only the entries whose condition applies. The canonical registry is maintained in `deployment-command-workflow.md`.

### Forms — external-intake credential issuance

Run this when Forms is enabled for a server-to-server external intake client and that environment does not already have its valid client ID/signing-secret pair:

```bash
php artisan forms:external-intake:issue-secret [client]
```

When exactly one external client is configured, the optional client argument may be omitted. The command prints matching Engage Core and caller environment blocks; it does not write either environment.

- [ ] Issue a distinct pair for this environment.
- [ ] Copy the Core block into the selected Core client environment.
- [ ] Copy the caller block into the matching external application environment.
- [ ] Run `php artisan optimize:clear` in each application after changing its environment.
- [ ] Never reuse the production pair in staging or the staging pair in production.

The command is bootstrap-safe when the current Core secret is blank or invalid. If `engage:install` reached its final validation stage and reported the missing/invalid Forms client configuration, issue and install the credential here, clear cached configuration, and continue with the explicit setup-validation gate below.

No other current module requires a mandatory module-specific post-install Artisan command beyond `engage:install` and `presets:sync`. Provider probes, smoke tests, user creation, and production process restarts remain in their owning checklist sections rather than this registry.

## 21. Run setup validation

Run setup validation after any additional config or preset changes, even when the initial install already completed its validation stage:

```bash
php artisan modules:status
php artisan setup:validate
```

Gate:

```text
errors: block handoff
warnings: understand and deliberately accept or resolve
clean: proceed
```

Do not auto-fix validation failures by broadening config contracts or adding unsupported config keys.

### Artist Sites Forms staging integration gate when used

Complete this after Core preset sync/setup validation and before the public Artist Sites newsletter destination is changed to `engage_core`.

Core staging must first satisfy:

```text
[ ] Forms enabled for the selected client
[ ] selected preset package includes forms group artist_updates
[ ] public current artist_updates FormVersion exists
[ ] external Forms intake enabled
[ ] matching external client ID/secret configured
[ ] allowed_forms includes artist_updates
[ ] email_marketing_consent maps to email / marketing when Messaging consent is used
[ ] sms_marketing_consent maps to sms / marketing when the selected form retains SMS consent
[ ] setup:validate has no Forms runtime errors
```

Keep the reusable `artist_updates` verification policy at `required=false` for the first transport proof. The reusable policy still validates any supplied Turnstile attestation against provider `turnstile`, action `artist_updates`, hostname presence, and a 300-second maximum age.

From the matching Artist Sites staging environment, while Mailchimp may still be the live newsletter destination, run:

```bash
php artisan site:probe-core-form artist_updates
```

That read-only GET must prove the intended Core environment, HMAC client/secret pair, form allowlist, and current public schema before cutover.

A successful probe is not a POST-readiness proof. Before changing `NEWSLETTER_DESTINATION=engage_core`, confirm the deployed Artist Sites sender submits the fields required by the current Core FormVersion. In particular, the reusable Core contract requires explicit accepted `email_marketing_consent`; a Sites build that still posts only `email` is not ready for cutover.

Once the Sites sender matches the published contract:

```text
1. Change the staging Artist Sites destination to engage_core and clear its cached configuration.
2. Ensure the public newsletter/intake surface itself is active; selecting a destination does not activate a disabled site surface.
3. Submit ONE controlled real public artist_updates form while Core verification remains optional. Turnstile may already be configured, but it is not required for this first transport proof.
4. Verify Core created/reused the intended Contact.
5. Verify Core stored FormSubmission + pinned FormVersion + typed FormSubmissionValues.
6. Verify expected interest:* Contact tags.
7. Verify accepted email/SMS marketing fields produced channel + purpose Messaging consent only when those fields were explicitly true.
8. If Artist Sites supplied normalized Turnstile evidence, verify it is present and verify no raw Turnstile token/provider payload was persisted.
9. Replay the same external UUID only as an explicit idempotency check; it must not duplicate the submission or consent grant.
10. Configure Artist Sites human verification (Turnstile for the current reference implementation), run `site:check`, and verify the exact staging hostname is allowed.
```

Only after the controlled transport POST succeeds and the Artist Sites human-verification check is green should the selected Core client promote verification to required with:

```text
client/{client-key}/config/presets/modules/forms/forms.php
    definitions.artist_updates.settings.submission.verification.required = true
```

Then run:

```bash
php artisan presets:sync
php artisan setup:validate
```

Confirm a new immutable current FormVersion was published, rerun the Artist Sites read-only probe, then perform one more controlled real submission. Confirm the Turnstile widget is present in the browser and the required-verification path succeeds end to end.

## 22. Create the initial CRM user when required

For an interactive new-client installation, `engage:install` offers to create the first CRM user after the four installation stages succeed.

If user creation was skipped, or another CRM user is needed later, run:

```bash
php artisan engage:user:add
```

The command prompts for name, email, password, and password confirmation. Password input is hidden and is not stored in `.env`.

If a CRM password is forgotten, reset it explicitly:

```bash
php artisan engage:user:password user@example.com
```

Do not use `db:seed`, `UserSeeder`, or `SETUP_USER_*` environment values for operational CRM users.

See `operations/crm-user-administration.md` for the complete contract.

---

# Phase 3 — Staging validation and smoke tests

## 23. Verify application URLs

Check the actual intended hosts:

```text
root/public site
CRM
webinar
webhooks
```

## 24. Verify effective environment and client identity

```bash
php artisan tinker --execute="dump([
    'app_env' => app()->environment(),
    'app_url' => config('app.url'),
    'root_domain' => config('app.root_domain'),
    'crm_url' => config('app.crm_url'),
    'webinar_url' => config('app.webinar_url'),
    'client_key' => config('client.key'),
    'client_preset' => config('client.preset'),
    'client_timezone' => config('client.timezone'),
    'enabled_modules' => config('modules.enabled'),
]);"
```

## 25. Verify queue/Horizon health

- [ ] Correct Supervisor program running.
- [ ] Correct checkout path running.
- [ ] Correct Horizon environment selected.
- [ ] All required queues consumed.
- [ ] No unexpected failed jobs.
- [ ] Redis prefixes understood.
- [ ] Laravel Scheduler cron installed for this client deployment.
- [ ] `php artisan schedule:list` shows the expected Messaging recovery/outbox tasks.

## 26. Verify email

When Messaging/email is enabled:

```text
Resend API works
sending domain/capability verified
transactional From identity resolves
marketing From identity resolves
Open Tracking remains off
Click Tracking remains off
delivery/lifecycle webhook reaches /message-events/email/resend with a valid signature
real staging-safe email reaches sent/delivered path
```

When Inbound Messaging email is enabled, also verify:

```text
receiving domain/capability verified
INBOUND_EMAIL_DOMAIN matches that receiving domain
RESEND_API_KEY has Full Access
inbound webhook sends only email.received to /inbound/email/resend
real reply is retrieved through the Resend API and recorded/correlated in Engage Core
```

## 27. Verify SMS

When SMS is enabled:

```text
SMS_ENABLED=true
provider resolves to telnyx unless intentionally changed
effective Messaging channel availability reports provider_enabled = true for the intended SMS surface/purpose/scope
transactional number resolves
marketing number resolves
profile IDs resolve when required
webhook public key/signature verification works when inbound events are used
real staging-safe SMS reaches sent path
STOP/HELP behavior remains protected
```

Do not infer SMS availability solely from provider credentials. Confirm Messaging channel availability and intended UI surfaces.

## 28. Verify permission invitations when used

- [ ] Public URL configured.
- [ ] Email invitation can be scheduled for eligible imported contacts.
- [ ] Normal Broadcast consent rules are not bypassed.
- [ ] Invitation acceptance writes intended consent state.
- [ ] Existing/failed/pending invitation rules behave as expected.

## 29. Verify Webinar setup when enabled

Run `php artisan setup:validate` before provider smoke tests. The Webinar contributor
must report no Zoom credential, endpoint, provider-adapter, webhook-mapping, token-TTL,
or timestamp-drift findings.

At minimum:

```text
Zoom Server-to-Server OAuth credentials work.
Both Webinar and Meeting adapters resolve for the configured Zoom provider.
Webinar lookup works when Webinar event types are used.
Meeting lookup works when Meeting event types are used.
Registration API works for each event type in use.
Personalized join URL is stored.
Registration confirmation planning works.
Schedule profile is selected and active.
Future reminders are scheduled correctly.
A real signed webinar.ended webhook is accepted when Webinars are used.
A real signed meeting.ended webhook is accepted when Meetings are used.
Attendance-report capability works through the exact provider call used by runtime.
Cloud-recording lookup works when replay follow-ups are enabled.
recording.completed can resolve playback.
Post-event follow-ups wait for required playback conditions.
webinar.attended / webinar.missed automation events work.
Selected Routes run.
Campaign enrollments occur when intended.
```

Current Core post-event orchestration is split by normalized event identity:

```text
webinar.ended
    Native source may be webinar.ended or meeting.ended.
    Records provider attendance for the resolved occurrence.

webinar.recording_completed
    Native source is recording.completed.
    Resolves playback.
    Dispatches post-event follow-ups.
```

Do not assume an ended event alone sends replay follow-ups.

Use `client-third-party-services-checklist.md` for the exact current granular Zoom
scope list. Registration/lookup, Meeting reports, Webinar reports, and cloud recording
lookup are separate capabilities; do not assume one permission category implies the
others.

Do not treat route existence or configured event subscriptions as proof of webhook
readiness. Verify that a real signed provider webhook reaches the intended environment,
passes signature verification, dispatches to a consumed queue, and produces the
expected domain action.

When Webinar-to-Meeting replacement is supported for the client, complete this staging
smoke before launch:

```text
1. Preserve the original Webinar occurrence and historical registrations.
2. Change the series type only for future synchronization.
3. Sync the replacement Meeting occurrence.
4. Confirm the explicit occurrence replacement in CRM.
5. Verify per-registration reprovisioning totals and individual recovery controls.
6. Verify old join, thank-you, and cancellation links follow the canonical registration.
7. Verify consent acknowledgements and confirmations are not duplicated.
8. Verify only future-valid reminders are scheduled for the replacement.
```

For production post-event handling, use this safe sequence:

```text
1. Verify the Zoom app has the capabilities required by the event types in use.
2. Verify attendance state.
3. Resolve duplicate/cancelled registration conflicts before follow-up dispatch when necessary.
4. Retry only the failed post-event provider job.
5. Confirm Webinar.playback_url contains the real recording URL.
6. Confirm follow_ups_dispatched_at is populated.
7. Inspect the actual ScheduledMessage rows.
8. Verify replay URL, expected CTAs/links, recipient eligibility, statuses, and send timing.
9. Inspect Horizon Delayed Until and/or serialized queue delay metadata before touching Redis.
10. Restart Supervisor-managed Horizon after queued-job code changes.
11. Surgically retry only the affected skipped/failed messages.
12. Verify final message statuses.
```

Do not use a broad queue reset, Redis flush, or indiscriminate message retry as normal
recovery for a narrow provider or post-event failure.

## 30. Use local/staging-only Webinar dev tools where available

The current product includes local/staging-only Webinar dev tooling for testing confirmations, reminders, join behavior, attendance outcomes, replay URLs, and post-event follow-ups.

Use it to exercise the real public Messaging seams without turning production testing flags into a permanent deployment dependency.

## 31. Staging handoff gate

Before production:

```text
[ ] Correct client key/preset/modules active
[ ] Local tests passed
[ ] Staging deployment matches intended commit
[ ] setup:validate clean or accepted warnings only
[ ] Database connection verified
[ ] Redis isolation verified
[ ] Horizon process path verified
[ ] All required queues consumed
[ ] Laravel Scheduler cron installed and effective schedule verified
[ ] DNS/Nginx/SSL verified
[ ] CRM login verified
[ ] Email tested when enabled
[ ] SMS tested when enabled
[ ] Provider webhooks tested when enabled
[ ] Webinar registration/reminders/post-event path tested when enabled
[ ] Artist Sites -> Core artist_updates probe and controlled POST verified when used
[ ] Core artist_updates required-verification FormVersion verified after the controlled proof when used
[ ] Routes/status/campaign outcomes verified when enabled
[ ] No placeholder values remain
```

---

# Phase 4 — Production deployment

Production should repeat the validated staging process, not invent a separate process.

## 32. Create production-specific infrastructure and secrets

Production must have unique values where isolation matters:

```text
APP_KEY
production database
Redis namespace/database strategy
CACHE_PREFIX
HORIZON_PREFIX
production domains
provider webhook URLs
provider credentials/resources where not intentionally shared
```

Required values:

```env
APP_ENV=production
APP_DEBUG=false
```

## 33. Confirm production provider endpoints before launch

For each enabled integration, verify the external dashboard points at production—not staging.

Examples:

```text
Resend delivery/lifecycle webhook URL
Resend inbound-email webhook URL when enabled
Telnyx inbound webhook URL
Zoom webhook URL
DNS records
CDN/storage URLs
```

## 34. Deploy the exact approved application/client commits

- [ ] Core commit/tag recorded.
- [ ] Client commit/tag recorded.
- [ ] Correct repositories checked out.
- [ ] Both Core and any nested client repository are clean and on the approved revisions.
- [ ] No source/config change was made directly on staging/production; deployment state came from approved commits.
- [ ] No legacy application path in Nginx or Supervisor.

## 35. Build and cache carefully

Typical sequence:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
```

Production `composer install --no-dev` intentionally omits development-only test tooling. Do not make `php artisan test` a production deployment gate. Run automated tests in local/staging/CI before production; production uses current-code configuration validation, `modules:status`, `setup:validate`, process checks, provider-safe smoke checks, and observability verification.

Apply any project-approved config/route/view caching only after the final environment is complete.

## 36. Apply production schema

For a brand-new production database after the final client configuration is deployed:

```bash
php artisan engage:install --force
```

For an existing production database, run platform and installed-module upgrades separately:

```bash
php artisan migrate --force
php artisan modules:migrate --force
```

Plain `migrate` is platform-only. The module command uses the installation ledger and dependency planner to upgrade only installed scopes.

Do not use `migrate:fresh` after real data matters.

For pre-launch disposable-data resets, stop workers and handle Redis queue state first. See the safety document.

## 37. Sync presets and validate setup

When definitions changed after installation, or when this is an existing-client upgrade:

```bash
php artisan presets:sync
php artisan modules:status
php artisan setup:validate
```

A brand-new environment already ran preset sync and setup validation inside `engage:install`; rerun these checks when configuration changed afterward or when you want an explicit final gate. Resolve errors before launch.

### Artist Sites Forms production cutover when used

Do not switch production Artist Sites intake merely because staging passed. Re-establish the environment pairing with production-specific secrets and endpoints.

Before production cutover:

```text
[ ] production Core selected package includes artist_updates
[ ] production current artist_updates FormVersion is the approved required-verification version
[ ] production external Forms client ID/secret matches the production Artist Sites caller
[ ] production allowlist includes artist_updates
[ ] production Forms consent intents match the approved channel + purpose policy; any acknowledgement-domain mappings are context/copy only
[ ] production setup:validate passes
[ ] production Artist Sites still has its previous destination until the read-only probe passes
```

From production Artist Sites, run the read-only probe first:

```bash
php artisan site:probe-core-form artist_updates
```

Confirm it reports the intended production Core environment and current version. Then switch `NEWSLETTER_DESTINATION=engage_core`, clear Artist Sites cached configuration, and perform one production-safe controlled submission using an approved recipient.

Verify the same durable Core evidence as staging before opening normal traffic:

```text
FormSubmission
pinned FormVersion
FormSubmissionValues
Contact create/reuse behavior
interest:* tags
channel + purpose consent grants for explicit accepted fields only
normalized verification evidence
no raw Turnstile token/provider payload
```

Do not loosen Core verification or consent policy to make a mismatched Sites deployment pass. Roll the Sites destination back to its prior configured destination and correct the contract mismatch instead.

## 38. Restart Horizon through Supervisor

Inspect and use the actual Supervisor program name rather than guessing it:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

This restart is mandatory after deploying PHP changes that alter queued-job runtime behavior, including job execution, validation, payload rendering, gates, or providers, because long-running workers otherwise continue executing the old code already loaded in memory.

Also verify the production Scheduler entry and effective task list:

```bash
sudo crontab -u <DEPLOY_USER> -l
cd <APP_PATH>
<PHP_BIN> artisan schedule:list
```

The production deployment is not ready when Horizon is healthy but the once-per-minute Scheduler entry is absent.

## 39. Install and verify production observability

When the deployment uses the Engage Core production observability path, complete `docs/operations/observability/README.md` before launch.

At minimum verify:

```text
root LOG_CHANNEL/LOG_STACK/LOG_LEVEL/LOG_DAILY_DAYS resolve to the intended production values
Nginx access log uses engage_core_json
X-Request-ID is returned and forwarded to Laravel
Laravel writes structured daily JSON
PHP-FPM slow logging validates
Horizon and slow-log rotation rules are installed
the public observability verifier passes
```

Run the server verifier with `sudo`; it executes `nginx -t`, which may require root access to read certificate files even when Nginx itself is already healthy.

Do not run an environment-diff helper on a secret-bearing production `.env` unless its output is known to redact unrelated values.

## 40. Verify production routes and hosts

```bash
php artisan route:list
```

Check every required hostname.

---

# Phase 5 — Production smoke test

Run production-safe tests before real client traffic or a live event.

## 41. Infrastructure smoke test

```text
[ ] Root/public URL works
[ ] CRM URL works
[ ] Webinar URL works when enabled
[ ] Webhooks URL resolves when integrations need it
[ ] SSL valid
[ ] DB connected
[ ] Redis connected
[ ] Correct cache/Redis/Horizon prefixes
[ ] Correct Horizon process path
[ ] Required queues consumed
[ ] Laravel Scheduler cron installed
[ ] Effective Scheduler task list verified
[ ] setup:validate passes
[ ] Artist Sites read-only Core form probe passes when external Forms is used
[ ] Controlled artist_updates POST evidence is verified before normal traffic when used
```

## 42. Messaging smoke test

When enabled:

```text
[ ] Transactional email test reaches sent/delivered path
[ ] Marketing email sender resolves
[ ] Resend delivered test reaches the Messaging lifecycle webhook
[ ] Resend bounced test creates bounce suppression / current delivery issue when applicable
[ ] Resend complained test creates protected complaint suppression
[ ] Resend suppressed test creates provider suppression
[ ] Real inbound email reply reaches email.received, is retrieved through the Resend API, and records one InboundMessage when inbound email is enabled
[ ] Signed Reply-To correlation resolves the originating message/contact when applicable
[ ] Transactional SMS test reaches sent path
[ ] Marketing SMS sender resolves
[ ] Telnyx inbound webhook/signature works when used
[ ] SMS consent/STOP/HELP protections remain active
```

Use Resend's provider-safe test addresses for lifecycle cases and production-safe real recipients for the final reply test.

## 43. Webinar smoke test

Before relying on a live client Webinar or Meeting:

```text
public registration
→ contact created/reused
→ registration created
→ correct provider event-type adapter selected
→ provider registrant created
→ personalized join URL stored
→ confirmations planned
→ consent behavior correct
→ reminders scheduled
→ native webinar.ended or meeting.ended webhook accepted
→ attendance recorded
→ attended/missed events emitted
→ selected Routes execute
→ status transitions occur when intended
→ Campaign enrollment occurs when intended
→ recording.completed resolves playback
→ post-event follow-ups dispatch only when conditions are satisfied
```

For a Webinar-to-Meeting replacement, additionally verify:

```text
original occurrence/history preserved
replacement occurrence explicitly linked
registrants reprovisioned independently and idempotently
partial failures visible and retryable
old join link reaches canonical Meeting
old thank-you link shows canonical status/date
old cancellation link cancels one canonical provider registrant
```

Inspect actual database state; do not rely only on UI success messages.

## 44. Check for duplicate-registration conflicts

Before a live event or legacy import, confirm that one person does not have conflicting duplicate registrations for the same webinar.

Do not globally merge contacts solely by phone number without a broader identity-resolution design.

## 45. Verify no stale jobs survived previous disposable-data resets

Inspect the actual prefixed queue keys when there has been a destructive reset or app migration.

See `deployment-safety-and-troubleshooting.md`.

---

# Phase 6 — Client data import or migration

## Controlled Project State clean-rebuild transfer

Use Project State only for an approved clean rebuild that must preserve the currently supported Engage Core database state.

Authoritative procedure:

[`operations/project-state-transfer-runbook.md`](project-state-transfer-runbook.md)

Required sequence:

```text
1. Verify the source code/schema/client identity and create an independent database backup.
2. Freeze writes; stop Horizon and Scheduler.
3. Export the current-format Project State file from the owner-only CRM surface.
4. Preserve the immutable export and its SHA-256.
5. Clear only the exact stale Redis queue/runtime namespace before IDs are reused.
6. Deploy the intended target code/client configuration.
7. Run `php artisan migrate:fresh --force` only inside the approved controlled rebuild; after the path-selection cutover this rebuilds the platform foundation only.
8. Run `php artisan engage:install --force --no-create-user` to install the configured module schema, synchronize presets, and validate setup while keeping environment-owned CRM user recreation explicit.
9. Recreate environment-owned CRM users with `php artisan engage:user:add`.
10. Ensure the authorized operator can reach the Project State CRM surface while Horizon and Scheduler remain stopped. If maintenance mode blocks CRM access, use the environment's approved maintenance-window access method; do not accidentally reopen public/provider writes merely to reach the owner screen.
11. Upload the file with Validate Only and resolve every error.
12. Apply with the current password and exact IMPORT confirmation.
13. Verify counts and inert runtime state.
14. Restore workers/providers/Scheduler only after the import is verified.
15. Resume imported work category by category only when pending resume items actually exist and the operator intends to release them. A deliberately runtime-stripped file may correctly have no resume work.
16. Verify providers, queues, relationships, and external side effects before reopening normal traffic.
```

Project State does not transfer users, sessions, Redis jobs, cache/locks, provider state, or currently unsupported module data. Mortgage and Scheduling durable rows transfer when their complete optional schemas are installed. Scheduling slot offers and booking holds must still be resolved before export, and destination-verification challenge/proof state is intentionally transient.

## Other client data imports or migrations

Run other client data migration only after the environment itself is green.

Recommended order:

```text
1. Deploy application/config.
2. Apply schema using engage:install for a new environment, or migrate + modules:migrate for an existing environment.
3. Run presets:sync when definitions changed and were not already materialized by engage:install.
4. Run modules:status and setup:validate.
5. Verify providers and workers.
6. Dry-run the import.
7. Inspect exact row-level output.
8. Apply only after approval.
9. Verify row counts, relationships, consent, scheduled work, and idempotency.
```

Import rules:

- Preserve actual consent state; do not manufacture consent from message history.
- Imported consent should use the dedicated import path when available.
- Do not emit normal consent-granted acknowledgement behavior for historical imported consent unless deliberately intended.
- Avoid replaying stale queued jobs from a legacy system.
- Prefer idempotent import behavior.
- Verify future scheduled messages, not only imported record counts.

---

# Phase 7 — Final launch gate

```text
[ ] Correct Core repository/commit deployed
[ ] Correct client repository/config deployed
[ ] CLIENT_KEY correct
[ ] Selected client preset correct
[ ] Selected client runtime modules correct
[ ] Client timezone correct
[ ] APP_ENV=production
[ ] APP_DEBUG=false
[ ] APP_KEY unique and preserved
[ ] Root/client `.env` permissions allow the deploy user and PHP-FPM to read them and deny world access
[ ] Selected-client `.env` contains only client-owned keys
[ ] No placeholder environment values
[ ] Production DB verified
[ ] Installed module migration scopes reviewed with `modules:status`
[ ] Redis isolation verified
[ ] Cache prefix unique
[ ] Horizon prefix unique
[ ] Horizon process path verified
[ ] Every required queue consumed
[ ] Laravel Scheduler cron installed and `schedule:list` verified
[ ] Runtime writable directories work across every process identity that needs them
[ ] Host memory/disk/swap posture is adequate for the deployment/build strategy
[ ] Production logging resolves to the intended root logging stack
[ ] Production observability verification passes when the Engage Core observability path is installed
[ ] No stale jobs from a previous disposable DB state
[ ] DNS correct
[ ] Nginx correct for every hostname
[ ] SSL valid
[ ] presets:sync completed
[ ] setup:validate passes
[ ] Initial CRM user exists
[ ] PROJECT_STATE_ADMIN_EMAIL is deliberately blank or matches the authorized CRM owner
[ ] Any controlled Project State transfer completed validation, apply, and dependency-ordered resume
[ ] No pending Project State resume items remain unless deliberately reconciled
[ ] Resend sending domain, API credential, lifecycle webhook, and sender identities configured/tested when email is enabled
[ ] Resend receiving domain, Full Access credential, inbound webhook, and real reply path configured/tested when inbound email is enabled
[ ] Telnyx configured/tested when enabled
[ ] Zoom Webinar and Meeting capabilities configured/tested when enabled
[ ] Provider webhook endpoints point to production
[ ] Registration flow tested for every Zoom event type in use
[ ] Reminder schedule inspected when Webinars enabled
[ ] Webinar/Meeting attendance and post-event flow tested when Webinars enabled
[ ] Routes/status transitions verified when enabled
[ ] Campaign enrollment verified when enabled
[ ] Duplicate-registration conflicts checked
[ ] Client data import verified and idempotent when applicable
```

---

# Phase 8 — Ongoing deployment procedure

For normal post-launch deployments:

```text
1. Record current deployed commit.
2. Pull/deploy approved Core and client commits.
3. Install production dependencies.
4. Build assets when frontend changed.
5. Apply new environment variables before code paths require them.
6. Run php artisan optimize:clear after env/config/route changes.
7. Run php artisan migrate --force for platform migrations.
8. Run php artisan modules:migrate --force for ledger-installed module scopes.
9. Run php artisan presets:sync when config/presets changed.
10. Run php artisan modules:status and php artisan setup:validate as the readiness gate.
11. Restart Horizon through Supervisor after any queued-job runtime code change; use the exact `<CLIENT_HORIZON_PROGRAM>` discovered from Supervisor.
12. Verify actual Horizon process path and queue list.
13. Run focused production-safe smoke checks for touched providers/modules.
14. When observability configuration or Nginx/PHP-FPM logging changed, rerun the production observability verifier.
```

Do not make the production test suite part of this procedure; production dependencies are installed with `--no-dev`, and automated tests belong in local/staging/CI.
Do not clear Redis indiscriminately during ordinary deployments.
Do not regenerate `APP_KEY`.
Do not destructively reset a production database containing real data.
Do not assume preset changes rewrite already scheduled message payloads.# Engage Core — Client Staging & Production Setup Checklist

## Purpose

This is the canonical operational checklist for bringing a new Engage Core client from local configuration through staging validation and production launch.

Use it for a new client installation, a new environment for an existing client, or a migration from a legacy application into Engage Core.

This checklist intentionally separates:

1. local/developer preparation;
2. third-party service setup;
3. staging server deployment;
4. staging validation;
5. production server deployment;
6. production smoke testing and launch.

Third-party provider work is detailed in `client-third-party-services-checklist.md`.
Environment-variable ownership and staging/production differences are detailed in `client-environment-reference.md`.
Operational failure modes and destructive-reset safety are detailed in `deployment-safety-and-troubleshooting.md`.
The canonical command-level install and deployment sequence is detailed in `operations/deployment-command-workflow.md`.

## Authority

When deployment documentation and executable behavior disagree, use this order:

1. database schema for persisted fields;
2. runtime DTOs, actions, services, consumers, handlers, and resolvers;
3. registered config/token contracts;
4. setup validation and runtime tests;
5. default/client config;
6. templates and prose documentation.

Do not preserve a stale deployment assumption merely because it appears in an older checklist.

---

# Placeholder conventions

Replace every placeholder before staging or production handoff.

```text
<CLIENT_KEY>                 Example: example-client
<ROOT_DOMAIN>                Example: example.com
<STAGING_ROOT_DOMAIN>        Example: staging.example.com
<APP_PATH>                   Example: /var/www/<ROOT_DOMAIN>/engage-core
<CLIENT_PATH>                Example: /var/www/<ROOT_DOMAIN>/engage-core/client/<CLIENT_KEY>
<DEPLOY_USER>                Example: deploy
<WEB_USER>                   Example: www-data
<PHP_BIN>                    Example: /usr/bin/php8.3
<SUPERVISOR_PROGRAM>         Example: <ROOT_DOMAIN>-horizon
<CLIENT_HORIZON_PROGRAM>     Actual Supervisor program that runs this client's Horizon process
<GITHUB_ORG>                 Example: YourGitHubOrg
<ENGAGE_CORE_REPO>           Example: engage-core
<CLIENT_REPO>                Example: <CLIENT_KEY>
<GITHUB_SSH_HOST_ALIAS>      Example: github-<CLIENT_KEY>-deploy
<DB_NAME>                    Example: engage_core_<CLIENT_KEY>_production
<DB_USER>                    Example: engage_core_<CLIENT_KEY>
<REDIS_PREFIX>               Example: <CLIENT_KEY>_production_
<HORIZON_PREFIX>             Example: <CLIENT_KEY>_production_horizon:
```

---

# Phase 0 — Client preparation and local validation

Complete this before provisioning or changing a server.

## 1. Confirm client identity and package composition

- [ ] `CLIENT_KEY` is final and matches the client directory/repository identity.
- [ ] `client/{CLIENT_KEY}/config/client.php` selects the intended preset and stable client timezone.
- [ ] `client/{CLIENT_KEY}/config/modules.php` explicitly selects runtime product modules.
- [ ] Client-facing contact labels are correct.
- [ ] Required client config files exist.
- [ ] No placeholder domains, sender addresses, phone numbers, provider IDs, or secrets remain in client configuration.

Keep these concepts separate:

```text
CLIENT_KEY
    selects client/{CLIENT_KEY} and therefore the active client environment and configuration

client config/client.php
    selects preset composition and stable client timezone

client config/modules.php
    selects explicitly enabled runtime product modules

DB-owned selections/bindings
    decide which synced definitions actually run
```

Enabling a module must not be treated as automatically activating every preset it contributes.

## 2. Confirm required feature/provider matrix

Decide explicitly whether the client needs:

```text
Messaging / email
SMS
Inbound Messaging
Internal Notifications
Broadcasts
Campaigns
Webinars
FlowRoutes / Routes
Tasks
Reporting
other optional modules
```

Then decide which external services are required:

```text
GitHub repository access/deploy keys
DNS provider
DigitalOcean Spaces
Resend
Telnyx
Zoom
```

Do not provision provider credentials for a feature that is not part of the intended client package unless there is a deliberate shared-infrastructure reason.

## 3. Review client config against current Core contracts

When applicable, review:

- [ ] Messaging email definitions.
- [ ] Messaging SMS definitions.
- [ ] Permission-invitation public copy/config.
- [ ] Webinar schedule profiles.
- [ ] Webinar post-event behavior.
- [ ] Campaign presets and channel variants.
- [ ] FlowRoute presets and trigger bindings.
- [ ] Task templates.
- [ ] Contact statuses.
- [ ] Forms preset groups and server-owned submission mappings when Forms is enabled.
- [ ] External Forms caller allowlist/HMAC environment when Engage Sites will call Core.
- [ ] Channel + purpose consent intent for every selected FormVersion that can grant Messaging consent; acknowledgement-domain mapping is optional context/copy configuration only.
- [ ] Client key/token extensions.

For an Artist Sites client using the reusable Core form, confirm before deployment:

```text
selected preset package includes groups.forms = ['artist_updates']
FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS includes artist_updates
email_marketing_consent maps to email / marketing when Messaging is enabled
sms_marketing_consent maps to sms / marketing if the selected artist_updates
  contract retains SMS consent
optional acknowledgement-domain mappings do not alter those permission boundaries
```

Do not switch the public Artist Sites destination to Core yet. Package selection and Core readiness come first.

Core rules that matter before deployment:

```text
Messaging templates own reusable copy and delivery-template metadata.
Owning modules own lifecycle timing/conditions/enablement.
Campaign presets own campaign timing and progression, not reusable message copy.
Webinar schedule profiles own Webinar lifecycle timing.
Preset composition and runtime module availability remain separate decisions.
SMS availability is a Messaging channel-availability decision, not merely provider credentials.
Normal Broadcasts remain consent-gated.
Imported-contact permission invitations are a distinct Messaging-owned flow.
```

## 4. Run local validation

Use the actual repository-supported commands. At minimum:

```bash
composer install
npm ci
npm run build
php artisan optimize:clear
php artisan presets:sync
php artisan setup:validate
```

Also run focused and adjacent tests for the modules/configs being introduced or changed.

Staging/client handoff rule:

```text
setup:validate errors must be resolved.
warnings must be understood and intentionally accepted.
```

## 5. Commit and push the intended deployment state

- [ ] Engage Core changes committed and pushed, if any.
- [ ] Client repository/config changes committed and pushed.
- [ ] Deployment branch/tag/commit identified.
- [ ] No uncommitted local-only config is required for the deployment to work.

Staging and production are deployment targets, not source-editing environments. Make application/client config changes in development, test them there, commit/push them, and deploy by pulling the approved revision. Direct server edits are reserved for environment files, server/process configuration, secrets, and emergency operational recovery—not normal PHP/config/source changes.

---

# Phase 1 — Third-party services

Complete the relevant sections in:

```text
client-third-party-services-checklist.md
```

At minimum, determine and record environment-specific values for:

```text
DNS and hostnames
GitHub deploy access
DigitalOcean Spaces
Resend
Telnyx
Zoom
```

Staging and production must not accidentally share provider webhook endpoints, credentials, numbers, buckets, or sender identities unless sharing is deliberate and documented.

---

# Phase 2 — Staging server deployment

Staging is a first-class deployment gate. Do not treat production as the first realistic integration test.

For repeatable new-environment provisioning after the Core checkout exists, `scripts/operations/launch-client-environment.sh` may automate the repository/dependency/env-permission/install/runtime checks in phased steps using `docs/operations/launch-client-environment.example.conf`. The helper does not own secrets, DNS, Nginx, TLS, provider accounts, or database-user creation; the detailed checklist below remains authoritative.

## 6. Provision the staging application path

Example:

```text
/var/www/<STAGING_ROOT_DOMAIN>/engage-core
```

Verify the intended deployment user, web user, PHP version, PHP-FPM socket, Composer, Node/npm, MySQL client access, Redis access, Nginx, Supervisor, and required PHP extensions.

Also verify the host has enough memory and disk headroom for the deployment toolchain:

```bash
free -h
swapon --show
df -h /
```

Composer, npm, and Vite builds can create short-lived memory spikes. Swap is not an Engage Core application requirement, but a small production host with limited RAM and no swap should be corrected before relying on in-place server builds. Choose swap size from the actual host capacity and workload; do not copy one client's value blindly.

## 7. Inspect SSH keys and host aliases before cloning

Useful checks:

```bash
ls -la ~/.ssh
cat ~/.ssh/config
ssh -T git@<GITHUB_SSH_HOST_ALIAS>
```

Do not assume `github.com` is the correct SSH host when deploy keys use client-specific aliases.

## 8. Clone the Engage Core and client repositories

Use the actual approved repository layout.

Example pattern:

```bash
git clone git@<GITHUB_SSH_HOST_ALIAS>:<GITHUB_ORG>/<ENGAGE_CORE_REPO>.git <APP_PATH>
```

Install or clone the client package in the location expected by Engage Core. When the client directory is its own repository, verify Core and the client repository independently; a clean/current Core checkout does not prove the nested client checkout is current.

```bash
cd <APP_PATH>
git status
git branch --show-current
git log -1 --oneline --decorate
git remote -v

cd client/<CLIENT_KEY>
git status
git branch --show-current
git log -1 --oneline --decorate
git remote -v
```

Before syncing presets, verify effective client identity:

```bash
php artisan tinker --execute="dump([
    'client_key' => config('client.key'),
    'client_preset' => config('client.preset'),
    'client_timezone' => config('client.timezone'),
    'enabled_modules' => config('modules.enabled'),
]);"
```

## 9. Install dependencies and build assets

Typical staging/production sequence:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Use the project's actual supported deployment commands if they differ.

## 10. Set permissions

Keep application source owned by the ordinary deployment user. Do not recursively give the checkout to the web server.

The root and selected-client `.env` files must be readable by both the deployment/Artisan identity and PHP-FPM without being world-readable. For the common `<DEPLOY_USER>` + `www-data` deployment:

```bash
sudo chown <DEPLOY_USER>:<WEB_GROUP> <APP_PATH>/.env <APP_PATH>/client/<CLIENT_KEY>/.env
sudo chmod 640 <APP_PATH>/.env <APP_PATH>/client/<CLIENT_KEY>/.env
```

Do not use `0600` merely because the files contain secrets when PHP-FPM runs as another user and loads them directly. CLI success does not prove the web runtime can read the same environment.

Verify every process identity that must create or update runtime files can write where required:

```text
storage/
bootstrap/cache/
```

Typical deployments may use different identities for:

```text
deployment / Scheduler user
PHP-FPM user
Supervisor/Horizon user
```

A directory showing `775` is not sufficient proof when those identities have different primary groups. A newly created file can still be writable only by its creator and creator group.

Before handoff, perform a real cross-user write check in a disposable file:

```text
deploy user creates -> web/worker user updates
web/worker user creates -> deploy user updates
```

Both directions must succeed when both identities are expected to write that tree.

Use the server's deliberate shared-write policy—such as shared groups with inherited group ownership or POSIX default ACLs—rather than repeatedly applying broad `chmod`/`chown` after deploys. When POSIX ACLs are used, apply access to existing files/directories and default ACLs to directories so future files inherit the same writable identities.

Do not blindly copy ownership/ACL commands between servers. Confirm the actual deployment, web, Scheduler, and worker users first.

A robust baseline when the checkout owner is `<DEPLOY_USER>` and PHP-FPM/Horizon use `<WEB_GROUP>` is:

```bash
sudo chown -R <DEPLOY_USER>:<WEB_GROUP> storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;
```

The setgid directory bit keeps newly created runtime files in the shared group. A recursive `775` policy may also work on an existing tree but gives execute permission to ordinary files and does not by itself prove future cross-user group inheritance. Keep the two-way write test as the authority.

## 11. Create the staging root and client environments

Start from the root `.env.example` and the selected client's `.env.example`.

Required staging differences:

```env
APP_ENV=staging
APP_DEBUG=false
```

Use the root `.env` for:

```text
CLIENT_KEY
APP_ENV
APP_DEBUG
APP_KEY
DB connection host/port
Redis host/port/database indexes
queue/process tuning
logging
staging access
process-wide storage/provider controls such as FILESYSTEM_DISK and webhook timestamp tolerances
```

Use `client/{CLIENT_KEY}/.env` for:

```text
APP_URL and host URLs
DB database/credentials
CACHE_PREFIX
REDIS_PREFIX
HORIZON_PREFIX
provider credentials and webhook secrets
sender identities and phone numbers
PROJECT_STATE_ADMIN_EMAIL when the owner-only transfer surface is deliberately enabled
storage credentials/bucket/CDN URL
other selected-client deployment values
```

Do not leave placeholder values such as `DOMAIN`, `CHANGE_ME`, empty required sender addresses, or blank provider secrets in an environment intended for handoff testing.

The selected-client environment is strictly validated. Do not copy root/process keys into `client/{CLIENT_KEY}/.env` merely because they relate to the client. A root/client ownership mismatch can fail during very early bootstrap before Laravel's normal exception reporting is fully available.

After creating the files, verify read access without printing secrets:

```bash
test -r .env && test -r client/<CLIENT_KEY>/.env
sudo -u <WEB_USER> test -r .env
sudo -u <WEB_USER> test -r client/<CLIENT_KEY>/.env
```

## 12. Generate the staging application key

For a new environment only:

```bash
php artisan key:generate
```

Do not regenerate a key after encrypted application data exists unless key rotation is deliberate and supported.

## 13. Configure and verify MySQL

The current deployment path is MySQL 8.

Verify root `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
```

Verify selected client `.env`:

```env
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Then test the application connection before migrations.

## 14. Configure and verify Redis isolation

Current stack expectations:

```env
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
```

Use an environment-specific namespace and/or Redis DB separation.

Recommended root `.env` values:

```env
REDIS_DB=0
REDIS_CACHE_DB=1
```

Recommended selected client `.env` values:

```env
REDIS_PREFIX=<CLIENT_KEY>_staging_
CACHE_PREFIX=<CLIENT_KEY>_staging_cache_
HORIZON_PREFIX=<CLIENT_KEY>_staging_horizon:
```

If Redis is shared with other apps or environments, uniqueness is mandatory.

Inspect effective config when needed:

```bash
php artisan tinker --execute="dump([
    'queue_default' => config('queue.default'),
    'redis_default' => config('database.redis.default'),
    'redis_cache' => config('database.redis.cache'),
    'queue_redis' => config('queue.connections.redis'),
    'cache_prefix' => config('cache.prefix'),
    'horizon_prefix' => config('horizon.prefix'),
    'horizon_use' => config('horizon.use'),
    'horizon_environment' => config('horizon.environments.'.app()->environment()),
]);"
```

## 15. Configure staging access protection

When the staging access middleware/gate is used, set:

```env
STAGING_USER=
STAGING_PASSWORD=
```

Use strong unique credentials. Do not reuse production application-user passwords.

## 16. Configure DNS, Nginx, and SSL

Typical deployment roles:

```text
<root domain>
    standard site domain

<Core admin subdomain>.<root domain>
    human-facing Engage Core administration; commonly crm or app

webinar.<root domain>
    public Engage Core Webinar host when enabled

webhooks.<root domain>
    public Engage Core webhook and signed integration host
```

Use the actual environment topology; do not assume the Core admin label is always `crm` or that staging must use the exact same naming pattern as production. `CRM_APP_URL` remains the canonical Core environment key even when the deployed admin hostname uses `app`.

For every hostname:

- [ ] DNS resolves to the intended staging server.
- [ ] Each Core-owned hostname points to the intended Engage Core `public/` directory.
- [ ] The standard site domain points to its owning site application rather than Engage Core when the products are deployed together.
- [ ] PHP-FPM socket/version is correct.
- [ ] SSL is valid.
- [ ] No hostname still points to a legacy checkout.

Validate before reload:

```bash
sudo nginx -t
```

After `.env` changes:

```bash
php artisan optimize:clear
php artisan route:list
```

Confirm route hosts are correct. In particular, the CRM route domain must match the hostname from `CRM_APP_URL`; do not assume it is always `crm.<ROOT_DOMAIN>`.

After SSL is valid, use real application routes for HTTP smoke checks:

```bash
curl -sS -D - -o /dev/null https://<CORE_ADMIN_HOST>/
curl -sS -D - -o /dev/null https://<CORE_ADMIN_HOST>/login
```

A guest redirect to login/staging access is healthy. For the webhooks host, `/` may legitimately be 404 because no root route exists. When external Forms is enabled, the useful unsigned transport smoke is:

```bash
curl -sS -o /tmp/forms-smoke.json -w '%{http_code}\n' \
  -H 'Accept: application/json' \
  https://webhooks.<ROOT_DOMAIN>/forms/<FORM_KEY>
```

A `401` `authentication_failed` response proves DNS, SSL, Nginx, PHP-FPM, Laravel host routing, the Forms route, and its authentication middleware are all being reached.

## 17. Configure Supervisor/Horizon

A Supervisor program should point to the exact intended checkout.

Generic example:

```ini
[program:<SUPERVISOR_PROGRAM>]
process_name=%(program_name)s
command=<PHP_BIN> <APP_PATH>/artisan horizon
directory=<APP_PATH>
autostart=true
autorestart=true
user=<WEB_USER>
redirect_stderr=true
stdout_logfile=<APP_PATH>/storage/logs/horizon.log
stopwaitsecs=3600
```

Verify all three paths:

```text
command=
directory=
stdout_logfile=
```

Reload using the server's operational process, for example:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart <SUPERVISOR_PROGRAM>
```

Then verify the actual process path:

```bash
ps aux | grep "[a]rtisan horizon"
```

Do not trust Supervisor config alone. Confirm the process that is actually running.

Before restarting Horizon, inspect the actual Supervisor program name instead of guessing it:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
```

Use the exact matching program name as:

```text
<CLIENT_HORIZON_PROGRAM>
```

Operational rule:

> After deploying PHP changes that affect queued job execution, job validation, payload rendering, gates, providers, or other queue-worker runtime behavior, restart the Supervisor-managed Horizon process so all workers load the new code.

```bash
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

Supervisor is the lifecycle source of truth for this deployment path. Do not substitute an Artisan Horizon lifecycle command for the Supervisor restart when Supervisor owns the process.

## 18. Configure the Horizon queue list explicitly

The current executable/configured queue set includes:

```text
default
notifications
confirmation_messages
opt_in_messages
reminders
post_event
marketing
emails
sms
webinars
webhooks
```

Use an explicit `HORIZON_SUPERVISOR_1_QUEUES` value until Core's built-in Horizon defaults are confirmed to reflect every executable queue path.

Example:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,opt_in_messages,reminders,post_event,marketing,emails,sms,webinars,webhooks
```

Current runtime notes:

```text
emails is an active queue path.
Webinar waitlist delivery uses notifications; there is no canonical separate waitlist queue requirement.
Do not preserve an old campaigns queue requirement from stale Webinar nurture config.
```

Horizon must consume every queue the current runtime can actually dispatch to. Verify effective runtime configuration rather than trusting a historical `.env` queue list.

### Configure Laravel Scheduler

Engage Core uses Laravel Scheduler for database-backed recovery and outbox reconciliation. Redis/Horizon remains the primary delayed-job execution path, but the Scheduler must run so due message-chain enrollments, stale delivery claims, and unpublished outbox events can be recovered.

Install exactly one cron entry for this client deployment under the intended deployment/process user:

```cron
* * * * * cd <APP_PATH> && <PHP_BIN> artisan schedule:run >> /dev/null 2>&1
```

Verify the entry and the effective schedule:

```bash
sudo crontab -u <DEPLOY_USER> -l
cd <APP_PATH>
<PHP_BIN> artisan schedule:list
```

Do not run `schedule:work` in parallel with the cron entry. The working directory and environment must resolve the intended `CLIENT_KEY`, database, Redis namespace, and client configuration.

## 19. Apply staging schema

For a brand-new staging database after client configuration is complete, use the full installer:

```bash
php artisan engage:install --force
```

That command applies platform migrations, installs the configured schema-owning module dependency closure, synchronizes presets, and runs setup validation. It does not rewrite runtime module configuration.

For an existing staging database, use the normal upgrade path:

```bash
php artisan migrate --force
php artisan modules:migrate --force
```

After the modular migration path-selection cutover, plain `migrate` is platform-only. `modules:migrate` upgrades only ledger-installed module scopes. Do not substitute a broad `migrate --path` scan of optional module directories.

A destructive reset is acceptable only when the environment data is disposable and queued Redis state has been handled first. See `deployment-safety-and-troubleshooting.md`.

## 20. Sync DB-owned definitions

Run the canonical orchestrator when preset/config definitions changed or when this stage was not already completed by `engage:install`:

```bash
php artisan presets:sync
```

Rerunning preset sync after a successful `engage:install` is allowed but normally redundant unless configuration changed afterward.

Current sync architecture may materialize, when selected/enabled:

```text
ContactStatus definitions
Task templates
Forms definitions/immutable published versions
Messaging template presets/assignments/catalog entries
Webinar schedule profiles/items
Campaigns/steps/variants
FlowRoute capabilities
FlowRoutes/points/bindings
```

Do not assume an old list of separate sync commands remains necessary. Use the current orchestrator and only run extra commands when current source explicitly requires them.

## 20A. Run applicable module-specific post-install commands

`engage:install` owns platform/module schema installation, selected preset materialization, and its initial setup-validation pass. `presets:sync` owns configured DB definitions. Do not rerun older module-specific sync commands for work already covered by those orchestrators.

Run only the entries whose condition applies. The canonical registry is maintained in `deployment-command-workflow.md`.

### Forms — external-intake credential issuance

Run this when Forms is enabled for a server-to-server external intake client and that environment does not already have its valid client ID/signing-secret pair:

```bash
php artisan forms:external-intake:issue-secret [client]
```

When exactly one external client is configured, the optional client argument may be omitted. The command prints matching Engage Core and caller environment blocks; it does not write either environment.

- [ ] Issue a distinct pair for this environment.
- [ ] Copy the Core block into the selected Core client environment.
- [ ] Copy the caller block into the matching external application environment.
- [ ] Run `php artisan optimize:clear` in each application after changing its environment.
- [ ] Never reuse the production pair in staging or the staging pair in production.

The command is bootstrap-safe when the current Core secret is blank or invalid. If `engage:install` reached its final validation stage and reported the missing/invalid Forms client configuration, issue and install the credential here, clear cached configuration, and continue with the explicit setup-validation gate below.

No other current module requires a mandatory module-specific post-install Artisan command beyond `engage:install` and `presets:sync`. Provider probes, smoke tests, user creation, and production process restarts remain in their owning checklist sections rather than this registry.

## 21. Run setup validation

Run setup validation after any additional config or preset changes, even when the initial install already completed its validation stage:

```bash
php artisan modules:status
php artisan setup:validate
```

Gate:

```text
errors: block handoff
warnings: understand and deliberately accept or resolve
clean: proceed
```

Do not auto-fix validation failures by broadening config contracts or adding unsupported config keys.

### Artist Sites Forms staging integration gate when used

Complete this after Core preset sync/setup validation and before the public Artist Sites newsletter destination is changed to `engage_core`.

Core staging must first satisfy:

```text
[ ] Forms enabled for the selected client
[ ] selected preset package includes forms group artist_updates
[ ] public current artist_updates FormVersion exists
[ ] external Forms intake enabled
[ ] matching external client ID/secret configured
[ ] allowed_forms includes artist_updates
[ ] email_marketing_consent maps to email / marketing when Messaging consent is used
[ ] sms_marketing_consent maps to sms / marketing when the selected form retains SMS consent
[ ] setup:validate has no Forms runtime errors
```

Keep the reusable `artist_updates` verification policy at `required=false` for the first transport proof. The reusable policy still validates any supplied Turnstile attestation against provider `turnstile`, action `artist_updates`, hostname presence, and a 300-second maximum age.

From the matching Artist Sites staging environment, while Mailchimp may still be the live newsletter destination, run:

```bash
php artisan site:probe-core-form artist_updates
```

That read-only GET must prove the intended Core environment, HMAC client/secret pair, form allowlist, and current public schema before cutover.

A successful probe is not a POST-readiness proof. Before changing `NEWSLETTER_DESTINATION=engage_core`, confirm the deployed Artist Sites sender submits the fields required by the current Core FormVersion. In particular, the reusable Core contract requires explicit accepted `email_marketing_consent`; a Sites build that still posts only `email` is not ready for cutover.

Once the Sites sender matches the published contract:

```text
1. Change the staging Artist Sites destination to engage_core and clear its cached configuration.
2. Ensure the public newsletter/intake surface itself is active; selecting a destination does not activate a disabled site surface.
3. Submit ONE controlled real public artist_updates form while Core verification remains optional. Turnstile may already be configured, but it is not required for this first transport proof.
4. Verify Core created/reused the intended Contact.
5. Verify Core stored FormSubmission + pinned FormVersion + typed FormSubmissionValues.
6. Verify expected interest:* Contact tags.
7. Verify accepted email/SMS marketing fields produced channel + purpose Messaging consent only when those fields were explicitly true.
8. If Artist Sites supplied normalized Turnstile evidence, verify it is present and verify no raw Turnstile token/provider payload was persisted.
9. Replay the same external UUID only as an explicit idempotency check; it must not duplicate the submission or consent grant.
10. Configure Artist Sites human verification (Turnstile for the current reference implementation), run `site:check`, and verify the exact staging hostname is allowed.
```

Only after the controlled transport POST succeeds and the Artist Sites human-verification check is green should the selected Core client promote verification to required with:

```text
client/{client-key}/config/presets/modules/forms/forms.php
    definitions.artist_updates.settings.submission.verification.required = true
```

Then run:

```bash
php artisan presets:sync
php artisan setup:validate
```

Confirm a new immutable current FormVersion was published, rerun the Artist Sites read-only probe, then perform one more controlled real submission. Confirm the Turnstile widget is present in the browser and the required-verification path succeeds end to end.

## 22. Create the initial CRM user when required

For an interactive new-client installation, `engage:install` offers to create the first CRM user after the four installation stages succeed.

If user creation was skipped, or another CRM user is needed later, run:

```bash
php artisan engage:user:add
```

The command prompts for name, email, password, and password confirmation. Password input is hidden and is not stored in `.env`.

If a CRM password is forgotten, reset it explicitly:

```bash
php artisan engage:user:password user@example.com
```

Do not use `db:seed`, `UserSeeder`, or `SETUP_USER_*` environment values for operational CRM users.

See `operations/crm-user-administration.md` for the complete contract.

---

# Phase 3 — Staging validation and smoke tests

## 23. Verify application URLs

Check the actual intended hosts:

```text
root/public site
CRM
webinar
webhooks
```

## 24. Verify effective environment and client identity

```bash
php artisan tinker --execute="dump([
    'app_env' => app()->environment(),
    'app_url' => config('app.url'),
    'root_domain' => config('app.root_domain'),
    'crm_url' => config('app.crm_url'),
    'webinar_url' => config('app.webinar_url'),
    'client_key' => config('client.key'),
    'client_preset' => config('client.preset'),
    'client_timezone' => config('client.timezone'),
    'enabled_modules' => config('modules.enabled'),
]);"
```

## 25. Verify queue/Horizon health

- [ ] Correct Supervisor program running.
- [ ] Correct checkout path running.
- [ ] Correct Horizon environment selected.
- [ ] All required queues consumed.
- [ ] No unexpected failed jobs.
- [ ] Redis prefixes understood.
- [ ] Laravel Scheduler cron installed for this client deployment.
- [ ] `php artisan schedule:list` shows the expected Messaging recovery/outbox tasks.

## 26. Verify email

When Messaging/email is enabled:

```text
Resend API works
sender domain verified
transactional from identity resolves
marketing from identity resolves
webhook endpoint/signature works when delivery events are used
real staging-safe email reaches sent/delivered path
```

## 27. Verify SMS

When SMS is enabled:

```text
SMS_ENABLED=true
provider resolves to telnyx unless intentionally changed
effective Messaging channel availability reports provider_enabled = true for the intended SMS surface/purpose/scope
transactional number resolves
marketing number resolves
profile IDs resolve when required
webhook public key/signature verification works when inbound events are used
real staging-safe SMS reaches sent path
STOP/HELP behavior remains protected
```

Do not infer SMS availability solely from provider credentials. Confirm Messaging channel availability and intended UI surfaces.

## 28. Verify permission invitations when used

- [ ] Public URL configured.
- [ ] Email invitation can be scheduled for eligible imported contacts.
- [ ] Normal Broadcast consent rules are not bypassed.
- [ ] Invitation acceptance writes intended consent state.
- [ ] Existing/failed/pending invitation rules behave as expected.

## 29. Verify Webinar setup when enabled

Run `php artisan setup:validate` before provider smoke tests. The Webinar contributor
must report no Zoom credential, endpoint, provider-adapter, webhook-mapping, token-TTL,
or timestamp-drift findings.

At minimum:

```text
Zoom Server-to-Server OAuth credentials work.
Both Webinar and Meeting adapters resolve for the configured Zoom provider.
Webinar lookup works when Webinar event types are used.
Meeting lookup works when Meeting event types are used.
Registration API works for each event type in use.
Personalized join URL is stored.
Registration confirmation planning works.
Schedule profile is selected and active.
Future reminders are scheduled correctly.
A real signed webinar.ended webhook is accepted when Webinars are used.
A real signed meeting.ended webhook is accepted when Meetings are used.
Attendance-report capability works through the exact provider call used by runtime.
Cloud-recording lookup works when replay follow-ups are enabled.
recording.completed can resolve playback.
Post-event follow-ups wait for required playback conditions.
webinar.attended / webinar.missed automation events work.
Selected Routes run.
Campaign enrollments occur when intended.
```

Current Core post-event orchestration is split by normalized event identity:

```text
webinar.ended
    Native source may be webinar.ended or meeting.ended.
    Records provider attendance for the resolved occurrence.

webinar.recording_completed
    Native source is recording.completed.
    Resolves playback.
    Dispatches post-event follow-ups.
```

Do not assume an ended event alone sends replay follow-ups.

Use `client-third-party-services-checklist.md` for the exact current granular Zoom
scope list. Registration/lookup, Meeting reports, Webinar reports, and cloud recording
lookup are separate capabilities; do not assume one permission category implies the
others.

Do not treat route existence or configured event subscriptions as proof of webhook
readiness. Verify that a real signed provider webhook reaches the intended environment,
passes signature verification, dispatches to a consumed queue, and produces the
expected domain action.

When Webinar-to-Meeting replacement is supported for the client, complete this staging
smoke before launch:

```text
1. Preserve the original Webinar occurrence and historical registrations.
2. Change the series type only for future synchronization.
3. Sync the replacement Meeting occurrence.
4. Confirm the explicit occurrence replacement in CRM.
5. Verify per-registration reprovisioning totals and individual recovery controls.
6. Verify old join, thank-you, and cancellation links follow the canonical registration.
7. Verify consent acknowledgements and confirmations are not duplicated.
8. Verify only future-valid reminders are scheduled for the replacement.
```

For production post-event handling, use this safe sequence:

```text
1. Verify the Zoom app has the capabilities required by the event types in use.
2. Verify attendance state.
3. Resolve duplicate/cancelled registration conflicts before follow-up dispatch when necessary.
4. Retry only the failed post-event provider job.
5. Confirm Webinar.playback_url contains the real recording URL.
6. Confirm follow_ups_dispatched_at is populated.
7. Inspect the actual ScheduledMessage rows.
8. Verify replay URL, expected CTAs/links, recipient eligibility, statuses, and send timing.
9. Inspect Horizon Delayed Until and/or serialized queue delay metadata before touching Redis.
10. Restart Supervisor-managed Horizon after queued-job code changes.
11. Surgically retry only the affected skipped/failed messages.
12. Verify final message statuses.
```

Do not use a broad queue reset, Redis flush, or indiscriminate message retry as normal
recovery for a narrow provider or post-event failure.

## 30. Use local/staging-only Webinar dev tools where available

The current product includes local/staging-only Webinar dev tooling for testing confirmations, reminders, join behavior, attendance outcomes, replay URLs, and post-event follow-ups.

Use it to exercise the real public Messaging seams without turning production testing flags into a permanent deployment dependency.

## 31. Staging handoff gate

Before production:

```text
[ ] Correct client key/preset/modules active
[ ] Local tests passed
[ ] Staging deployment matches intended commit
[ ] setup:validate clean or accepted warnings only
[ ] Database connection verified
[ ] Redis isolation verified
[ ] Horizon process path verified
[ ] All required queues consumed
[ ] Laravel Scheduler cron installed and effective schedule verified
[ ] DNS/Nginx/SSL verified
[ ] CRM login verified
[ ] Email tested when enabled
[ ] SMS tested when enabled
[ ] Provider webhooks tested when enabled
[ ] Webinar registration/reminders/post-event path tested when enabled
[ ] Artist Sites -> Core artist_updates probe and controlled POST verified when used
[ ] Core artist_updates required-verification FormVersion verified after the controlled proof when used
[ ] Routes/status/campaign outcomes verified when enabled
[ ] No placeholder values remain
```

---

# Phase 4 — Production deployment

Production should repeat the validated staging process, not invent a separate process.

## 32. Create production-specific infrastructure and secrets

Production must have unique values where isolation matters:

```text
APP_KEY
production database
Redis namespace/database strategy
CACHE_PREFIX
HORIZON_PREFIX
production domains
provider webhook URLs
provider credentials/resources where not intentionally shared
```

Required values:

```env
APP_ENV=production
APP_DEBUG=false
```

## 33. Confirm production provider endpoints before launch

For each enabled integration, verify the external dashboard points at production—not staging.

Examples:

```text
Resend webhook URL
Telnyx inbound webhook URL
Zoom webhook URL
DNS records
CDN/storage URLs
```

## 34. Deploy the exact approved application/client commits

- [ ] Core commit/tag recorded.
- [ ] Client commit/tag recorded.
- [ ] Correct repositories checked out.
- [ ] Both Core and any nested client repository are clean and on the approved revisions.
- [ ] No source/config change was made directly on staging/production; deployment state came from approved commits.
- [ ] No legacy application path in Nginx or Supervisor.

## 35. Build and cache carefully

Typical sequence:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
```

Production `composer install --no-dev` intentionally omits development-only test tooling. Do not make `php artisan test` a production deployment gate. Run automated tests in local/staging/CI before production; production uses current-code configuration validation, `modules:status`, `setup:validate`, process checks, provider-safe smoke checks, and observability verification.

Apply any project-approved config/route/view caching only after the final environment is complete.

## 36. Apply production schema

For a brand-new production database after the final client configuration is deployed:

```bash
php artisan engage:install --force
```

For an existing production database, run platform and installed-module upgrades separately:

```bash
php artisan migrate --force
php artisan modules:migrate --force
```

Plain `migrate` is platform-only. The module command uses the installation ledger and dependency planner to upgrade only installed scopes.

Do not use `migrate:fresh` after real data matters.

For pre-launch disposable-data resets, stop workers and handle Redis queue state first. See the safety document.

## 37. Sync presets and validate setup

When definitions changed after installation, or when this is an existing-client upgrade:

```bash
php artisan presets:sync
php artisan modules:status
php artisan setup:validate
```

A brand-new environment already ran preset sync and setup validation inside `engage:install`; rerun these checks when configuration changed afterward or when you want an explicit final gate. Resolve errors before launch.

### Artist Sites Forms production cutover when used

Do not switch production Artist Sites intake merely because staging passed. Re-establish the environment pairing with production-specific secrets and endpoints.

Before production cutover:

```text
[ ] production Core selected package includes artist_updates
[ ] production current artist_updates FormVersion is the approved required-verification version
[ ] production external Forms client ID/secret matches the production Artist Sites caller
[ ] production allowlist includes artist_updates
[ ] production Forms consent intents match the approved channel + purpose policy; any acknowledgement-domain mappings are context/copy only
[ ] production setup:validate passes
[ ] production Artist Sites still has its previous destination until the read-only probe passes
```

From production Artist Sites, run the read-only probe first:

```bash
php artisan site:probe-core-form artist_updates
```

Confirm it reports the intended production Core environment and current version. Then switch `NEWSLETTER_DESTINATION=engage_core`, clear Artist Sites cached configuration, and perform one production-safe controlled submission using an approved recipient.

Verify the same durable Core evidence as staging before opening normal traffic:

```text
FormSubmission
pinned FormVersion
FormSubmissionValues
Contact create/reuse behavior
interest:* tags
channel + purpose consent grants for explicit accepted fields only
normalized verification evidence
no raw Turnstile token/provider payload
```

Do not loosen Core verification or consent policy to make a mismatched Sites deployment pass. Roll the Sites destination back to its prior configured destination and correct the contract mismatch instead.

## 38. Restart Horizon through Supervisor

Inspect and use the actual Supervisor program name rather than guessing it:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

This restart is mandatory after deploying PHP changes that alter queued-job runtime behavior, including job execution, validation, payload rendering, gates, or providers, because long-running workers otherwise continue executing the old code already loaded in memory.

Also verify the production Scheduler entry and effective task list:

```bash
sudo crontab -u <DEPLOY_USER> -l
cd <APP_PATH>
<PHP_BIN> artisan schedule:list
```

The production deployment is not ready when Horizon is healthy but the once-per-minute Scheduler entry is absent.

## 39. Install and verify production observability

When the deployment uses the Engage Core production observability path, complete `docs/operations/observability/README.md` before launch.

At minimum verify:

```text
root LOG_CHANNEL/LOG_STACK/LOG_LEVEL/LOG_DAILY_DAYS resolve to the intended production values
Nginx access log uses engage_core_json
X-Request-ID is returned and forwarded to Laravel
Laravel writes structured daily JSON
PHP-FPM slow logging validates
Horizon and slow-log rotation rules are installed
the public observability verifier passes
```

Run the server verifier with `sudo`; it executes `nginx -t`, which may require root access to read certificate files even when Nginx itself is already healthy.

Do not run an environment-diff helper on a secret-bearing production `.env` unless its output is known to redact unrelated values.

## 40. Verify production routes and hosts

```bash
php artisan route:list
```

Check every required hostname.

---

# Phase 5 — Production smoke test

Run production-safe tests before real client traffic or a live event.

## 41. Infrastructure smoke test

```text
[ ] Root/public URL works
[ ] CRM URL works
[ ] Webinar URL works when enabled
[ ] Webhooks URL resolves when integrations need it
[ ] SSL valid
[ ] DB connected
[ ] Redis connected
[ ] Correct cache/Redis/Horizon prefixes
[ ] Correct Horizon process path
[ ] Required queues consumed
[ ] Laravel Scheduler cron installed
[ ] Effective Scheduler task list verified
[ ] setup:validate passes
[ ] Artist Sites read-only Core form probe passes when external Forms is used
[ ] Controlled artist_updates POST evidence is verified before normal traffic when used
```

## 42. Messaging smoke test

When enabled:

```text
[ ] Transactional email test reaches sent/delivered path
[ ] Marketing email sender resolves
[ ] Resend webhook works when used
[ ] Transactional SMS test reaches sent path
[ ] Marketing SMS sender resolves
[ ] Telnyx inbound webhook/signature works when used
[ ] SMS consent/STOP/HELP protections remain active
```

Use production-safe recipients only.

## 43. Webinar smoke test

Before relying on a live client Webinar or Meeting:

```text
public registration
→ contact created/reused
→ registration created
→ correct provider event-type adapter selected
→ provider registrant created
→ personalized join URL stored
→ confirmations planned
→ consent behavior correct
→ reminders scheduled
→ native webinar.ended or meeting.ended webhook accepted
→ attendance recorded
→ attended/missed events emitted
→ selected Routes execute
→ status transitions occur when intended
→ Campaign enrollment occurs when intended
→ recording.completed resolves playback
→ post-event follow-ups dispatch only when conditions are satisfied
```

For a Webinar-to-Meeting replacement, additionally verify:

```text
original occurrence/history preserved
replacement occurrence explicitly linked
registrants reprovisioned independently and idempotently
partial failures visible and retryable
old join link reaches canonical Meeting
old thank-you link shows canonical status/date
old cancellation link cancels one canonical provider registrant
```

Inspect actual database state; do not rely only on UI success messages.

## 44. Check for duplicate-registration conflicts

Before a live event or legacy import, confirm that one person does not have conflicting duplicate registrations for the same webinar.

Do not globally merge contacts solely by phone number without a broader identity-resolution design.

## 45. Verify no stale jobs survived previous disposable-data resets

Inspect the actual prefixed queue keys when there has been a destructive reset or app migration.

See `deployment-safety-and-troubleshooting.md`.

---

# Phase 6 — Client data import or migration

## Controlled Project State clean-rebuild transfer

Use Project State only for an approved clean rebuild that must preserve the currently supported Engage Core database state.

Authoritative procedure:

[`operations/project-state-transfer-runbook.md`](project-state-transfer-runbook.md)

Required sequence:

```text
1. Verify the source code/schema/client identity and create an independent database backup.
2. Freeze writes; stop Horizon and Scheduler.
3. Export the current-format Project State file from the owner-only CRM surface.
4. Preserve the immutable export and its SHA-256.
5. Clear only the exact stale Redis queue/runtime namespace before IDs are reused.
6. Deploy the intended target code/client configuration.
7. Run `php artisan migrate:fresh --force` only inside the approved controlled rebuild; after the path-selection cutover this rebuilds the platform foundation only.
8. Run `php artisan engage:install --force --no-create-user` to install the configured module schema, synchronize presets, and validate setup while keeping environment-owned CRM user recreation explicit.
9. Recreate environment-owned CRM users with `php artisan engage:user:add`.
10. Ensure the authorized operator can reach the Project State CRM surface while Horizon and Scheduler remain stopped. If maintenance mode blocks CRM access, use the environment's approved maintenance-window access method; do not accidentally reopen public/provider writes merely to reach the owner screen.
11. Upload the file with Validate Only and resolve every error.
12. Apply with the current password and exact IMPORT confirmation.
13. Verify counts and inert runtime state.
14. Restore workers/providers/Scheduler only after the import is verified.
15. Resume imported work category by category only when pending resume items actually exist and the operator intends to release them. A deliberately runtime-stripped file may correctly have no resume work.
16. Verify providers, queues, relationships, and external side effects before reopening normal traffic.
```

Project State does not transfer users, sessions, Redis jobs, cache/locks, provider state, or currently unsupported module data. Mortgage and Scheduling durable rows transfer when their complete optional schemas are installed. Scheduling slot offers and booking holds must still be resolved before export, and destination-verification challenge/proof state is intentionally transient.

## Other client data imports or migrations

Run other client data migration only after the environment itself is green.

Recommended order:

```text
1. Deploy application/config.
2. Apply schema using engage:install for a new environment, or migrate + modules:migrate for an existing environment.
3. Run presets:sync when definitions changed and were not already materialized by engage:install.
4. Run modules:status and setup:validate.
5. Verify providers and workers.
6. Dry-run the import.
7. Inspect exact row-level output.
8. Apply only after approval.
9. Verify row counts, relationships, consent, scheduled work, and idempotency.
```

Import rules:

- Preserve actual consent state; do not manufacture consent from message history.
- Imported consent should use the dedicated import path when available.
- Do not emit normal consent-granted acknowledgement behavior for historical imported consent unless deliberately intended.
- Avoid replaying stale queued jobs from a legacy system.
- Prefer idempotent import behavior.
- Verify future scheduled messages, not only imported record counts.

---

# Phase 7 — Final launch gate

```text
[ ] Correct Core repository/commit deployed
[ ] Correct client repository/config deployed
[ ] CLIENT_KEY correct
[ ] Selected client preset correct
[ ] Selected client runtime modules correct
[ ] Client timezone correct
[ ] APP_ENV=production
[ ] APP_DEBUG=false
[ ] APP_KEY unique and preserved
[ ] Root/client `.env` permissions allow the deploy user and PHP-FPM to read them and deny world access
[ ] Selected-client `.env` contains only client-owned keys
[ ] No placeholder environment values
[ ] Production DB verified
[ ] Installed module migration scopes reviewed with `modules:status`
[ ] Redis isolation verified
[ ] Cache prefix unique
[ ] Horizon prefix unique
[ ] Horizon process path verified
[ ] Every required queue consumed
[ ] Laravel Scheduler cron installed and `schedule:list` verified
[ ] Runtime writable directories work across every process identity that needs them
[ ] Host memory/disk/swap posture is adequate for the deployment/build strategy
[ ] Production logging resolves to the intended root logging stack
[ ] Production observability verification passes when the Engage Core observability path is installed
[ ] No stale jobs from a previous disposable DB state
[ ] DNS correct
[ ] Nginx correct for every hostname
[ ] SSL valid
[ ] presets:sync completed
[ ] setup:validate passes
[ ] Initial CRM user exists
[ ] PROJECT_STATE_ADMIN_EMAIL is deliberately blank or matches the authorized CRM owner
[ ] Any controlled Project State transfer completed validation, apply, and dependency-ordered resume
[ ] No pending Project State resume items remain unless deliberately reconciled
[ ] Resend configured/tested when enabled
[ ] Telnyx configured/tested when enabled
[ ] Zoom Webinar and Meeting capabilities configured/tested when enabled
[ ] Provider webhook endpoints point to production
[ ] Registration flow tested for every Zoom event type in use
[ ] Reminder schedule inspected when Webinars enabled
[ ] Webinar/Meeting attendance and post-event flow tested when Webinars enabled
[ ] Routes/status transitions verified when enabled
[ ] Campaign enrollment verified when enabled
[ ] Duplicate-registration conflicts checked
[ ] Client data import verified and idempotent when applicable
```

---

# Phase 8 — Ongoing deployment procedure

For normal post-launch deployments:

```text
1. Record current deployed commit.
2. Pull/deploy approved Core and client commits.
3. Install production dependencies.
4. Build assets when frontend changed.
5. Apply new environment variables before code paths require them.
6. Run php artisan optimize:clear after env/config/route changes.
7. Run php artisan migrate --force for platform migrations.
8. Run php artisan modules:migrate --force for ledger-installed module scopes.
9. Run php artisan presets:sync when config/presets changed.
10. Run php artisan modules:status and php artisan setup:validate as the readiness gate.
11. Restart Horizon through Supervisor after any queued-job runtime code change; use the exact `<CLIENT_HORIZON_PROGRAM>` discovered from Supervisor.
12. Verify actual Horizon process path and queue list.
13. Run focused production-safe smoke checks for touched providers/modules.
14. When observability configuration or Nginx/PHP-FPM logging changed, rerun the production observability verifier.
```

Do not make the production test suite part of this procedure; production dependencies are installed with `--no-dev`, and automated tests belong in local/staging/CI.
Do not clear Redis indiscriminately during ordinary deployments.
Do not regenerate `APP_KEY`.
Do not destructively reset a production database containing real data.
Do not assume preset changes rewrite already scheduled message payloads.