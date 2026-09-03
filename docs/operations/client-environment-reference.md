# Engage Core — Client Environment Reference

## Purpose

This document defines the current environment-variable expectations for the supported Engage Core deployment path represented by the supplied Core configuration:

```text
Laravel / PHP
MySQL 8
Redis cache + sessions + queues
Horizon + Supervisor
DigitalOcean Spaces
Resend
Telnyx
Zoom Webinars
```

The accompanying `.env.example` is intentionally curated for that current stack. It does not enumerate every alternate Laravel backend variable for SQLite, PostgreSQL, SQL Server, SQS, Beanstalkd, Memcached, DynamoDB, SES, Postmark, SMTP, or other unused transports.

## Important rule about blank optional overrides

Do not add a blank environment assignment for an optional variable merely to make the file look complete.

Example:

```env
RESEND_FROM_EMAIL_TRANSACTIONAL=
```

can override a configured fallback with an empty value.

Prefer:

```env
# RESEND_FROM_EMAIL_TRANSACTIONAL=transactional@example.com
```

until an override is intentionally needed.

The updated `.env.example` follows this rule for fallback-based variables.

## Environment-file filesystem permissions

The supported server deployment path expects both the deployment/Artisan user and PHP-FPM to read the root and selected-client environment files. Protect secrets without making them unreadable to the web runtime.

For the common deployment shape where the checkout is owned by `<DEPLOY_USER>` and PHP-FPM runs in `<WEB_GROUP>` (commonly `www-data`), use:

```bash
sudo chown <DEPLOY_USER>:<WEB_GROUP> .env client/<CLIENT_KEY>/.env
sudo chmod 640 .env client/<CLIENT_KEY>/.env
```

Verify both identities can read the files without printing their contents:

```bash
test -r .env && test -r client/<CLIENT_KEY>/.env
sudo -u <WEB_USER> test -r .env
sudo -u <WEB_USER> test -r client/<CLIENT_KEY>/.env
```

Do not default these files to `0600` when PHP-FPM runs as another user and reads the files directly. That produces a split-brain failure where CLI/Artisan sees the intended environment while web requests fall back to missing/default environment values; symptoms can include `MissingAppKeyException`, unexpected `production` logging context, or host-specific routes returning 404 even though `route:list` is correct from the CLI.

`0600` is valid only when the web runtime does not need filesystem read access to these files or runs as the same owning identity.

---

# 1. Application identity and environment

Root/process application variables:

```env
APP_NAME="EngageCore"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
```

`APP_URL` is selected-client deployment state and belongs in `client/[CLIENT_KEY]/.env`; do not duplicate it in root `.env`, because root/process values are loaded before the selected-client environment and can defeat the intended client override.

Staging:

```env
APP_ENV=staging
APP_DEBUG=false
```

Production:

```env
APP_ENV=production
APP_DEBUG=false
```

`APP_KEY` must be unique per environment and preserved after encrypted data exists.

`APP_PREVIOUS_KEYS` is available for deliberate key-rotation compatibility.

---

# 2. Client selection, client config, modules, and timezone

Root `.env` has one client-selection key:

```env
CLIENT_KEY=
```

Meaning:

```text
CLIENT_KEY
    selects client/[CLIENT_KEY]
```

The selected client then contributes:

```text
client/[CLIENT_KEY]/.env
    client deployment/runtime values accepted by ClientEnvironmentLoader

client/[CLIENT_KEY]/config/client.php
    client identity, selected preset, stable client timezone

client/[CLIENT_KEY]/config/modules.php
    explicitly enabled runtime product modules

client/[CLIENT_KEY]/config/**
    version-controlled product/business behavior and client overrides
```

`CLIENT_PRESET`, `ENABLED_MODULES`, and `CLIENT_TIMEZONE` are not part of the canonical environment contract.

The runtime module source of truth is:

```text
client/[CLIENT_KEY]/config/modules.php
    -> config('modules.enabled')
    -> ModuleManager
    -> enabled module providers and runtime availability
```

