# Deployment Plan and Environment Contract

Engage Core separates version-controlled client/product decisions from deployment-specific runtime values.

```text
Development
  client config + enabled modules + provider/capability selections
  -> test
  -> commit/push

Staging / production
  pull committed Core + client repositories
  -> resolve deployment plan
  -> reconcile only required runtime environment values
  -> install/migrate/sync
  -> validate/smoke
```

Staging and production are deployment targets. Do not edit source or client config there.

## `.env.example` is a catalog, not an install template

The root `.env.example` and `docs/config-templates/client-environment.example` document the complete supported environment vocabulary. They are intentionally broader than any single deployment.

Do **not** create a runtime environment with:

```bash
cp .env.example .env
cp client/example/.env.example client/example/.env
```

Doing so creates irrelevant values, hides module/provider ownership, and makes later environment drift harder to reason about.

## Resolve only what the committed build needs

Use:

```bash
php artisan engage:deployment-plan
```

The command is read-only. Normal output emphasizes required environment values plus active persisted overrides so deployment blockers and deliberate runtime differences remain obvious. Inactive optional/defaulted rows stay hidden. Use:

```bash
php artisan engage:deployment-plan --verbose
```

when the full environment matrix is useful.

Machine-readable output remains exhaustive:

```bash
php artisan engage:deployment-plan --json
```

A missing, blank, invalid, or persisted-identity-mismatched required value causes a non-zero exit status so deployment automation can stop before runtime work continues. Provider/capability selectors may declare allowed values; unsupported selections are deployment blockers rather than deferred runtime failures. For example, an explicitly selected `CLIENT_KEY` cannot silently disagree with the value persisted in root `.env`.

## Add only missing required variable names

Use:

```bash
php artisan engage:environment:sync --write-missing
```

The synchronizer:

- writes a missing key only to its catalog-owned root or selected-client environment file;
- never overwrites an existing value;
- never removes unused values automatically;
- never invents secret values;
- creates new environment files as `0640`;
- preserves an existing file's mode;
- writes the active `CLIENT_KEY` when it is already known non-secret runtime state.

After synchronization, populate blank required values. If `APP_KEY` is blank, generate it with:

```bash
php artisan key:generate
```

Then clear cached configuration when appropriate and rerun:

```bash
php artisan engage:deployment-plan
```

## Environment ownership versus deployment necessity

`EnvironmentVariableCatalog` is bootstrap-safe and answers:

- whether Engage Core recognizes a key;
- whether it belongs in root `.env` or `client/[CLIENT_KEY]/.env`;
- which subsystem owns the key;
- whether the value is sensitive.

Deployment contributors answer a different question:

> Does this committed client build actually need this variable in this environment?

That distinction is required because selected-client `.env` loading happens before Laravel service providers and module contributor tags are available.

## Messaging provider requirements

Messaging contributes provider-aware deployment requirements instead of requiring credentials for every provider the codebase happens to support.

Email currently has one configured provider, `resend`, so Messaging requires an explicit supported `EMAIL_PROVIDER` selection. Local/testing deployment planning treats provider credentials as non-live; staging/production require `MAIL_MAILER=resend`, the Resend API key, a resolved sender address, and `RESEND_WEBHOOK_SECRET`.

Outbound Resend delivery/lifecycle callbacks are now owned directly by Messaging at:

```text
/message-events/email/resend
```

That endpoint records delivery evidence and applies Messaging-owned consequences such as bounce/complaint/provider suppressions and provider unsubscribe revocations. It no longer depends on the Inbound Messaging module. True received email remains separately owned by Inbound Messaging at:

```text
/inbound/email/resend
```

When inbound email receiving is enabled, the current runtime uses the same `RESEND_API_KEY` to retrieve the received message body, so that key needs Resend Full Access rather than Sending Access. `RESEND_WEBHOOK_SECRET` may contain multiple active endpoint secrets during provider-secret rotation/cutover.

SMS resolves progressively:

```text
SMS_ENABLED=false
    -> stop
    -> no SMS provider or provider credentials are required

SMS_ENABLED=true
    -> require a supported SMS_PROVIDER
    -> require only the selected provider's credentials and sender identities
```

Local/testing deployment planning allows either configured SMS provider behind non-live semantics. For staging/production, live SMS additionally requires Inbound Messaging because STOP, HELP, START/re-opt-in, and normal inbound replies still use the Inbound Messaging SMS path.

The canonical ownership split is:

```text
/message-events/sms/telnyx
    -> Messaging
    -> outbound delivery/lifecycle callback

/inbound/sms/telnyx
    -> Inbound Messaging
    -> inbound message + STOP/HELP/START compliance path
```

Although outbound Twilio provider code exists, the current public inbound SMS route and default inbound handler resolver admit Telnyx only. Therefore staging/production must not be declared ready with `SMS_PROVIDER=twilio`; current live-safe SMS provider selection is Telnyx. Disabled SMS never requires an SMS provider.

