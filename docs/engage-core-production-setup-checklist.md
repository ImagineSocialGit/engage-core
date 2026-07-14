# Engage Core — Generic Production Setup & Deployment Checklist

## Purpose

This checklist captures production setup steps and deployment lessons for bringing a new client onto Engage Core.

It is intended to prevent common production rollout problems:

- wrong application checkout used by Horizon;
- stale Redis jobs surviving a database reset;
- placeholder domain values in `.env`;
- incomplete third-party provider scopes;
- queue-prefix confusion during Redis diagnostics;
- missing or stale client configuration;
- Nginx/subdomain routing mistakes;
- provider credentials not fully enabled;
- production setup validation failures;
- post-event webhook and attendance processing gaps;
- duplicate registrations causing conflicting automation outcomes.

This is a generic checklist. Replace every placeholder before running commands in production.

---

## Placeholder Conventions

```text
<ROOT_DOMAIN>                 Example: example.com
<CLIENT_SLUG>                 Example: example-client
<APP_PATH>                    Example: /var/www/<ROOT_DOMAIN>/engage-core
<LEGACY_APP_PATH>             Example: /var/www/<ROOT_DOMAIN>/legacy-app
<DEPLOY_USER>                 Example: deploy
<WEB_USER>                    Example: www-data
<PHP_BIN>                     Example: /usr/bin/php8.3
<SUPERVISOR_PROGRAM>          Example: <ROOT_DOMAIN>-horizon
<GITHUB_ORG>                  Example: YourGitHubOrg
<ENGAGE_CORE_REPO>            Example: engage-core
<CLIENT_REPO>                 Example: <CLIENT_SLUG>
<GITHUB_SSH_HOST_ALIAS>       Example: github-<CLIENT_SLUG>-deploy
<REDIS_PREFIX>                Example: <CLIENT_SLUG>_
<HORIZON_PREFIX>              Example: <CLIENT_SLUG>
<DB_NAME>                     Example: engage_core_<CLIENT_SLUG>
<DB_USER>                     Example: engage_core_<CLIENT_SLUG>
<ZOOM_EXTERNAL_WEBINAR_ID>    Example: 86596191799
<WEBINAR_SLUG>                Example: homebuyer-game-plan-2026-07-14-1200am
<JOB_UUID>                    Example: 94eeecfb-a589-4edd-a5ac-060652bff469
```

---

# Phase 1 — Server and Repository Preparation

## 1. Create the production application directory

```bash
sudo mkdir -p /var/www/<ROOT_DOMAIN>/engage-core
sudo chown -R <DEPLOY_USER>:<DEPLOY_USER> /var/www/<ROOT_DOMAIN>/engage-core
```

Target path:

```text
<APP_PATH> = /var/www/<ROOT_DOMAIN>/engage-core
```

## 2. Inspect available SSH configuration and host aliases

Before cloning, check whether the server already has deploy-key host aliases.

```bash
ls -la ~/.ssh
```

```bash
test -f ~/.ssh/config && cat ~/.ssh/config || echo "No ~/.ssh/config found"
```

Show configured SSH host aliases:

```bash
awk '
    tolower($1) == "host" {
        for (i = 2; i <= NF; i++) {
            if ($i !~ /[*?]/) print $i
        }
    }
' ~/.ssh/config 2>/dev/null
```

If the deploy user is different from the current shell user, also check that user's SSH config:

```bash
sudo -u <DEPLOY_USER> sh -lc 'ls -la ~/.ssh && test -f ~/.ssh/config && cat ~/.ssh/config || true'
```

List public keys available to the current user:

```bash
ls -la ~/.ssh/*.pub 2>/dev/null || true
```

Check SSH agent keys if using an agent:

```bash
ssh-add -l 2>/dev/null || true
```

## 3. Test the GitHub SSH host alias

Test the standard GitHub host:

```bash
ssh -T git@github.com
```

Test the client/deploy-key alias:

```bash
ssh -T git@<GITHUB_SSH_HOST_ALIAS>
```

Expected GitHub behavior is usually a successful authentication message plus “shell access is not provided.”

If the alias is unknown, inspect `~/.ssh/config` for a block shaped like:

