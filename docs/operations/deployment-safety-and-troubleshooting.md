# Engage Core — Deployment Safety & Troubleshooting

## Purpose

This document preserves operational lessons that should not clutter the canonical staging/production setup sequence but are important when diagnosing a rollout.

Use it for:

```text
wrong checkout/process issues
stale Redis jobs
queue-prefix confusion
incomplete Horizon queue consumption
placeholder-domain problems
provider-scope/webhook failures
post-event Webinar debugging
duplicate registrations
join-link scanner/prefetch safety
```

The canonical command-level install and deployment sequence is [`operations/deployment-command-workflow.md`](deployment-command-workflow.md). After the modular migration cutover, plain `php artisan migrate` and `migrate:fresh` operate on the platform path only; module schema is handled by the explicit module commands or `engage:install`.

---

# 1. Wrong application checkout used by Horizon

## Failure mode

Supervisor can point at an old or legacy checkout while Nginx serves the new Engage Core checkout.

That creates a dangerous split:

```text
web requests use new code
queued jobs use old code
```

Serialized jobs may then fail as incomplete/unknown classes or execute obsolete behavior.

## Required checks

Inspect Supervisor:

```text
command=
directory=
stdout_logfile=
```

Then verify the actual running process:

```bash
ps aux | grep "[a]rtisan horizon"
```

Do not stop after reading the Supervisor config. Confirm the live process path.

## Recovery

1. Stop the wrong Supervisor program/process.
2. Correct all paths.
3. `reread` and `update` Supervisor.
4. Start/restart the intended program.
5. Verify the actual process path.
6. Inspect failed/pending queues for jobs serialized by the wrong application state.

## Stale long-running workers after a code deploy

A second failure mode is subtler: Supervisor points to the correct checkout, but long-running Horizon workers still have the old PHP code loaded in memory.

Symptom pattern:

```text
deploy queued-job/runtime fix
→ some later jobs behave correctly
→ other retried jobs still execute obsolete validation/rendering behavior
```

This can affect changes to:

```text
queued job classes
job validation
payload rendering
unresolved-token guards
message gates
providers
other queue-worker runtime behavior
```

Inspect the actual Supervisor program name instead of guessing:

```bash
sudo supervisorctl status
sudo grep -R "^\[program:" /etc/supervisor /etc/supervisor/conf.d 2>/dev/null
```

Then restart the Supervisor-managed Horizon process:

```bash
sudo supervisorctl restart <CLIENT_HORIZON_PROGRAM>
ps aux | grep "[a]rtisan horizon"
```

Supervisor is the lifecycle source of truth for this deployment path. Do not rely on an Artisan Horizon lifecycle command as a substitute when Supervisor owns the process.

---

# 2. Stale Redis jobs after destructive database reset

## Critical rule

A database reset does not clear Redis.

Dangerous sequence:

```text
old delayed jobs remain in Redis
→ database is dropped/recreated
→ primary keys are reused
→ stale jobs later execute against unrelated new rows
```

## Safe disposable-data reset sequence

Only when the environment's data is explicitly disposable:

```text
1. Stop the relevant Horizon Supervisor program.
2. Confirm no legitimate queued jobs must be preserved.
3. Identify the exact Redis DB/prefix used by this app/environment.
4. Flush only the correct Redis DB when safe, or delete only the intended keys.
5. Run the destructive DB reset/migrations/import.
6. Restart Horizon through Supervisor.
7. Verify the actual process path.
8. Verify every required queue.
```

Example only:

```bash
sudo supervisorctl stop <SUPERVISOR_PROGRAM>
redis-cli FLUSHDB
sudo supervisorctl start <SUPERVISOR_PROGRAM>
```

Do not run `FLUSHDB` until you know whether sessions, cache, queues, locks, Horizon metadata, or other applications share that Redis DB.

Production rule:

```text
Once real data matters, do not use destructive database resets as a routine deployment technique.
```

## Controlled Project State rebuild exception