Messaging recognizes provider-neutral `SMS_FROM`, `SMS_FROM_TRANSACTIONAL`, and `SMS_FROM_MARKETING` fallbacks because current SMS configuration consumes them. Purpose-specific Telnyx/Twilio sender variables are required only when the effective sender chain does not already resolve a sender.

The Messaging public preference host is derived from the selected client's existing domain contract:

```text
messaging.[ROOT_DOMAIN]
```

Do not add `MESSAGING_APP_URL`. Deployment must provision DNS, TLS, and web-server routing for that hostname and verify the canonical Messaging public routes. Host reachability is an operational deployment concern; it is not modeled as a fake environment variable in this batch.

`MESSAGING_SMS_MARKETING_PROFILE_ID` and `MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID` remain recognized client keys. Inbound Messaging uses them, when present, to map Telnyx Messaging Profile IDs back to message purpose; STOP/re-opt-in behavior has safe fallback handling when a provider context cannot be mapped, so this batch does not make them universal required values.

Stable product decisions such as SMS enablement/provider selection are still environment-backed in the current runtime. A later product-config pass may move those non-secret decisions into committed client PHP config; this batch does not change that ownership yet.

## Scheduling public-origin requirements

Scheduling has one deployment-owned environment value: `SCHEDULING_APP_URL`.

The generic public booking surface is optional. Omitting `SCHEDULING_APP_URL` intentionally leaves public Scheduling routes disabled without blocking an otherwise valid Scheduling deployment. Internal appointment authoring, availability, lifecycle state, dashboard surfaces, and database-owned Scheduling configuration do not require a public Scheduling hostname.

When `SCHEDULING_APP_URL` is present, it must be a root-level `http://` or `https://` origin with no credentials, path, query, or fragment. The deployment plan applies the same origin rule used by Scheduling setup validation so a malformed deliberate override blocks before installation/runtime work instead of being reported as a ready optional value.

Examples:

```text
valid:   https://booking.example.com
valid:   https://appointments.example.com:8443
invalid: booking.example.com
invalid: https://booking.example.com/schedule
invalid: https://booking.example.com?source=crm
```

Scheduling hold lifetimes, destination-verification limits, public booking rate limits, travel defaults, reschedule suggestion limits, and expiration batch sizing remain committed application configuration. They are not deployment environment requirements merely because they affect Scheduling runtime behavior.

## Media writable-storage requirements

Media is a reusable runtime upload capability, so its live deployment requirements are different from static assets that were uploaded during development.

When Media is enabled in staging/production, its provider activates the existing `storage` environment owner and requires:

```text
FILESYSTEM_DISK=spaces
DO_SPACES_KEY
DO_SPACES_SECRET
DO_SPACES_ENDPOINT
DO_SPACES_REGION
DO_SPACES_BUCKET
CDN_BASE_URL
```

`DO_SPACES_ENDPOINT` and `CDN_BASE_URL` use the deployment plan's HTTP-origin validation. Local/testing keeps these values optional so tests and development can use fake/local disks. The Media module does not add DigitalOcean-specific runtime code; it writes through Laravel Filesystem and the live deployment selects the existing `spaces` adapter.

Media's application upload-size and accepted-file policy stay in committed `config/media.php`. Nginx/PHP request-size limits are server deployment configuration, not fake application environment variables.

## Adding a module

Module/config changes are authored in development and committed. A staging or production deployment must not modify `client/[CLIENT_KEY]/config/modules.php` to make the target work.

After a commit adds a module, the target resolves the new deployment plan. New runtime obligations appear as a delta. The operator fills only those new values and reruns the plan before installation/verification continues.

## Unused values

The plan may report present-but-unused keys, but only for subsystem owners whose deployment contributor coverage is active. This prevents false positives while deployment contributors are introduced module-by-module.

Unused values are informational. They are never deleted automatically.

## Client environment loader

`ClientEnvironmentLoader` uses the same bootstrap-safe catalog to enforce client ownership. It clears every legal client-owned value before applying the selected client's `.env`, including when that `.env` file does not yet exist. This prevents stale root or previously selected client values from leaking across clients.

## Current contributor coverage

Current deployment-plan contributors cover:

- Core
- Forms
- Messaging provider/runtime requirements
- Webinars / Zoom
- Scheduling public-origin requirements
- Media writable-storage requirements

Forms resolves public intake enablement and its signing/identity requirements. Messaging resolves the selected email/SMS providers, live credentials, sender fallbacks, webhook verification, and operational defaults. Webinars resolves Zoom provider readiness and post-event webhook requirements. Scheduling keeps public booking optional while validating any deliberately persisted public origin. Media activates storage-owner coverage only when its runtime upload capability is enabled, requiring writable Spaces and a stable CDN origin in staging/production.

Additional module/provider contributors should continue to be added from fresh dependency cones. The Bash launcher should consume the resolved deployment plan rather than re-encoding module requirements itself.