```sshconfig
Host <GITHUB_SSH_HOST_ALIAS>
    HostName github.com
    User git
    IdentityFile ~/.ssh/<PRIVATE_KEY_FOR_THIS_CLIENT>
    IdentitiesOnly yes
```

Do not assume `git@github.com:<GITHUB_ORG>/<REPO>.git` will work if the server uses per-client deploy keys or host aliases.

## 4. Clone Engage Core

Using a deploy-key host alias:

```bash
git clone git@<GITHUB_SSH_HOST_ALIAS>:<GITHUB_ORG>/<ENGAGE_CORE_REPO>.git <APP_PATH>
```

If the server intentionally uses the default GitHub SSH identity:

```bash
git clone git@github.com:<GITHUB_ORG>/<ENGAGE_CORE_REPO>.git <APP_PATH>
```

## 5. Clone or install the client repository/configuration

If client configuration lives in a separate repository:

```bash
git clone git@<GITHUB_SSH_HOST_ALIAS>:<GITHUB_ORG>/<CLIENT_REPO>.git /var/www/<ROOT_DOMAIN>/<CLIENT_REPO>
```

Verify Engage Core is actually loading the intended client package/configuration before syncing presets or validating setup.

## 6. Install dependencies and build assets

From `<APP_PATH>`:

```bash
cd <APP_PATH>
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Use the project’s actual supported deployment commands if they differ.

## 7. Set ownership and permissions

Verify the web server and queue worker user can write where required:

```bash
sudo chown -R <WEB_USER>:<WEB_USER> <APP_PATH>/storage <APP_PATH>/bootstrap/cache
sudo chmod -R ug+rwX <APP_PATH>/storage <APP_PATH>/bootstrap/cache
```

Do not blindly copy ownership commands between servers without checking the actual deployment user, web user, and PHP-FPM setup.

---

# Phase 2 — Environment Configuration

## 8. Create the production `.env`

Do not leave `.env.example` placeholders in place.

Bad placeholder examples:

```env
APP_URL=https://DOMAIN
ROOT_DOMAIN=DOMAIN.com
WEBINAR_APP_URL=https://webinar.DOMAIN
CRM_APP_URL=https://crm.DOMAIN
WEBHOOKS_APP_URL=https://webhooks.DOMAIN
```

Set actual domains:

```env
APP_URL=https://<ROOT_DOMAIN>
ROOT_DOMAIN=<ROOT_DOMAIN>
WEBINAR_APP_URL=https://webinar.<ROOT_DOMAIN>
CRM_APP_URL=https://crm.<ROOT_DOMAIN>
WEBHOOKS_APP_URL=https://webhooks.<ROOT_DOMAIN>
```

Then clear cached configuration:

```bash
cd <APP_PATH>
php artisan optimize:clear
```

## 9. Configure the application environment

Verify at minimum:

```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=
```

Generate the app key if required:

```bash
php artisan key:generate
```

Do not regenerate an existing production `APP_KEY` after encrypted production data exists.

## 10. Configure database credentials

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=<DB_NAME>
DB_USERNAME=<DB_USER>
DB_PASSWORD=<DB_PASSWORD>
```

Confirm the app can connect:

```bash
php artisan tinker --execute="DB::connection()->getPdo(); dump('database ok');"
```

## 11. Configure Redis

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PREFIX=<REDIS_PREFIX>
```

Inspect effective Redis state:

```bash
php artisan tinker --execute="dump([
    'redis_client' => config('database.redis.client'),
    'redis_default' => config('database.redis.default'),
    'redis_cache' => config('database.redis.cache'),
    'queue_default' => config('queue.default'),
    'queue_redis' => config('queue.connections.redis'),
]);"
```

Important: the Redis prefix affects raw queue-key inspection.

If:

```env
REDIS_PREFIX=<REDIS_PREFIX>
```

then queue keys are usually shaped like:

```text
<REDIS_PREFIX>queues:default
<REDIS_PREFIX>queues:default:delayed
<REDIS_PREFIX>queues:default:reserved
```

Example commands:

```bash
redis-cli LLEN <REDIS_PREFIX>queues:default
redis-cli ZCARD <REDIS_PREFIX>queues:default:delayed
redis-cli ZCARD <REDIS_PREFIX>queues:default:reserved
```

Do not inspect unprefixed queue keys and assume queues are empty.

## 12. Configure Horizon

```env
QUEUE_CONNECTION=redis
HORIZON_PREFIX=<HORIZON_PREFIX>
```

If queue names are environment-driven:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,reminders,opt_in_messages,post_event,marketing
```