Preset packages do not declare module availability or module requirements. They select contributed definition groups only. Runtime module authority belongs exclusively to the selected client's `config/modules.php`.

Core keeps a generic default enabled-module list for the no-client/default application state and test fallback. A selected client's `config/modules.php` replaces that list.

Client timezone is stable client config:

```php
client/[CLIENT_KEY]/config/client.php

'timezone' => 'America/Denver',
```

The selected-client `.env` is a strict allowlist, not an arbitrary second Laravel environment file. `ClientEnvironmentLoader` rejects root-owned or unsupported keys. Keep process-wide values in root `.env` even when they are related to a client-facing provider. In particular:

```text
FILESYSTEM_DISK
RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS
```

remain root/process-owned, while client-varying storage credentials, bucket identity, sender identities, provider API keys, webhook secrets, and Forms external-client credentials remain selected-client-owned.

When a fresh bootstrap fails before Laravel finishes registering configuration, an early client-environment contract exception may be obscured by a secondary `Target class [config] does not exist` reporting failure. Use the troubleshooting runbook's direct `ClientEnvironmentLoader` diagnostic before changing application code.

---

# 3. URLs and host topology

The selected client `.env` owns:

```env
ROOT_DOMAIN=
APP_URL=
WEBINAR_APP_URL=
CRM_APP_URL=
```

`bootstrap/app.php` derives the webhooks route host from `ROOT_DOMAIN`:

```text
webhooks.[ROOT_DOMAIN]
```

`WEBHOOKS_APP_URL` is not part of the active application environment contract.

Typical deployment roles:

```text
[ROOT_DOMAIN]
    standard site domain; it may be served by a separate Engage site application

<CORE_ADMIN_SUBDOMAIN>.[ROOT_DOMAIN]
    human-facing Engage Core administration; commonly crm or app

webinar.[ROOT_DOMAIN]
    public Engage Core Webinar host when Webinars is enabled

webhooks.[ROOT_DOMAIN]
    public Engage Core webhook and signed server-to-server integration host
```

The Engage Core admin subdomain is deployment-owned. `CRM_APP_URL` remains the canonical environment key even when the selected hostname uses `app` rather than `crm`. Only Core-owned hostnames should route to the Engage Core `public/` directory; the standard site domain must route to its owning application.

## External Forms server-to-server access

When Engage Sites or another approved first-party server is allowed to read and submit a Core-backed form, the selected client `.env` owns the calling identity and credential values:

```env
FORMS_EXTERNAL_INTAKE_ENABLED=true
FORMS_EXTERNAL_INTAKE_CLIENT_ID=engage_sites
FORMS_EXTERNAL_INTAKE_CLIENT_SECRET=
FORMS_EXTERNAL_INTAKE_SOURCE=engage_sites
FORMS_EXTERNAL_INTAKE_PROVIDER=engage_sites
FORMS_EXTERNAL_INTAKE_ALLOWED_FORMS=artist_updates
FORMS_EXTERNAL_INTAKE_DOMAINS=example.com,forms.example.com
```

These values configure the signed application-to-application boundary used by both:

```text
GET  https://webhooks.[ROOT_DOMAIN]/forms/{form_key}
POST https://webhooks.[ROOT_DOMAIN]/forms/{form_key}/submissions
```

`FORMS_EXTERNAL_INTAKE_CLIENT_SECRET` is server-only and must not be rendered into browser HTML or JavaScript. The client ID identifies the authenticated caller. `SOURCE` and `PROVIDER` are server-owned attribution/idempotency values, and `ALLOWED_FORMS` is an exact comma-separated allowlist of form keys.

The root/process environment continues to own the shared signed-request and abuse controls documented in `.env.example`:

```text
FORMS_EXTERNAL_INTAKE_MAX_BODY_BYTES
FORMS_EXTERNAL_INTAKE_MAX_TIMESTAMP_DRIFT_SECONDS
FORMS_EXTERNAL_INTAKE_NONCE_TTL_SECONDS
FORMS_EXTERNAL_INTAKE_UNAUTHENTICATED_RATE_LIMIT_PER_MINUTE
FORMS_EXTERNAL_INTAKE_CLIENT_RATE_LIMIT_PER_MINUTE
```