Project State supports a deliberately approved clean rebuild only when the current transfer contract covers every durable row that must survive.

This is not permission to run `migrate:fresh` during a normal deploy. The controlled path requires:

```text
independent database backup
maintenance/write freeze
Horizon and Scheduler stopped
current-format Project State export
exact Redis namespace cleanup
target platform-only migrate:fresh
engage:install for configured module schema, presets, and setup validation
validation-only upload
transactional apply
explicit dependency-ordered resume
provider and queue reconciliation
```

Project State does not export Redis jobs. Old delayed jobs must be removed from the exact source/target Redis namespace before primary keys are reused. Supported runnable database work is imported inert and recreated only through explicit resume.

Export is blocked when pending resume items remain, unsupported durable tables contain rows, database-backed jobs exist, operational receipts are nonterminal, schema changes are unclassified, or references cannot be restored safely.

Use [`operations/project-state-transfer-runbook.md`](project-state-transfer-runbook.md) for the complete procedure.

---

# 3. Queue-prefix confusion

Raw Laravel Redis queue keys may be prefixed.

If:

```env
REDIS_PREFIX=example_production_
```

then the real key may look like:

```text
example_production_queues:default
```

not:

```text
queues:default
```

Useful checks:

```bash
redis-cli --scan --pattern '*queues:*'
redis-cli --scan --pattern '*horizon*'
```

For a specific known prefixed queue:

```bash
redis-cli LLEN <REDIS_PREFIX>queues:default
redis-cli ZCARD <REDIS_PREFIX>queues:default:delayed
redis-cli ZCARD <REDIS_PREFIX>queues:default:reserved
```

Never inspect an unprefixed key, see zero, and conclude the app queue is empty without first checking effective config.

When the root environment contains:

```env
REDIS_PASSWORD=null
```

the literal value `null` represents no Redis password. Do not export `REDISCLI_AUTH=null`; that makes `redis-cli` attempt authentication against a server that may have no password configured.

---

# 4. Delayed-job diagnostics: database `send_at` is not enough

When debugging delayed delivery, do not infer the actual Redis delay solely from `scheduled_messages.send_at`.

A queued Laravel job can preserve timezone-aware delay metadata even when a persisted timestamp was normalized incorrectly. Before manipulating Redis or requeueing a job, inspect:

```text
Horizon Delayed Until
serialized queue delay metadata
the timezone and instant carried by the serialized delay object
```

Diagnostic rule:

> Inspect the actual Horizon `Delayed Until` value and/or serialized queue delay metadata before manipulating Redis or requeueing jobs. Do not assume a persisted database `send_at` value proves the Redis delay is wrong.

`ScheduleMessageAction` normalizes timezone-aware Carbon values to UTC before persistence and queue delay registration. `ScheduledMessage.send_at`, the queued delay, and Horizon `send_at` metadata therefore represent the same instant. Current ScheduledMessage metadata does not retain a duplicate source-timezone scheduling snapshot; diagnose discrepancies from the owning definition/binding, the persisted UTC instant, and the queued job payload. A discrepancy in older data is a code/data consistency issue; it is not, by itself, proof that the queued delay is wrong.

---

# 5. Horizon queue list does not cover actual dispatch queues

## Current known risk

Horizon must consume every queue that current runtime/config can actually dispatch to.

Current executable/configured queues include:

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

Current runtime notes:

```text
emails is an active queue path.
Webinar waitlist delivery uses notifications; there is no canonical separate waitlist queue requirement.
Do not preserve an old campaigns queue requirement from stale Webinar nurture config.
```

## Deployment protection

Set and verify an explicit queue list when the built-in Horizon defaults are not confirmed to cover the current runtime:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,opt_in_messages,reminders,post_event,marketing,emails,sms,webinars,webhooks
```

Then verify effective Horizon environment config:

```bash
php artisan tinker --execute="dump(config('horizon.environments.'.app()->environment()));"
```

Do not leave queue behavior dependent on one hand-maintained historical `.env` forever. Reconcile queue registry/config, Horizon defaults, and deployed environment values whenever executable queue paths change.

---

# 5A. Shared runtime directories look writable but cross-user writes fail

## Failure mode

A deployment can show:

```text
storage/            775
bootstrap/cache/    775
```

and still fail when the deployment/Scheduler user and PHP-FPM/Horizon user have different primary groups.

Typical symptom:

```text
deploy user creates file
-> www-data cannot update it

