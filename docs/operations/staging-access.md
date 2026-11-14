# Engage Core staging browser access

This is a platform-owned staging-only preview gate, not CRM authentication. It applies to every web route, including module routes and non-GET state changes. It requires no additional database tables. It is inactive in local, testing, and production environments; `/staging-login` and `/staging-logout` respond 404 outside staging.

## Root-owned runtime configuration

Set `APP_ENV=staging` and supply **root/runtime-owned** `STAGING_USER` and `STAGING_PASSWORD_HASH`. The hash must be a PHP `password_hash()`-compatible bcrypt or Argon2 hash; generate it out of band and inject the complete encoded hash (including `$` characters) without interpolation. **Do not** place these settings in a selected-client repository or client `.env`, and do not commit them. The platform client-config loader rejects PHP overrides of the `staging` configuration namespace. Legacy `STAGING_PASSWORD` is ignored intentionally: before deploying this release, provision the hash through the Infrastructure-owned secret mechanism. Coordinate a deployment window because existing plaintext staging login settings will cease working.

Configure `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax` (or `strict`). Use an independent session cookie name and appropriately scoped `SESSION_DOMAIN` for staging versus production, and ensure the connection uses HTTPS, the correct trusted proxy settings, and persistent session storage (for example Redis/database). With `APP_ENV=staging`, the default for `SESSION_SECURE_COOKIE` is now true, and the gate returns a generic HTTP 503 if credentials or session-cookie safety settings are absent or invalid. This is intentional fail-closed behavior. A cached runtime must be built with these root values present; always rebuild config cache after credential installation or rotation. Runtime authentication reads `config('staging.*')`, not `env()`.

Successful login regenerates and destroys the previous session ID, then stores only a keyed credential-version proof, not the password or password hash. Changing either configured credential or `APP_KEY` invalidates the proof. Failed logins return generic feedback; POST `/staging-login` is CSRF-protected and limited to five requests per minute under Laravel’s request-signature throttling. An authenticated visitor can open `/staging-login` to sign out; POST `/staging-logout` invalidates the **whole session**, including any CRM user login using that same browser session, and regenerates the CSRF token.

## Route boundary

- Anonymous GET/HEAD on browser routes redirects to `/staging-login`, remembering only a same-origin **path and query** for the successful login. POST/PUT/PATCH/DELETE without staging access return HTTP 403 (with valid CSRF where applicable), never redirect and replay request bodies. Unsafe external or protocol-relative intended URLs are discarded.
- `/up` is the built-in Laravel health check registered outside the web middleware group and remains unauthenticated even when staging credentials are missing. It proves that PHP responds, **not** that the staging gate is configured or active.
- Exactly these webhooks-host routes bypass the preview session, retaining their existing provider-authentication and/or signature-verification middleware/controllers: `webhooks.forms.show` (GET/HEAD), `webhooks.forms.submissions.store` (POST), `webhooks.webinar` (POST), `webhooks.message-events.email` (POST), `webhooks.message-events.sms` (POST), `webhooks.inbound.sms` (POST), `webhooks.inbound.email` (POST), `webhooks.sms` (POST), `webhooks.email` (POST). Exemptions require the host to equal `webhooks.` + `app.root_domain`. New feature/webhook routes are protected unless explicitly reviewed and added. Do not replace this with `webhooks.*`.
- Staging web responses include `Cache-Control: private, no-store, max-age=0` and `X-Robots-Tag: noindex, nofollow, noarchive`. Static assets served directly by Nginx bypass Laravel; never put private data in public assets.

## Acceptance and deployment handoff

Run the focused PHP tests in the batch. Before DNS/publication, Infra must attest that this exact Core commit, root credentials, cached configuration, TLS, session cookie isolation, and actual gate behavior are installed. Test on **each relevant staging hostname**, not just the root:

1. Anonymous `GET /up` => 200; anonymous `GET /login` => 302 to `/staging-login` with `no-store`; `GET /staging-login` => 200, non-indexable.
2. Anonymous state-changing browser request with a valid CSRF token => 403; login POST without CSRF => 419; five invalid attempts from the same anonymous client => subsequent 429. Check no credential echo in logs/errors.
3. Use a browser (or a cookie jar plus CSRF token) over HTTPS: open a protected path with a query string, log in with the injected credentials, verify same-origin return, re-request a protected page using the established session, open `/staging-login` and sign out, verify protected access is revoked.
4. Confirm legitimate signed/provider-authenticated webhook deliveries and externally authenticated form transport still pass through their existing signature checks, while a new unreviewed `webhooks.*` route requires the browser gate.
5. Production and local routes retain their former behavior; `/staging-login` and `/staging-logout` return 404 outside staging. Staging cookies must not authorize a production host.

This gate does **not** replace TLS, CRM authorization, provider callback verification, or web server/network controls. No Infrastructure, DNS, web server, or client-repository edits are part of this batch.