Do not put those process-wide controls into every selected-client `.env` merely for symmetry.

For the reusable Artist Sites intake, environment allowlisting is only one part of readiness. The selected Core client must also:

```text
- enable Forms in client config/modules.php;
- explicitly select the Forms preset group `artist_updates` in the selected package;
- when Messaging-backed marketing consent is used, confirm the intended channel +
  purpose permissions are represented by the form's accepted consent fields; optional
  consent-domain mappings may be configured only when custom acknowledgement/topic
  grouping is desired;
- sync the selected preset so a public current `artist_updates` FormVersion exists.
```

The reusable Core preset begins with `settings.submission.verification.required=false` while still validating any supplied Turnstile evidence against provider `turnstile`, action `artist_updates`, hostname presence, and a 300-second age limit. This is a rollout state, not the desired permanent public posture.

After the matching Artist Sites sender is proven end to end, use a selected-client config override at:

```text
client/{client-key}/config/presets/modules/forms/forms.php
```

to set only:

```php
'definitions' => [
    'artist_updates' => [
        'settings' => [
            'submission' => [
                'verification' => [
                    'required' => true,
                ],
            ],
        ],
    ],
],
```

Then run `php artisan presets:sync` and `php artisan setup:validate`. The sync publishes a new immutable FormVersion; it does not mutate the previously published version.

The Artist Sites read-only probe may be run before destination cutover. Do not treat a successful GET probe as permission to switch live POST traffic until the Sites sender also matches the required submission fields, especially `email_marketing_consent` and any enabled SMS-consent field.

---

# 4. Application locale and timezone

Core reads:

```env
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US
```

Laravel application/runtime storage remains UTC:

```text
config/app.php
    timezone = UTC
```

The selected client's presentation/business timezone comes from:

```text
client/[CLIENT_KEY]/config/client.php
    -> config('client.timezone')
```

Do not duplicate the client timezone in root or client `.env`.

---

# 5. Maintenance and previous keys

Current Core reads:

```env
APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=database
APP_PREVIOUS_KEYS=
```

`APP_MAINTENANCE_STORE` matters when a maintenance driver uses a store; it is harmless to document with the current file-driver default.

---

# 6. Staging access and initial user bootstrap

Staging access:

```env
STAGING_USER=
STAGING_PASSWORD=
```

CRM application users are not environment configuration.

Create the first CRM user, and later users, with:

```bash
php artisan engage:user:add
```

Reset a forgotten CRM password with:

```bash
php artisan engage:user:password user@example.com
```

Passwords are entered through hidden terminal prompts and must not be retained in `.env`.

Keep the existing STAGING_USER / STAGING_PASSWORD and PROJECT_STATE_ADMIN_EMAIL documentation. Those values serve separate access/authorization purposes.

See `operations/crm-user-administration.md` for the complete user-administration contract.

Project State owner authorization:

```env
PROJECT_STATE_ADMIN_EMAIL=
```

These are separate concepts.

```text
STAGING_USER / STAGING_PASSWORD
    environment access gate

PROJECT_STATE_ADMIN_EMAIL
    exact CRM user email allowed to open and operate the owner-only Project State surface
```

`PROJECT_STATE_ADMIN_EMAIL` is a selected-client environment value. A blank value intentionally disables Project State access because no authenticated user can match it. The configured user must still provide the current password for export, apply, and resume operations.

Remove or rotate bootstrap secrets after use according to operational policy. Keep the Project State owner email deliberately configured only for operators who should retain that maintenance authority.

---

# 7. Logging

Logging is a root/process environment concern.

Canonical current-stack variables:

```env
LOG_CHANNEL=stack
LOG_STACK=daily_json
LOG_DEPRECATIONS_CHANNEL=null
LOG_DEPRECATIONS_TRACE=false
LOG_LEVEL=info
LOG_DAILY_DAYS=14
```