www-data creates file
-> deploy user cannot update it
```

Directory mode alone is not proof that future files inherit a usable shared-write policy.

## Required protection

Identify every process identity that writes runtime files:

```text
deployment / Scheduler user
PHP-FPM user
Supervisor/Horizon user
```

Test both create/update directions with disposable files in the actual runtime tree.

When multiple identities must write the same tree, use a deliberate inheritance mechanism such as:

```text
shared group + setgid/inherited group ownership
POSIX access + default ACLs
another explicitly administered equivalent
```

When POSIX ACLs are used, grant access to existing files/directories and set default ACLs on directories so new files inherit the same writable identities.

Do not solve this with recurring broad `chmod 777`, blanket ownership changes, or assumptions based only on the parent directory mode.

---

# 5B. Server build memory pressure

Composer, npm, and Vite can create short-lived memory spikes even when normal PHP request/worker memory is healthy.

Before an in-place server build on a small host, inspect:

```bash
free -h
swapon --show
df -h /
```

Swap is not an Engage Core runtime requirement, but a host with limited RAM and no swap may be unnecessarily vulnerable to OOM kills or stalled deployment builds. Add an appropriately sized persistent swapfile when the actual server capacity/workload warrants it.

Do not copy a fixed swap size from another client without checking the host.

---

# 6. Placeholder domains and stale config cache

## Failure mode

Values such as:

```env
APP_URL=https://DOMAIN
ROOT_DOMAIN=DOMAIN.com
WEBINAR_APP_URL=https://webinar.DOMAIN
CRM_APP_URL=https://crm.DOMAIN
```

can produce wrong route hosts and 404s.

After changing environment values:

```bash
php artisan optimize:clear
php artisan route:list
```

Verify actual hosts for:

```text
root/public
CRM
webinar
webhooks
```

The current application derives the webhooks host from `ROOT_DOMAIN`; `WEBHOOKS_APP_URL` is not the active app environment contract. Operators must still verify `webhooks.<root domain>` for DNS, Nginx, SSL, route registration, and provider webhook configuration.

Also verify Nginx points every hostname at the intended new checkout, not only the root domain.

---

# 7. Setup validation failures

Run:

```bash
php artisan setup:validate
```

Interpretation:

```text
errors
    block staging/client handoff

warnings
    non-blocking only when understood and intentionally accepted

clean
    proceed
```

Do not assume every validation failure means client config is wrong. A validator itself can drift from runtime truth.

A first-production-run example exposed duplicated module authority between preset-package declarations and runtime module configuration. Preset packages no longer declare modules. The selected client's `config/modules.php` is now the sole runtime authority, while preset packages select definition groups only.

Use the project authority order:

```text
schema
runtime behavior/DTOs/actions/resolvers
registered contracts
validation/tests
default/client config
docs/templates
```

Fix the wrong layer.

---

# 8. Resend failures

Check:

```text
MAIL_MAILER=resend
EMAIL_PROVIDER=resend
RESEND_API_KEY
verified sender domain
transactional sender identity
marketing sender identity
optional Resend-specific override variables
webhook secret/signature when delivery events are used
```

Important fallback rule:

```text
blank optional RESEND_FROM_* values can defeat fallback sender configuration
```

Do not populate optional override variables with empty assignments merely for symmetry.

For the canonical Resend transport, SMTP variables such as `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, and `MAIL_PASSWORD` are not required.

---

# 9. Telnyx failures and hidden SMS

A valid API key does not guarantee SMS is available.

Check:

