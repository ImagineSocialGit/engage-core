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
<CLIENT_PRESET>              Example: mortgage
<ROOT_DOMAIN>                Example: example.com
<STAGING_ROOT_DOMAIN>        Example: staging.example.com
<APP_PATH>                   Example: /var/www/<ROOT_DOMAIN>/engage-core
<CLIENT_PATH>                Example: /var/www/<ROOT_DOMAIN>/engage-core/client/<CLIENT_KEY>
<DEPLOY_USER>                Example: deploy
<WEB_USER>                   Example: www-data
<PHP_BIN>                    Example: /usr/bin/php8.3
<SUPERVISOR_PROGRAM>         Example: <ROOT_DOMAIN>-horizon
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
- [ ] `CLIENT_PRESET` is final and exists in the effective merged `presets.packages` config.
- [ ] `ENABLED_MODULES` is explicitly decided.
- [ ] Client-facing contact labels are correct.
- [ ] Client timezone is correct in stable client config or via deliberate `CLIENT_TIMEZONE` override.
- [ ] Required client config files exist.
- [ ] No placeholder domains, sender addresses, phone numbers, provider IDs, or secrets remain in client configuration.

Keep these concepts separate:

```text
CLIENT_KEY
    selects the client config package

CLIENT_PRESET
    selects preset composition

ENABLED_MODULES
    selects explicitly enabled runtime features

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
CLIENT_PRESET and ENABLED_MODULES are separate decisions.
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

## 11. Create the staging `.env`

Start from the updated `.env.example` in this batch.

Required staging differences:

```env
APP_ENV=staging
APP_DEBUG=false
```

Use staging-specific values for:

```text
APP_KEY
APP_URL and host URLs
DB database/credentials
CACHE_PREFIX
REDIS_PREFIX and/or Redis DB isolation
HORIZON_PREFIX
provider webhook URLs
provider credentials where environment-specific
storage bucket/prefix where environment-specific
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

Verify:

```env
DB_CONNECTION=mysql
DB_HOST=
DB_PORT=3306
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

Recommended explicit values:

```env
REDIS_DB=0
REDIS_CACHE_DB=1
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

## 18. Configure the Horizon queue list explicitly

The provided Core configs can currently dispatch work to queues including:

```text
default
notifications
confirmation_messages
opt_in_messages
reminders
post_event
marketing
campaigns
waitlist
sms
webinars
webhooks
```

Use an explicit `HORIZON_SUPERVISOR_1_QUEUES` value until Core's built-in Horizon defaults are updated to reflect all executable queue paths.

Example:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,opt_in_messages,reminders,post_event,marketing,campaigns,waitlist,sms,webinars,webhooks
```

Do not add an `emails` queue merely because it appeared in an older env file; the supplied current Core configs do not dispatch to that queue.

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

At minimum:

```text
Zoom credentials work
registration API works
personalized join URL is stored
registration confirmation planning works
schedule profile is selected and active
future reminders are scheduled correctly
webhook endpoint receives signed events
attendance retrieval works
recording.completed can resolve playback
post-event follow-ups wait for required playback conditions
webinar.attended / webinar.missed automation events work
selected Routes run
Campaign enrollments occur when intended
```

Current Core post-event orchestration is materially split:

```text
webinar.ended
    records provider attendance

webinar.recording_completed
    resolves playback
    dispatches post-webinar follow-ups
```

Do not assume `webinar.ended` alone sends replay follow-ups.

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

Use the actual Supervisor program and verify the process path afterward:

```bash
sudo supervisorctl restart <SUPERVISOR_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

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

Before relying on a live client webinar:

```text
public registration
→ contact created/reused
→ registration created
→ provider registrant created
→ personalized join URL stored
→ confirmations planned
→ consent behavior correct
→ reminders scheduled
→ webhook accepted
→ attendance recorded
→ attended/missed events emitted
→ selected Routes execute
→ status transitions occur when intended
→ Campaign enrollment occurs when intended
→ recording.completed resolves playback
→ post-event follow-ups dispatch only when conditions are satisfied
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
[ ] CLIENT_PRESET correct
[ ] ENABLED_MODULES correct
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
[ ] Zoom configured/tested when enabled
[ ] Provider webhook endpoints point to production
[ ] Registration flow tested when Webinars enabled
[ ] Reminder schedule inspected when Webinars enabled
[ ] Attendance and post-event flow tested when Webinars enabled
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
10. Restart Horizon through Supervisor.
11. Verify actual Horizon process path and queue list.
12. Run focused production-safe smoke checks for touched providers/modules.
```

Do not clear Redis indiscriminately during ordinary deployments.
Do not regenerate `APP_KEY`.
Do not destructively reset a production database containing real data.
Do not assume preset changes rewrite already scheduled message payloads.