`config/logging.php` defaults to the `stack` channel, but the stack's default child is `single` when `LOG_STACK` is absent. Production deployments that use the Engage Core observability path must therefore set `LOG_STACK=daily_json` explicitly rather than assuming `LOG_CHANNEL=stack` alone enables structured daily JSON logging.

Keep these values in the root `.env`, not `client/[CLIENT_KEY]/.env`.

Verify the effective production logging contract after the current code and environment are in place:

```bash
php artisan tinker --execute="dump([
    'default' => config('logging.default'),
    'stack_channels' => config('logging.channels.stack.channels'),
    'daily_json_level' => config('logging.channels.daily_json.level'),
    'daily_json_days' => config('logging.channels.daily_json.days'),
    'daily_json_path' => config('logging.channels.daily_json.path'),
]);"
```

Expected production shape:

```text
default = stack
stack channels = [daily_json]
daily_json level = info
daily_json days = 14
```

Use `docs/operations/observability/README.md` for the Nginx request-ID/JSON access log, PHP-FPM slow-log, Horizon rotation, verification, and rollback procedure.

The Core logging config also supports Slack, Papertrail, stderr, syslog, and other optional handlers. Those variables are omitted from the canonical current-stack `.env.example` until a client deployment actually uses them.

---

# 8. MySQL

Root `.env` owns the connection type and machine/network location. The selected client `.env` owns database identity and credentials.

Canonical current-stack variables:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
```

Optional advanced MySQL variables supported by config include:

```text
DB_URL
DB_SOCKET
DB_CHARSET
DB_COLLATION
MYSQL_ATTR_SSL_CA
```

Do not populate them unless the actual deployment requires them.

---

# 9. Cache

Root `.env` owns the cache backend and operational TTLs. The selected `[CLIENT]` environment owns `CACHE_PREFIX`.

Current deployment path:

```env
CACHE_STORE=redis
CACHE_PREFIX=[CLIENT_KEY]_cache_
```

Webinar registration and waitlist pages are rendered for every request. Do not add a response-cache toggle, a webinar landing-page TTL, or a custom public-page configuration TTL. Session state, CSRF tokens, validation errors, old input, and waitlist-prefill data must remain request-specific.

Supported cache TTL overrides:

```env
CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS=300
CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS=60
CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS=300
CACHE_EXTERNAL_API_RESPONSE_SECONDS=300
CACHE_IMAGE_MANIFEST_SECONDS=3600
```

The Webinar module may cache identifiers for active series and registerable webinar occurrences. It does not cache final HTML. `WebinarRegisterPageConfig` reads merged Laravel configuration and does not maintain a separate public-page configuration cache.

The prefix must be unique when Redis/cache infrastructure is shared.

---

# 10. Sessions

Root `.env` owns session driver/security behavior. The selected client `.env` owns `SESSION_DOMAIN` when it varies by client domain.

Canonical current stack:

```env
SESSION_DRIVER=redis
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=
```

Useful security overrides supported by current config:

```text
SESSION_SECURE_COOKIE
SESSION_HTTP_ONLY
SESSION_SAME_SITE
SESSION_EXPIRE_ON_CLOSE
SESSION_COOKIE
SESSION_CONNECTION
SESSION_STORE
```

The curated example keeps secure-cookie behavior commented because local HTTP development and HTTPS staging/production have different needs. Staging and production should deliberately enable secure cookies when served over HTTPS.

---

# 11. Queues

Canonical current stack:

```env
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
```

Module-specific queue overrides:

```env
CONTACT_INGESTION_QUEUE=default
CONTACT_ENRICHMENT_QUEUE=default
SMS_QUEUE=sms
WEBINAR_REGISTRATION_QUEUE=webinars
WEBINAR_WEBHOOK_QUEUE=webhooks
WEBINAR_REMINDER_QUEUE=notifications
WEBINAR_CONFIRMATION_MESSAGE_QUEUE=notifications
WEBINAR_FOLLOWUP_QUEUE=notifications
```

## Current executable queue inventory

Current runtime/configuration paths use:

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

Current queue notes:

```text
emails
    active generic email/Broadcast queue path