```text
SMS_ENABLED
SMS_PROVIDER
Messaging channel availability
effective provider_enabled value for the intended surface/purpose/scope
surface visibility
purpose/scope eligibility
recipient phone
active consent
suppression/STOP state
purpose-specific sender number
messaging profile ID when required
```

Inbound debugging:

```text
Telnyx webhook reaches correct environment
TELNYX_WEBHOOK_PUBLIC_KEY correct
signature accepted
message.received event normalized
STOP/HELP handled deterministically
normal reply routed only when appropriate
```

Do not expose SMS to client/admin UI solely because code and provider credentials exist.

---

# 10. Zoom scopes: basic webinar access is not attendance access

A common failure is having enough Zoom scope to read webinars/register users but not enough scope to retrieve past participant reports.

The deployment that informed this guidance required attendance-report capability equivalent to:

```text
report:read:list_webinar_participants:admin
```

The application must be able to call the Zoom webinar-participant report endpoint used by the current provider implementation.

Also verify capabilities equivalent to registration, webinar lookup, and recording access when those features are used.

After changing Zoom scopes:

```text
confirm app activation
confirm new scopes are effective
retest OAuth token
retest exact API call
```

Do not stop at successful authentication. The first production run had enough Zoom access for basic Webinar operations while attendance reconciliation and recording resolution still failed until the missing participant-report and recording capabilities were added.

---

# 11. Zoom webhook and post-event debugging

Current Core normalization includes:

```text
webinar.ended -> webinar.ended
webinar.completed -> webinar.ended
recording.completed -> webinar.recording_completed
```

Current post-event action chain:

```text
webinar.ended
    → RecordWebinarProviderAttendanceAction

webinar.recording_completed
    → ResolveWebinarPlaybackAction
    → DispatchPostWebinarFollowUpsAction
```

Therefore this expectation is wrong:

```text
webinar ended
→ replay follow-up immediately sends even though no recording/playback exists
```

Current outcome-message conditions require a filled playback URL.

Debug order:

```text
1. Did provider webhook reach the correct host?
2. Was signature accepted?
3. Was event normalized to expected event key?
4. Was webhook job dispatched to a consumed queue?
5. Did attendance retrieval succeed?
6. Did recording.completed arrive?
7. Did playback resolution succeed?
8. Is webinar.playback_url filled?
9. Did outcome-message condition pass?
10. Did Messaging schedule/send or skip with a recorded reason?
```

Before a live Webinar, verify real webhook delivery end to end. A configured route and provider subscription are not enough. A missed `webinar.ended` delivery can force manual recovery through the real post-event job path and makes attendance/follow-up sequencing harder to reason about under pressure.

---

# 12. Duplicate registrations can create conflicting outcomes

Before a live webinar or legacy import, inspect for duplicate registrations for the same person/webinar.

Potential conflict:

```text
registration A marked attended
registration B marked missed
→ duplicate automation events
→ conflicting statuses/routes/campaigns
```

Use stable identity rules. Do not globally auto-merge contacts solely on phone number without a broader identity-resolution design.

Verify:

```text
contact identity
webinar identity
registration uniqueness policy
provider registrant IDs
email/phone normalization
legacy duplicates
```

---

# 13. Join-link scanners and prefetchers

Do not treat every GET request to a personalized join redirect as guaranteed human intent.

Email security scanners and link prefetchers can follow URLs automatically.

Risk:

```text
GET personalized join URL
→ mark join_clicked_at
→ skip live reminder
```

A scanner can suppress a reminder without the human actually clicking.

Safer future pattern:

```text
GET personalized join URL
→ render lightweight joining page
→ browser-side confirmation/brief delay
→ signed POST
→ record stronger click evidence
→ redirect to provider
```

Preserve raw resolution signals separately from stronger human-interaction evidence where practical.

Until architecture changes, remember this limitation during production debugging.

---

# 14. Existing scheduled messages do not automatically change when config changes

Preset/template/config changes affect future resolution/scheduling unless an explicit rescheduling/rebuild path exists.

Do not assume:

