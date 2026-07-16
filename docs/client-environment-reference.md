

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

---

# 1. Application identity and environment

Core variables:

```env
APP_NAME="EngageCore"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=https://DOMAIN.com
```

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
    selects client/{CLIENT_KEY}
```

The selected client then contributes:

```text
client/{CLIENT_KEY}/.env
    client deployment/runtime values

client/{CLIENT_KEY}/config/client.php
    client identity, selected preset, stable client timezone

client/{CLIENT_KEY}/config/modules.php
    explicitly enabled runtime product modules

client/{CLIENT_KEY}/config/**
    version-controlled product/business behavior and client overrides
```

`CLIENT_PRESET`, `ENABLED_MODULES`, and `CLIENT_TIMEZONE` are not part of the canonical environment contract.

The runtime module source of truth is:

```text
client/{CLIENT_KEY}/config/modules.php
    -> config('modules.enabled')
    -> ModuleManager
    -> enabled module providers and runtime availability
```

Preset packages may still declare `modules.enabled`, but that list is a package requirement contract: every declared module must be runtime-enabled for the selected package to be valid. It is not a second runtime module source of truth.

Core keeps a generic default enabled-module list for the no-client/default application state and test fallback. A selected client's `config/modules.php` replaces that list.

Client timezone is stable client config:

```php
client/{CLIENT_KEY}/config/client.php

'timezone' => 'America/Denver',
```

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
webhooks.<ROOT_DOMAIN>
```

`WEBHOOKS_APP_URL` is not part of the active application environment contract.

Typical production topology:

```text
<ROOT_DOMAIN>
crm.<ROOT_DOMAIN>
webinar.<ROOT_DOMAIN>
webhooks.<ROOT_DOMAIN>
```

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
client/{CLIENT_KEY}/config/client.php
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

Initial application user bootstrap:

```env
SETUP_USER_NAME=
SETUP_USER_EMAIL=
SETUP_USER_PASSWORD=
```

These are separate concepts.

```text
STAGING_USER / STAGING_PASSWORD
    environment access gate

SETUP_USER_*
    initial CRM/application user seed/bootstrap input
```

Remove or rotate bootstrap secrets after use according to operational policy.

---

# 7. Logging

Curated current-stack variables:

```env
LOG_CHANNEL=errorlog
LOG_STACK=errorlog
LOG_DEPRECATIONS_CHANNEL=null
LOG_DEPRECATIONS_TRACE=false
LOG_LEVEL=debug
LOG_DAILY_DAYS=14
```

For production, use an intentional log level such as `info` or stricter when appropriate.

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

Root `.env` owns cache backend and operational TTLs. The selected client `.env` owns `CACHE_PREFIX`.

Current deployment path:

```env
CACHE_STORE=redis
CACHE_PREFIX=CHANGE_ME_cache_
PUBLIC_RESPONSE_CACHE_ENABLED=true
```

Current response-cache TTL overrides:

```env
CACHE_NEXT_UPCOMING_WEBINAR_EMPTY_SECONDS=300
CACHE_NEXT_UPCOMING_WEBINAR_MIN_SECONDS=60
CACHE_ACTIVE_WEBINAR_SERIES_MIN_SECONDS=300
CACHE_PUBLIC_PAGE_CONFIG_SECONDS=3600
CACHE_WEBINAR_LANDING_PAGE_SECONDS=300
CACHE_EXTERNAL_API_RESPONSE_SECONDS=300
CACHE_IMAGE_MANIFEST_SECONDS=3600
```

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

These values are selected-client deployment values and belong in `client/{CLIENT_KEY}/.env`.

Canonical variables:

```env
FILESYSTEM_DISK=spaces
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_ENDPOINT=https://nyc3.digitaloceanspaces.com
DO_SPACES_REGION=nyc3
DO_SPACES_BUCKET=
CDN_BASE_URL=
```

`CDN_BASE_URL` falls back to the Spaces endpoint when absent, but an explicit CDN URL may be desirable when CDN delivery is enabled.

---

# 14. Email and Resend

Provider selection, credentials, webhook secrets, and sender identities are selected-client deployment values.

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

Resend credentials/webhook:

```env
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
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
client/{CLIENT_KEY}/config/modules.php
    -> config('modules.enabled')
    -> ModuleManager
```

Webinar provider selection and Zoom credentials are client deployment values:

```env
WEBINAR_PROVIDER=zoom

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
ZOOM_WEBHOOK_REPLAY_CACHE_TTL_SECONDS=600
```

`WEBINARS_ENABLED` is not part of the current canonical runtime contract. Module availability is decided through client module config.

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
[ ] URLs correct
[ ] DB correct
[ ] Redis DB/prefix isolation correct
[ ] Cache prefix unique
[ ] Horizon prefix unique
[ ] Horizon queue list covers executable queues
[ ] Mail sender fallbacks resolve to non-empty addresses
[ ] Resend secret/API key set when enabled
[ ] SMS_ENABLED deliberate
[ ] Telnyx sender numbers/profile IDs correct when enabled
[ ] Webinars/Zoom provider and credentials correct when enabled
[ ] setup user values handled securely
[ ] staging credentials only used where intended
[ ] php artisan optimize:clear run after client/env changes
[ ] php artisan setup:validate passes
```