notifications
    current Webinar waitlist delivery queue; no separate canonical waitlist queue is required

marketing
    current Campaign/Webinar nurture queue; do not preserve the obsolete campaigns queue assumption
```

### Horizon default coverage

`config/horizon.php` currently defaults to:

```text
default,notifications,confirmation_messages,reminders,opt_in_messages,post_event,marketing
```

That built-in default does not cover every executable queue path above. Until the Core Horizon defaults are reconciled, set `HORIZON_SUPERVISOR_1_QUEUES` explicitly for deployments:

```env
HORIZON_SUPERVISOR_1_QUEUES=default,notifications,confirmation_messages,opt_in_messages,reminders,post_event,marketing,emails,sms,webinars,webhooks
```

Verify the effective queue list after configuration changes. Do not copy historical `campaigns` or `waitlist` queue names into a deployment unless current executable code reintroduces them deliberately.

---

# 12. Redis

Root `.env` owns the Redis client, host, port, password, and DB indexes. The selected client `.env` owns `REDIS_PREFIX`.

Canonical current stack:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=CHANGE_ME_
```

`REDIS_PASSWORD=null` means no Redis password is configured. Do not pass the literal string `null` to `redis-cli` through `REDISCLI_AUTH`; omit authentication when the server itself has no Redis password configured.

Optional supported variables include:

```text
REDIS_URL
REDIS_USERNAME
REDIS_CLUSTER
REDIS_PERSISTENT
REDIS_MAX_RETRIES
REDIS_BACKOFF_ALGORITHM
REDIS_BACKOFF_BASE
REDIS_BACKOFF_CAP
REDIS_QUEUE_CONNECTION
REDIS_QUEUE
REDIS_QUEUE_RETRY_AFTER
REDIS_CACHE_CONNECTION
REDIS_CACHE_LOCK_CONNECTION
```

Use prefixes and/or DB isolation deliberately. Never assume an unprefixed raw Redis key is the queue used by the app.

---

# 13. DigitalOcean Spaces

Disk/backend selection is root/process-owned; client-varying Spaces identity, credentials, bucket, and CDN values belong in `client/[CLIENT_KEY]/.env`.

These values become deployment-blocking when an enabled runtime capability needs writable public object storage. The Media module is the first such capability. Static assets uploaded during development do not by themselves make the Media runtime contract active.

Root/process selection:

```env
FILESYSTEM_DISK=spaces
```

Selected-client storage identity and credentials:

```env
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_REGION=nyc3
DO_SPACES_BUCKET=
CDN_BASE_URL=
```

`config/filesystems.php` still has a legacy fallback from `CDN_BASE_URL` to the Spaces endpoint for generic filesystem URL generation. When Media is enabled in staging/production, `CDN_BASE_URL` is explicitly required so reusable outbound assets have a stable public delivery origin rather than relying on that fallback.

---

# 14. Email and Resend
# 14. Email and Resend

Provider selection, credentials, webhook secrets, sender identities, and inbound receiving-domain identity are selected-client deployment values.

Canonical provider path:

```env
MAIL_MAILER=resend
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="${APP_NAME}"
EMAIL_PROVIDER=resend
```

Messaging sender identities:

```env
FROM_EMAIL_TRANSACTIONAL=
FROM_NAME_TRANSACTIONAL=
FROM_EMAIL_MARKETING=
FROM_NAME_MARKETING=
```

Selected-client Resend credentials, webhook secrets, and optional inbound receiving domain:

```env
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
INBOUND_EMAIL_DOMAIN=
```

Recommended role split when inbound replies are enabled:

```text
email.<ROOT_DOMAIN>
    Resend sending domain

replies.<ROOT_DOMAIN>
    Resend receiving domain
    value used by INBOUND_EMAIL_DOMAIN

webhooks.<ROOT_DOMAIN>
    Engage Core webhook host
```

The standard sending domain is a deployment recommendation, not a requirement to use that literal subdomain. The visible From address must belong to the domain actually verified for sending. A display name may provide the human-facing sender identity independently from the subdomain used in the address.