Verify effective Horizon config:

```bash
php artisan tinker --execute="dump([
    'horizon_prefix' => config('horizon.prefix'),
    'horizon_use' => config('horizon.use'),
    'horizon_environment' => config('horizon.environments.'.app()->environment()),
]);"
```

---

# Phase 3 — Nginx, DNS, SSL, and Subdomains

## 13. Configure DNS

Verify required hosts point to the production server:

```text
<ROOT_DOMAIN>
crm.<ROOT_DOMAIN>
webinar.<ROOT_DOMAIN>
webhooks.<ROOT_DOMAIN>
```

## 14. Configure Nginx

Every Engage Core hostname must point to:

```text
<APP_PATH>/public
```

not a legacy checkout.

Find relevant Nginx config files:

```bash
grep -R "<ROOT_DOMAIN>\|crm.<ROOT_DOMAIN>\|webinar.<ROOT_DOMAIN>\|webhooks.<ROOT_DOMAIN>" \
    /etc/nginx/sites-available \
    /etc/nginx/sites-enabled 2>/dev/null
```

Check for accidental legacy paths:

```bash
grep -R "<LEGACY_APP_PATH>\|leadflow-core\|old-app\|legacy" \
    /etc/nginx/sites-available \
    /etc/nginx/sites-enabled 2>/dev/null || true
```

Each relevant server block should point to:

```nginx
root <APP_PATH>/public;
```

## 15. Test and reload Nginx

```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 16. Configure SSL certificates

Ensure certificates cover:

```text
<ROOT_DOMAIN>
crm.<ROOT_DOMAIN>
webinar.<ROOT_DOMAIN>
webhooks.<ROOT_DOMAIN>
```

Verify HTTPS responses:

```bash
curl -I https://<ROOT_DOMAIN>
curl -I https://crm.<ROOT_DOMAIN>
curl -I https://webinar.<ROOT_DOMAIN>
curl -I https://webhooks.<ROOT_DOMAIN>
```

## 17. Verify application route hosts

```bash
php artisan route:list
php artisan route:list | grep -E "<ROOT_DOMAIN>|crm\.|webinar\.|webhooks\."
```

Confirm routes show intended production domains rather than placeholder hosts.

---

# Phase 4 — Supervisor and Horizon

## 18. Create or update the Supervisor Horizon program

Example:

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

Create/edit:

```bash
sudo nano /etc/supervisor/conf.d/<SUPERVISOR_PROGRAM>.conf
```

Critical check: verify all three paths point to the current app checkout:

```text
command=
directory=
stdout_logfile=
```

Search Supervisor configs for old paths:

```bash
grep -R "<ROOT_DOMAIN>\|<APP_PATH>\|<LEGACY_APP_PATH>" /etc/supervisor/conf.d
```

## 19. Reload Supervisor after changes

```bash
sudo supervisorctl reread && \
sudo supervisorctl update && \
sudo supervisorctl restart <SUPERVISOR_PROGRAM>
```

## 20. Verify the actual running process path

```bash
ps aux | grep "[a]rtisan horizon"
```

Confirm the active process points to:

```text
<APP_PATH>/artisan horizon
```

## 21. Treat Supervisor as the source of truth

Use Supervisor to stop/start/restart the production Horizon process unless this deployment has explicitly proven Artisan Horizon lifecycle commands are reliable.

---

# Phase 5 — Critical Redis Rule Before Destructive Database Resets

## 22. Never run `migrate:fresh` while old queued jobs remain in Redis

A database reset does not clear Redis.

Dangerous sequence:

```text
old delayed jobs remain in Redis
→ database is dropped and recreated
→ primary keys are reused
→ stale jobs later execute against new rows with recycled IDs
```

## 23. Safe destructive-reset sequence

```text
1. Stop the relevant Horizon Supervisor process.
2. Confirm no legitimate jobs must be preserved.
3. Flush the correct Redis database when appropriate.
4. Run the destructive database reset/migrations/import.
5. Restart Horizon through Supervisor.
6. Verify the running checkout path.
7. Verify the expected queue list.
```

Generic command:

```bash
sudo supervisorctl stop <SUPERVISOR_PROGRAM> && \
redis-cli FLUSHDB && \
sudo supervisorctl start <SUPERVISOR_PROGRAM>
```

Only use `FLUSHDB` when it is safe for that Redis database.

## 24. Verify stale jobs are gone

```bash
for queue in default notifications confirmation_messages reminders opt_in_messages post_event marketing; do
    echo "== $queue =="
    redis-cli LLEN <REDIS_PREFIX>queues:$queue
    redis-cli ZCARD <REDIS_PREFIX>queues:$queue:delayed
    redis-cli ZCARD <REDIS_PREFIX>queues:$queue:reserved
