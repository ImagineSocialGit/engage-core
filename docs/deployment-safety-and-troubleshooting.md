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
Once real data matters, do not use destructive database resets as a deployment technique.
```

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

`ScheduleMessageAction` normalizes timezone-aware Carbon values to UTC before persistence and queue delay registration. `ScheduledMessage.send_at`, the queued delay, and Horizon `send_at` metadata therefore represent the same instant. The message's `meta.message_scheduling` payload retains the source timezone and both source and UTC ISO-8601 values for diagnosis. A discrepancy in older data is a code/data consistency issue; it is not, by itself, proof that the queued delay is wrong.

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

# 15. Safe surgical recovery for skipped scheduled messages

For a narrow production failure, recover only the exact affected ScheduledMessage instances after the code/config/runtime cause is fixed.

Safe recovery principle:

```text
1. Identify the exact affected scheduled-message IDs.
2. Filter by channel.
3. Filter by current status.
4. Filter by the exact skip or failure reason.
5. Reset only those rows to pending.
6. Clear skipped_at, skip_reason, and stale failure state only as appropriate for those rows.
7. Dispatch only those specific SendScheduledMessageJob instances.
8. Verify final statuses.
```

Do not perform broad status resets, indiscriminate retries, queue flushes, or Redis destruction for a small, identified failure set.

Before retrying jobs after a PHP runtime fix:

```text
deploy the fix
→ restart <CLIENT_HORIZON_PROGRAM> through Supervisor
→ verify the running Horizon process
→ retry only the exact affected messages
```

The production correction should be narrower than the incident whenever possible.

---

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

## Validation

```bash
php artisan presets:sync
php artisan setup:validate
```

---

# 17. Incident-prevention gate

Before launch or a live Webinar event:

```text
[ ] Correct checkout served by Nginx
[ ] Correct checkout consumed by Horizon
[ ] Actual Horizon process path verified
[ ] Explicit queue list covers executable queues
[ ] Redis prefixes understood
[ ] No stale jobs after disposable-data resets
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