The current Resend credential contract is:

```text
outbound email only
    Sending Access may be sufficient

Inbound Messaging email receiving enabled
    Full Access is required
```

Inbound `email.received` webhooks contain receiving metadata rather than the complete email body. Engage Core uses `RESEND_API_KEY` to retrieve the received email by `email_id` from Resend's Received Emails API. The runtime currently has one Resend API-key setting for both operations.

Each Resend webhook registration may have its own Svix signing secret. `RESEND_WEBHOOK_SECRET` accepts one or more active trusted secrets separated by commas, semicolons, or whitespace. This supports the canonical split between the Messaging delivery/lifecycle endpoint and the Inbound Messaging `email.received` endpoint without adding another environment variable.

`INBOUND_EMAIL_DOMAIN` is required only when the selected deployment uses Resend Receiving / Inbound Messaging email. It should contain a bare domain such as:

```env
INBOUND_EMAIL_DOMAIN=replies.example.com
```

Do not include a scheme, mailbox/local part, or `@`.

Root/process webhook verification tolerance:

```env
RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS=300
```

Optional provider-specific sender overrides:

```text
RESEND_FROM_EMAIL_TRANSACTIONAL
RESEND_FROM_NAME_TRANSACTIONAL
RESEND_FROM_EMAIL_MARKETING
RESEND_FROM_NAME_MARKETING
```

Fallback chain:

```text
Messaging FROM_* value
    -> MAIL_FROM_* fallback

Resend-specific override
    -> Messaging FROM_* fallback
    -> MAIL_FROM_* fallback
```

Do not set the Resend-specific override keys to blank values unless blank is truly intended.

Keep provider-dashboard Open Tracking and Click Tracking disabled while Engage Core owns CTA engagement tracking through Messaging `tracking_key` redirects. Provider-level tracking is an operational setting rather than an environment variable.

### SMTP variables removed from the canonical Resend example

The prior env example contained:

```text
MAIL_HOST
MAIL_PORT
MAIL_SCHEME
MAIL_USERNAME
MAIL_PASSWORD
```

Those are not required for `MAIL_MAILER=resend`. Keep them out of the canonical Resend deployment example unless an SMTP transport is intentionally selected.

### Notification sender variables

Current Core uses:

```text
INTERNAL_NOTIFICATION_FROM_ADDRESS
INTERNAL_NOTIFICATION_FROM_NAME
```

The older names below are not consumed by the supplied Core config:

```text
FROM_EMAIL_NOTIFICATIONS
FROM_EMAIL_NOTIFICATIONS_NAME
```

The updated example uses the current names only.

---

# 15. Permission invitations

PERMISSION_INVITATION_PUBLIC_URL is an optional selected-client deployment override for the public base URL used by permission-invitation links.

When absent, the config falls back to APP_URL.

Set it only when permission-invitation public links should use a different base URL from the application's normal APP_URL.

This is a distinct Messaging-owned one-time permission flow. It is not a normal Broadcast consent bypass.

---

# 16. Internal notifications and inbound replies

These are selected-client deployment values.

Optional current variables:

```text
INTERNAL_NOTIFICATION_FROM_ADDRESS
INTERNAL_NOTIFICATION_FROM_NAME
TELNYX_FROM_NOTIFICATIONS
INBOUND_REPLY_DEFAULT_TEAM_MEMBER_EMAIL
```

Only set them when the corresponding feature path is enabled and needs an override.

---

# 17. SMS and Telnyx

Provider enablement, selection, credentials, sender numbers, webhook keys, and profile IDs are selected-client deployment values. Rate limits and queue tuning remain root/process-owned.

Current global SMS toggle/provider:

```env
SMS_ENABLED=false
SMS_PROVIDER=telnyx
```

Purpose-specific sender numbers:

```env
TELNYX_FROM_TRANSACTIONAL=
TELNYX_FROM_MARKETING=
TELNYX_FROM_NOTIFICATIONS=
```

Credentials/signature:

```env
TELNYX_API_KEY=
TELNYX_WEBHOOK_PUBLIC_KEY=
```