done
```

---

# Phase 6 — Database, Migrations, Client Data, and Presets

## 25. Run migrations or the approved destructive reset

Use the correct production strategy. For pre-production/non-sacred data, a destructive reset may be acceptable only after queue state has been handled correctly.

## 26. Sync presets

```bash
php artisan presets:sync
```

Verify:

```text
correct client package selected
expected modules enabled
expected preset package selected
no wrong legacy/default package active
```

## 27. Sync other DB-backed preset systems as required

Depending on enabled modules:

```bash
php artisan messaging:template-presets:sync
php artisan webinars:schedule-profiles:sync
```

Use commands actually supported by the current codebase.

## 28. Run setup validation

```bash
php artisan setup:validate
```

Do not ignore warnings or errors without understanding them.

Setup validation failures can indicate:

```text
bad config
missing provider credentials
disabled channels
missing scopes
wrong preset package
actual bug in validation logic
```

---

# Phase 7 — Messaging Providers

## 29. Configure email provider

For Engage Core, email uses Resend.

Verify:

```text
API key
sender/from address
domain verification
provider enabled flag
production sending permissions
effective channel availability
```

## 30. Configure SMS provider

For Engage Core, SMS primarily uses Telnyx.

Verify:

```text
API credentials
sending number
provider enabled flag
messaging profile settings if applicable
production phone number assignment
channel availability config
```

Common issue:

```text
provider_enabled=false
```

## 31. Verify real provider sends before launch

Use a production-safe test path and verify both email and SMS can reach:

```text
sent
```

---

# Phase 8 — Webinar Provider / Zoom Setup

## 32. Configure the Zoom Server-to-Server OAuth app

If the client uses Zoom, verify whether the app type is:

```text
Server-to-Server OAuth
```

## 33. Add required Zoom scopes

### Webinar registration and lookup

```text
webinar:write:registrant:admin
webinar:delete:registrant:admin
webinar:read:list_webinars:admin
webinar:read:webinar:admin
webinar:read:list_registrants:admin
```

### Recording

```text
cloud_recording:read:list_recording_files:admin
cloud_recording:read:recording:admin
```

### Attendance reports

Critical for attendance reconciliation:

```text
report:read:list_webinar_participants:admin
```

Without this scope, the application cannot retrieve past webinar participant attendance from:

```text
GET /report/webinars/{webinarId}/participants
```

## 34. Verify provider credentials and app activation

Confirm:

```text
Zoom account ID
Zoom client ID
Zoom client secret
app active
newly added scopes effective
```

## 35. Configure Zoom webhooks

Verify:

```text
webhook endpoint URL
webhook secret/signature configuration
app subscription active
correct event subscriptions
endpoint resolves through webhooks.<ROOT_DOMAIN>
Nginx points webhooks host at <APP_PATH>/public
```

Important event examples:

```text
webinar.ended
recording.completed
```

## 36. Verify webhook route

```bash
php artisan route:list | grep -i zoom
php artisan route:list | grep -i webhook
```

Confirm the URL matches the Zoom app configuration exactly.

## 37. Verify webhook delivery before a live event

Confirm:

```text
Zoom event emitted
→ webhook request reaches Engage Core
→ signature accepted
→ provider event parsed
→ intended job dispatched
```

---

# Phase 9 — Webinar Attendance and Post-Event Processing

## 38. Verify `webinar.ended` processing configuration

```bash
php artisan tinker --execute="dump(config('webinars.post_event.events')['webinar.ended'] ?? null);"
```

## 39. Verify attendance retrieval

Current Zoom provider flow:

```text
ZoomWebinarProvider::listAttendanceRecords()
→ ZoomWebinarService::listPastWebinarParticipants()
→ GET /report/webinars/{webinarId}/participants
→ ZoomAttendanceMapper
→ RecordWebinarProviderAttendanceAction
→ RecordWebinarAttendanceAction
```

Ensure the report scope exists before launch.

## 40. Inspect a queued job by UUID in prefixed Redis keys

```bash
redis-cli LRANGE <REDIS_PREFIX>queues:default 0 -1 | grep -F "<JOB_UUID>"
redis-cli ZRANGE <REDIS_PREFIX>queues:default:delayed 0 -1 | grep -F "<JOB_UUID>"
redis-cli ZRANGE <REDIS_PREFIX>queues:default:reserved 0 -1 | grep -F "<JOB_UUID>"
```

## 41. Verify actual registration outcomes after processing

Inspect:

```text
status
attended_at
attendance metadata
automation events
contact status
campaign enrollment
scheduled follow-ups
```

Do not stop at “job completed.” Verify the complete chain:

```text
provider attendance resolved
→ registration outcome recorded
→ automation event emitted
→ Route executed
→ contact status changed
→ correct campaign/follow-up path selected
```

---

# Phase 10 — Duplicate Registration / Identity Safety

## 42. Check for likely duplicate registrations before post-event follow-ups

Risk pattern:

```text
same person
same phone
different email addresses
two Contact records
two WebinarRegistration records
conflicting outcomes
```

Immediate operational rule:

```text
Attended wins over missed.
```

Before follow-ups send, inspect duplicate registrations and suppress conflicting outcomes.

Longer-term system rule should likely be webinar-scoped:

```text
same webinar
+ same normalized phone
+ conflicting registration outcomes
→ attended wins over missed
```

Do not globally auto-merge contacts solely on phone number without a broader identity-resolution design.

---

# Phase 11 — Reminder and Join-Link Safety

## 43. Do not treat every GET request to a join redirect as proof of human interaction

Known risk:

```text
GET personalized join URL
→ mark join_clicked_at
→ increment join_click_count
→ skip eligible live reminders
```

Email security scanners/prefetchers can hit links without a human consciously clicking.

Recommended future flow:

```text
GET personalized join URL
→ render lightweight "Joining webinar..." page
→ JavaScript waits briefly
→ POST signed confirmation
→ mark join_clicked_at
→ skip live reminder
→ redirect to Zoom
```

Preserve raw resolver signals separately:

```text
join_resolved_at
join_resolve_count
```

Use stronger evidence for:

```text
join_clicked_at
join_click_count
```

---

# Phase 12 — Production Smoke Tests Before Launch

## 44. Verify application URLs

```bash
curl -I https://<ROOT_DOMAIN>
curl -I https://crm.<ROOT_DOMAIN>
curl -I https://webinar.<ROOT_DOMAIN>
curl -I https://webhooks.<ROOT_DOMAIN>
```

## 45. Verify routes

```bash
php artisan route:list
```

## 46. Verify setup

```bash
php artisan setup:validate
```

## 47. Verify Horizon process path

```bash
ps aux | grep "[a]rtisan horizon"
```

## 48. Verify queues

```bash
php artisan tinker --execute="dump(config('horizon.environments.'.app()->environment()));"
```

## 49. Verify Redis prefix

```bash
php artisan tinker --execute="dump([
    'queue_default' => config('queue.default'),
    'redis_default' => config('database.redis.default'),
    'redis_cache' => config('database.redis.cache'),
    'redis_horizon' => config('database.redis.horizon'),
    'queue_redis' => config('queue.connections.redis'),
    'horizon_prefix' => config('horizon.prefix'),
    'horizon_use' => config('horizon.use'),
    'horizon_environment' => config('horizon.environments.'.app()->environment()),
]);"
```

## 50. Verify provider sends

Send production-safe email and SMS tests and confirm database status reaches `sent`.

## 51. Verify registration flow

Test:

```text
public registration
→ contact created/reused
→ registration created
→ provider registrant created
→ personalized join URL stored
→ confirmations scheduled/sent
→ opt-in behavior correct
→ reminders scheduled
```

## 52. Verify webhook delivery

Test the real production webhook endpoint before launch.

## 53. Verify post-event flow

Before relying on a live client webinar, test or simulate:

```text
webinar ended
→ attendance retrieval
→ attended/missed outcomes
→ automation events
→ Routes
→ statuses
→ campaign enrollments
→ post-event follow-ups
```

---

# Phase 13 — Final Pre-Live Checklist

```text
[ ] Correct Engage Core repo deployed
[ ] Correct client repo/config active
[ ] SSH host alias verified, if using deploy keys
[ ] Production .env has no placeholder domains
[ ] APP_ENV=production
[ ] APP_DEBUG=false
[ ] Database connection verified
[ ] Redis connection verified
[ ] Redis prefix understood
[ ] Nginx hosts point to <APP_PATH>/public
[ ] SSL valid for all required hosts
[ ] Supervisor Horizon program points to <APP_PATH>
[ ] Running Horizon process path verified
[ ] Required queues consumed
[ ] No stale Redis jobs from previous app/database state
[ ] Presets synced
[ ] Messaging templates synced if required
[ ] Webinar schedule profiles synced if required
[ ] Setup validation passes
[ ] Resend configured and tested
[ ] Telnyx configured, enabled, and tested
[ ] Zoom Server-to-Server OAuth app active, if applicable
[ ] Zoom registration scopes present, if applicable
[ ] Zoom recording scopes present, if applicable
[ ] Zoom participant-report scope present, if applicable
[ ] Zoom webhook endpoint correct, if applicable
[ ] Zoom webhook events subscribed, if applicable
[ ] Webhook route reachable
[ ] Registration flow tested
[ ] Confirmation email tested
[ ] Confirmation SMS tested
[ ] Reminder schedule inspected
[ ] Post-event attendance processing tested
[ ] Routes/status transitions verified
[ ] Campaign enrollment verified
[ ] Duplicate-registration conflicts checked
```

---

# Recommended Production Deployment Order

```text
1. Provision server paths and repositories.
2. Inspect SSH keys and host aliases.
3. Test GitHub SSH access.
4. Clone Engage Core and client configuration.
5. Install dependencies and build assets.
6. Configure .env completely.
7. Configure database and Redis.
8. Configure DNS, Nginx, SSL, and subdomains.
9. Configure Supervisor to the correct Engage Core checkout.
10. Stop Horizon before any destructive database reset.
11. Clear stale Redis state when safe and necessary.
12. Run migrations/imports.
13. Sync presets/templates/schedule profiles.
14. Run setup validation.
15. Configure and verify Resend.
16. Configure and verify Telnyx.
17. Configure Zoom app, scopes, credentials, and webhooks if applicable.
18. Restart Horizon through Supervisor.
19. Verify the real running process path.
20. Verify all consumed queues.
21. Smoke-test real email and SMS.
22. Test registration and reminder scheduling.
23. Test webhook ingress.
24. Test attendance/post-event processing.
25. Inspect duplicate registrations before follow-ups.
26. Only then consider the client production-ready.
```

---

# Most Important Lessons

## 1. Database resets and Redis queues must be treated as one operational unit

A fresh database with stale delayed jobs is unsafe.

## 2. Verify the actual running Horizon process path, not just the config file

The wrong checkout can consume jobs and create incomplete-class failures.

## 3. Raw Redis diagnostics must use the configured prefix

Unprefixed queue checks can falsely suggest Redis is empty.

## 4. Placeholder domains in `.env` can make correct routes appear broken

Always inspect effective route hosts after environment setup.

## 5. Provider scopes are part of production readiness

Zoom webinar read/registrant scopes do not imply attendance-report access.

## 6. “Job completed” is not enough

Verify the actual downstream business effects.

## 7. Duplicate registrations can create contradictory automation outcomes

Attended versus missed conflicts must be resolved before messages send.

## 8. A GET request is not trustworthy proof of human interaction

Email scanners can trigger links and suppress reminders.

## 9. Setup validation is valuable, but the validator itself can also be wrong

Investigate both configuration and validation logic.

## 10. Before a live webinar, test the complete lifecycle

```text
registration
→ confirmations
→ reminders
→ join behavior
→ webhook
→ attendance
→ outcome events
→ Routes
→ statuses
→ campaigns
→ follow-ups
```