# Engage Core — Client Staging & Production Setup Checklist

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
- [ ] Client key/token extensions.

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

## 6. Provision the staging application path

Example:

```text
/var/www/<STAGING_ROOT_DOMAIN>/engage-core
```

Verify the intended deployment user, web user, PHP version, PHP-FPM socket, Composer, Node/npm, MySQL client access, Redis access, Nginx, Supervisor, and required PHP extensions.

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

Install or clone the client package in the location expected by Engage Core.

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

Verify the web server and queue worker user can write where required:

```text
storage/
bootstrap/cache/
```

Do not blindly copy `chown`/`chmod` commands between servers. Confirm the actual deployment and web users first.

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
initial-user bootstrap values
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
storage credentials/bucket/CDN URL
other selected-client deployment values
```

Do not leave placeholder values such as `DOMAIN`, `CHANGE_ME`, empty required sender addresses, or blank provider secrets in an environment intended for handoff testing.

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

Typical application topology:

```text
<root domain>
crm.<root domain>
webinar.<root domain>
webhooks.<root domain>
```

Use the actual environment topology; do not assume staging must use the exact same naming pattern as production.

For every hostname:

- [ ] DNS resolves to the intended staging server.
- [ ] Nginx points to the intended Engage Core `public/` directory.
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

Confirm route hosts are correct.

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

## 19. Run migrations

For a normal staging deployment:

```bash
php artisan migrate --force
```

A destructive reset is acceptable only when the environment data is disposable and queued Redis state has been handled first. See `deployment-safety-and-troubleshooting.md`.

## 20. Sync DB-owned definitions

Run the canonical orchestrator:

```bash
php artisan presets:sync
```

Current sync architecture may materialize, when selected/enabled:

```text
ContactStatus definitions
Task templates
Messaging template presets/assignments/catalog entries
Webinar schedule profiles/items
Campaigns/steps/variants
FlowRoute capabilities
FlowRoutes/points/bindings
```

Do not assume an old list of separate sync commands remains necessary. Use the current orchestrator and only run extra commands when current source explicitly requires them.

## 21. Run setup validation

```bash
php artisan setup:validate
```

Gate:

```text
errors: block handoff
warnings: understand and deliberately accept or resolve
clean: proceed
```

Do not auto-fix validation failures by broadening config contracts or adding unsupported config keys.

## 22. Bootstrap the initial CRM user when required

The current setup config supports:

```env
SETUP_USER_NAME=
SETUP_USER_EMAIL=
SETUP_USER_PASSWORD=
```

Use the actual current setup/seeding command for the repository.

Treat the bootstrap password as a secret. After the initial user exists and the command no longer needs the env values, remove or rotate the secret according to the operational policy.

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
[ ] DNS/Nginx/SSL verified
[ ] CRM login verified
[ ] Email tested when enabled
[ ] SMS tested when enabled
[ ] Provider webhooks tested when enabled
[ ] Webinar registration/reminders/post-event path tested when enabled
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
- [ ] No legacy application path in Nginx or Supervisor.

## 35. Build and cache carefully

Typical sequence:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan optimize:clear
```

Apply any project-approved config/route/view caching only after the final environment is complete.

## 36. Run production migrations

Normal production path:

```bash
php artisan migrate --force
```

Do not use `migrate:fresh` after real data matters.

For pre-launch disposable-data resets, stop workers and handle Redis queue state first. See the safety document.

## 37. Sync presets and validate setup

```bash
php artisan presets:sync
php artisan setup:validate
```

Resolve errors before launch.

## 38. Restart Horizon through Supervisor

Inspect and use the actual Supervisor program name rather than guessing it:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

This restart is mandatory after deploying PHP changes that alter queued-job runtime behavior, including job execution, validation, payload rendering, gates, or providers, because long-running workers otherwise continue executing the old code already loaded in memory.

## 39. Verify production routes and hosts

```bash
php artisan route:list
```

Check every required hostname.

---

# Phase 5 — Production smoke test

Run production-safe tests before real client traffic or a live event.

## 40. Infrastructure smoke test

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
[ ] setup:validate passes
```

## 41. Messaging smoke test

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

## 42. Webinar smoke test

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

## 43. Check for duplicate-registration conflicts

Before a live event or legacy import, confirm that one person does not have conflicting duplicate registrations for the same webinar.

Do not globally merge contacts solely by phone number without a broader identity-resolution design.

## 44. Verify no stale jobs survived previous disposable-data resets

Inspect the actual prefixed queue keys when there has been a destructive reset or app migration.

See `deployment-safety-and-troubleshooting.md`.

---

# Phase 6 — Client data import or migration

Run client data migration only after the environment itself is green.

Recommended order:

```text
1. Deploy application/config.
2. Run migrations.
3. Run presets:sync.
4. Run setup:validate.
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
[ ] No placeholder environment values
[ ] Production DB verified
[ ] Redis isolation verified
[ ] Cache prefix unique
[ ] Horizon prefix unique
[ ] Horizon process path verified
[ ] Every required queue consumed
[ ] No stale jobs from a previous disposable DB state
[ ] DNS correct
[ ] Nginx correct for every hostname
[ ] SSL valid
[ ] presets:sync completed
[ ] setup:validate passes
[ ] Initial CRM user exists
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
7. Run php artisan migrate --force.
8. Run php artisan presets:sync when config/presets changed.
9. Run php artisan setup:validate when setup/config changed.
10. Restart Horizon through Supervisor after any queued-job runtime code change; use the exact `<CLIENT_HORIZON_PROGRAM>` discovered from Supervisor.
11. Verify actual Horizon process path and queue list.
12. Run focused production-safe smoke checks for touched providers/modules.
```

Do not clear Redis indiscriminately during ordinary deployments.
Do not regenerate `APP_KEY`.
Do not destructively reset a production database containing real data.
Do not assume preset changes rewrite already scheduled message payloads.