Optional profile IDs:

```env
MESSAGING_SMS_MARKETING_PROFILE_ID=
MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID=
```

Current SMS operational controls:

```env
SMS_QUEUE=sms
SMS_RATE_LIMIT_PER_IP_PER_HOUR=5
SMS_RATE_LIMIT_PER_PHONE_PER_DAY=10
SMS_DUPLICATE_WINDOW_MINUTES=15
SMS_DAILY_ALERT_THRESHOLD=500
SMS_DAILY_HARD_LIMIT=2000
```

Optional fallback sender variables supported by current config:

```text
SMS_FROM
SMS_FROM_TRANSACTIONAL
SMS_FROM_MARKETING
TELNYX_FROM
```

Do not populate all fallback layers by default. Prefer one clear canonical sender strategy.

---

# 18. Twilio

Current Core still supports Twilio config variables:

```text
TWILIO_SID
TWILIO_AUTH_TOKEN
TWILIO_FROM
TWILIO_FROM_TRANSACTIONAL
TWILIO_FROM_MARKETING
TWILIO_VIRTUAL_PHONE
```

They are commented out in the curated env example because Telnyx is the current primary path.

---

# 19. Webinars and Zoom

Runtime Webinar module availability comes from the selected client's module config:

```text
client/[CLIENT_KEY]/config/modules.php
    -> config('modules.enabled')
    -> ModuleManager
```

`WEBINARS_ENABLED` is not part of the canonical runtime contract.

The provider family is a selected-client deployment value:

```env
WEBINAR_PROVIDER=zoom
```

Webinar versus Meeting is not selected through an environment variable. The default
provider event type remains root config, while each `WebinarSeries` stores the operator's
current event-type selection and each synchronized occurrence preserves its own type.
Changing a series type affects future synchronization only.

Zoom Server-to-Server OAuth credentials and webhook verification values are
selected-client deployment values:

```env
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_WEBHOOK_SECRET=
```

Provider operational defaults remain root-owned:

```env
ZOOM_BASE_URL=https://api.zoom.us/v2
ZOOM_OAUTH_URL=https://zoom.us/oauth/token
ZOOM_OAUTH_TOKEN_TTL_SECONDS=3500
ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS=300
WEBHOOK_INBOX_CLAIM_LEASE_SECONDS=300
```

`ZOOM_BASE_URL` and `ZOOM_OAUTH_URL` must be absolute HTTPS URLs without embedded
credentials. The OAuth cache TTL must remain between 60 and 3600 seconds; the webhook
timestamp drift must remain between 1 and 3600 seconds. Current defaults are 3500 and
300 seconds respectively.

The Zoom Marketplace app must subscribe the environment-specific webhook endpoint to:

```text
webinar.ended
meeting.ended
recording.completed
```

The exact granular admin scopes required by current provider calls are documented in
`client-third-party-services-checklist.md`. Scope grants and account Reports-role
permissions cannot be proven by static environment validation; exercise the actual API
calls in staging.

`WEBHOOK_INBOX_CLAIM_LEASE_SECONDS` is provider-neutral and controls when interrupted
durable webhook work may be reclaimed for processing. It applies to Zoom and Resend
receipts; it is not a cache replay TTL.

---

# 20. Horizon

`HORIZON_PREFIX` is selected-client-owned. Worker/process tuning remains root-owned.

Current supported variables:

```env
HORIZON_PREFIX=
HORIZON_WAIT_THRESHOLD_DEFAULT=60
HORIZON_MASTER_MEMORY_LIMIT=64
HORIZON_MAX_PROCESSES=1
HORIZON_MEMORY=128
HORIZON_TRIES=1
HORIZON_TIMEOUT=60
HORIZON_PRODUCTION_MAX_PROCESSES=10
HORIZON_STAGING_MAX_PROCESSES=3
HORIZON_LOCAL_MAX_PROCESSES=3
HORIZON_BALANCE_MAX_SHIFT=1
HORIZON_BALANCE_COOLDOWN=3
HORIZON_SUPERVISOR_1_QUEUES=
```