```text
edit client message config
run presets:sync
→ already scheduled message payloads rewrite themselves
```

Before editing production copy or CTAs, inspect whether affected messages are already represented by persisted `scheduled_messages` rows and whether payloads were materialized at scheduling time.

Operational rule:

```text
Fix future definition state and separately decide what to do with already scheduled instances.
```

---

# 15. Safe surgical recovery for terminal scheduled messages

A terminal ScheduledMessage is immutable delivery history for operational recovery purposes.

Its durable result is owned by:

```text
ScheduledMessage.status
ScheduledMessageOutboxEvent
ScheduledMessageDeliveryAttempt, when an attempt existed
```

Do not reset a sent, skipped, or failed ScheduledMessage row to pending. The terminal outbox is one-row-per-message, and the original attempt/outbox evidence must remain intact.

Safe recovery principle:

```text
1. Identify the exact affected ScheduledMessage IDs and owning domain records.
2. Confirm the code/config/provider cause is fixed.
3. Inspect the durable terminal result, including outbox occurrence and attempt/reason.
4. Use the owning module's explicit retry/reissue/reschedule path when one exists.
5. Otherwise create a new logical delivery occurrence with a new dedupe/idempotency identity.
6. Preserve the original ScheduledMessage, delivery attempts, and outbox event unchanged.
7. Dispatch only the newly created replacement deliveries.
8. Verify both the preserved original result and the replacement result.
```

Do not perform broad status resets, delete terminal outbox rows, reuse provider idempotency keys, indiscriminately retry jobs, flush queues, or destroy Redis for a small identified failure set.

Before creating replacement deliveries after a PHP runtime fix:

```text
deploy the fix
→ restart <CLIENT_HORIZON_PROGRAM> through Supervisor when worker code changed
→ verify the running Horizon process
→ invoke only the owning module's exact recovery/reissue path
```

The production correction should be narrower than the incident whenever possible.

# 16. Diagnostic command set

## Environment/client

```bash
php artisan tinker --execute="dump([
    'env' => app()->environment(),
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

## Redis/Horizon

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

## Routes

```bash
php artisan route:list
php artisan route:list | grep -i webhook
php artisan route:list | grep -i zoom
```

## Running worker

```bash
ps aux | grep "[a]rtisan horizon"
```

## Deployment and validation

```bash
php artisan modules:status
php artisan presets:sync
php artisan setup:validate
```

For the exact new-client, existing-client, module-addition, and controlled-rebuild command sequences, use `docs/operations/deployment-command-workflow.md`.

---

# 17. Incident-prevention gate

Before launch or a live Webinar event:

```text
[ ] Correct checkout served by Nginx
[ ] Correct checkout consumed by Horizon
[ ] Actual Horizon process path verified
[ ] Explicit queue list covers executable queues
[ ] Laravel Scheduler cron exists exactly once for this client and `schedule:list` is understood
[ ] Runtime writable directories pass cross-user create/update checks for the actual process identities
[ ] Build host has adequate memory/disk/swap posture for the chosen deployment strategy
[ ] Redis prefixes understood
[ ] No stale jobs after disposable-data resets or a controlled Project State rebuild
[ ] Root production logging resolves to the intended logging stack
[ ] Production observability verifier passes when the Engage Core observability path is installed
[ ] Project State export/import/resume record preserved when a controlled rebuild was performed
[ ] No pending Project State resume items remain unless deliberately reconciled
[ ] No placeholder domains
[ ] Config cache cleared after env changes
[ ] setup:validate passes
[ ] Resend sender/API/webhook verified when enabled
[ ] Telnyx number/profile/webhook verified when enabled
[ ] Zoom registration scopes verified when enabled
[ ] Zoom recording scopes verified when replay used
[ ] Zoom attendance-report scope verified
[ ] Zoom webhook subscriptions verified
[ ] Duplicate registration conflicts checked
[ ] Post-event recording/playback dependency understood
[ ] Already scheduled messages reviewed before copy/CTA changes
```