Optional Horizon surface values also supported:

```text
HORIZON_NAME
HORIZON_DOMAIN
HORIZON_PATH
```

Use a unique `HORIZON_PREFIX` for every app/environment sharing Redis.

---

# 21. Keys intentionally omitted from the new canonical example

The following keys appeared in older env material but were not evidenced by the supplied current Core config files:

```text
ASSET_URL
BCRYPT_ROUNDS
BROADCAST_CONNECTION
VITE_APP_NAME
FROM_EMAIL_NOTIFICATIONS
FROM_EMAIL_NOTIFICATIONS_NAME
SMS_MANAGED_BY
WEBINAR_MANAGED_BY
ZOOM_OAUTH_TOKEN_CACHE_KEY
WEBINAR_TEST_SCHEDULING_ENABLED
WEBINAR_TEST_DELAY_STEP_SECONDS
WEBINAR_REMINDER_TESTING
```

The new example omits them rather than asserting they are current deployment contracts.

Important limitation:

```text
This conclusion is based on the supplied Core config dump.
A variable referenced directly from application code, routes, bootstrapping, package config, or deployment scripts would not be discovered by a config-only audit.
```

Before deleting an existing live environment variable, search the full repository for its exact name.

---

# 22. Recommended environment-specific isolation

| Concern | Local | Staging | Production |
| --- | --- | --- | --- |
| APP_ENV | local | staging | production |
| APP_DEBUG | true | false | false |
| APP_KEY | unique | unique | unique/preserved |
| Database | local/disposable | separate | separate/real |
| Redis prefix | unique | unique | unique |
| Cache prefix | unique | unique | unique |
| Horizon prefix | unique | unique | unique |
| URLs | local/dev | staging | production |
| Staging access credentials | optional | required when gate used | normally blank/not used |
| Email provider | log/test or real test | safe test | production |
| SMS provider | disabled/test | safe test | production |
| Webhooks | local tunnel/test | staging endpoint | production endpoint |
| Destructive DB resets | normal | disposable-data only | never after real data matters |

---

# 23. Pre-handoff environment verification

```text
[ ] No placeholder required values remain
[ ] Root CLIENT_KEY correct
[ ] Selected client directory exists
[ ] Selected client .env populated with deployment/runtime values
[ ] Selected client config/client.php has correct preset and timezone
[ ] Selected client config/modules.php has correct runtime modules
[ ] APP_ENV correct
[ ] APP_DEBUG false outside local
[ ] APP_KEY set and preserved
[ ] root and client `.env` files are owned/readable by the deploy and PHP-FPM identities without being world-readable
[ ] client `.env` contains only ClientEnvironmentLoader-owned keys; root/process keys were not copied there for symmetry
[ ] URLs correct
[ ] `CRM_APP_URL` resolves to the actual registered CRM route host (including `app.` deployments)
[ ] DB correct
[ ] Redis DB/prefix isolation correct
[ ] Cache prefix unique
[ ] Horizon prefix unique
[ ] Horizon queue list covers executable queues
[ ] Root logging resolves to the intended production channel/stack/level/retention
[ ] Production observability is installed/verified when this deployment uses the Engage Core observability path
[ ] Mail sender fallbacks resolve to non-empty addresses
[ ] Resend API key and active webhook signing secrets set when email is enabled
[ ] Resend API key has Full Access when Inbound Messaging email receiving is enabled
[ ] INBOUND_EMAIL_DOMAIN matches the verified Resend receiving domain when inbound email is enabled
[ ] SMS_ENABLED deliberate
[ ] Telnyx sender numbers/profile IDs correct when enabled
[ ] Webinars/Zoom provider and credentials correct when enabled
[ ] setup user values handled securely
[ ] PROJECT_STATE_ADMIN_EMAIL is blank or matches the deliberately authorized CRM owner
[ ] External Forms client identity/secret/allowlist are populated only when external Forms access is enabled
[ ] staging credentials only used where intended
[ ] php artisan optimize:clear run after client/env changes
[ ] php artisan setup:validate passes
```