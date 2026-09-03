# Engage Core — Client Third-Party Services Checklist

## Purpose

This checklist contains only external-provider work required to support a client environment.

Use it alongside `client-staging-production-setup-checklist.md`.

Do not mix provider-dashboard work with local repository configuration or server provisioning. Record who owns each external account and whether staging and production intentionally share or isolate each resource.

---

# Environment inventory

Before creating credentials, fill this out:

```text
Client key:
Staging root domain:
Production root domain:
Staging server/IP:
Production server/IP:
GitHub organization:
Core repository:
Client repository:
Spaces bucket strategy:
Resend account/domain:
Telnyx account/numbers/profiles:
Zoom account/app:
Cloudflare Turnstile account/widget, when public forms use it:
```

For each service, classify environment isolation:

```text
separate staging and production resources
shared account, separate credentials/resources
intentionally shared resource
not used
```

Never allow an accidental staging webhook URL in a production provider dashboard.

---

# 1. GitHub repository access

## Required decisions

- [ ] Core repository access method decided.
- [ ] Client repository access method decided.
- [ ] Deploy key or machine-user strategy decided.
- [ ] Client-specific SSH host alias created when multiple identities are needed.

Useful server checks:

```bash
ls -la ~/.ssh
cat ~/.ssh/config
ssh -T git@<GITHUB_SSH_HOST_ALIAS>
```

Example SSH config shape:

```sshconfig
Host github-<CLIENT_KEY>-deploy
    HostName github.com
    User git
    IdentityFile ~/.ssh/<CLIENT_KEY>_deploy
    IdentitiesOnly yes
```

Verify both Core and client repository access before provisioning proceeds.

---

# 2. DNS and domain provider

Typical Engage Core production topology:

```text
<ROOT_DOMAIN>
<CORE_ADMIN_HOST>    commonly crm.<ROOT_DOMAIN> or app.<ROOT_DOMAIN>
webinar.<ROOT_DOMAIN>
webhooks.<ROOT_DOMAIN>
```

`CRM_APP_URL` is the canonical Core admin URL; do not treat the literal `crm` label as part of the platform contract.

Staging may use a separate domain or subdomain hierarchy.

For every required hostname:

- [ ] DNS record exists.
- [ ] Record points to intended environment/server.
- [ ] TTL understood during cutover.
- [ ] No stale legacy target remains.
- [ ] SSL issuance plan exists.

Record:

```text
Root/public:
CRM:
Webinar:
Webhooks:
Other:
```

Do not assume a wildcard certificate exists or covers every required hostname.

---

# 3. Cloudflare Turnstile, when public forms use it

Turnstile is an Artist Sites/public-form protection service, not a requirement for Engage Core itself and not a requirement to move DNS/reverse proxying to Cloudflare.

For the current `artist_updates` reference flow:

- [ ] Widget/account ownership is assigned to the organization/client rather than an individual developer account.
- [ ] MFA/recovery/offboarding access is documented.
- [ ] Widget allows the exact staging and production Artist Sites hostnames that will render the form.
- [ ] Each deployed Artist runtime validates only its own exact hostname(s); do not use wildcard application-side hostname acceptance.
- [ ] Staging/production site key and secret inventory is recorded securely.
- [ ] Site key may be public; secret key is server-only.
- [ ] No secret is committed to source control, Site State, logs, or ordinary deployment artifacts.

Current Artist Sites deployment variables are:

```env
FORMS_HUMAN_VERIFICATION_PROVIDER=turnstile
TURNSTILE_SITE_KEY=
TURNSTILE_SECRET_KEY=
TURNSTILE_EXPECTED_HOSTNAMES=
```

A shared Cloudflare widget may authorize both staging and production hostnames, but each runtime's `TURNSTILE_EXPECTED_HOSTNAMES` should remain narrow. Run Artist Sites `php artisan site:check` after installing credentials before Core is changed to require verification.

---

# 4. DigitalOcean Spaces

Current Core storage path supports DigitalOcean Spaces through the Laravel S3 driver.

Spaces is not a universal requirement merely because static client assets may already be hosted there. When the Media module is enabled in staging/production, its deployment contributor activates writable-storage coverage: `FILESYSTEM_DISK` remains root/process-owned and the remaining Spaces/CDN values are selected-client deployment values. Clients without an enabled runtime upload capability are not forced to satisfy this block by Media.

```env
FILESYSTEM_DISK=spaces
DO_SPACES_KEY=
DO_SPACES_SECRET=
DO_SPACES_ENDPOINT=
DO_SPACES_REGION=
DO_SPACES_BUCKET=
CDN_BASE_URL=
```

Checklist:

- [ ] Bucket selected or created.
- [ ] Region recorded.
- [ ] Endpoint recorded.
- [ ] Access key created with appropriate scope.
- [ ] Secret stored securely.
- [ ] CDN enabled only when intended.
- [ ] CDN URL recorded.
- [ ] Staging/production bucket or prefix isolation deliberate.
- [ ] Upload/read test succeeds through the application.

Do not reuse a production write credential in staging without a deliberate reason.

---

# 5. Resend

Current canonical email path is Laravel's `resend` transport plus Messaging's Resend provider integration.

Use this section as the operator-facing Resend account/DNS/webhook setup authority. The technical route/event ownership contract is `docs/modules/messaging/provider-webhook-routing.md`.

Core deployment variables:

```env
MAIL_MAILER=resend
EMAIL_PROVIDER=resend
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=
FROM_EMAIL_TRANSACTIONAL=
FROM_NAME_TRANSACTIONAL=
FROM_EMAIL_MARKETING=
FROM_NAME_MARKETING=
RESEND_API_KEY=
RESEND_WEBHOOK_SECRET=
INBOUND_EMAIL_DOMAIN=
RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS=300
```

`RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS` is root/process-owned. The remaining client-varying Resend/sender values are selected-client deployment values.

Provider-specific sender overrides exist but are optional:

```text
RESEND_FROM_EMAIL_TRANSACTIONAL
RESEND_FROM_NAME_TRANSACTIONAL
RESEND_FROM_EMAIL_MARKETING
RESEND_FROM_NAME_MARKETING
```

Do not set optional override variables to blank values merely for symmetry. A blank override can defeat the intended fallback sender identity.

## Recommended domain topology

For a client that uses both outbound email and inbound replies, prefer three separate roles:

```text
email.<ROOT_DOMAIN>
    Resend sending domain
    Sending ON
    Receiving OFF

replies.<ROOT_DOMAIN>
    Resend receiving domain
    Sending OFF
    Receiving ON

webhooks.<ROOT_DOMAIN>
    public Engage Core webhook host
```

This keeps sending authentication/reputation, inbound mail routing, and application webhook delivery separate.

If the client deliberately needs a root-domain From address such as `person@<ROOT_DOMAIN>`, verify that exact root domain for sending instead. Verifying only `email.<ROOT_DOMAIN>` does not make `person@<ROOT_DOMAIN>` a valid From identity.

### Sending domain

- [ ] Add `email.<ROOT_DOMAIN>` to Resend unless another sending domain was deliberately chosen.
- [ ] Enable Sending.
- [ ] Disable Receiving when this domain is outbound-only.
- [ ] Leave Custom Return Path at Resend's default unless an actual DNS collision or provider-specific requirement exists.
- [ ] Copy the DKIM/SPF/return-path DNS records exactly as generated by the Resend dashboard.
- [ ] Do not replace unrelated root-domain mailbox MX records merely to enable outbound sending.
- [ ] Wait for the Resend sending capability to verify.
- [ ] Leave Resend Open Tracking OFF.
- [ ] Leave Resend Click Tracking OFF.

Engage Core owns explicit CTA engagement tracking. Resend Click Tracking adds another redirect layer and should not be enabled on top of Messaging `tracking_key` links.

A normal visible sender can use the verified sending subdomain plus a human display name:

```text
Display name:  <PERSON OR BRAND NAME>
Address:       <local-part>@email.<ROOT_DOMAIN>
```

Example rendered header shape:

```text
Example Sender <sender@email.example.com>
```

The transactional and marketing display names may be the same or intentionally different through `FROM_NAME_TRANSACTIONAL` and `FROM_NAME_MARKETING`.

### Receiving domain

When Inbound Messaging email is enabled:

- [ ] Add `replies.<ROOT_DOMAIN>` to Resend as the receiving domain.
- [ ] Enable Receiving.
- [ ] Disable Sending when this domain is receive-only.
- [ ] Leave Custom Return Path at the default; it is not part of the receive-only path.
- [ ] Add the receiving MX record exactly as generated by Resend.
- [ ] Wait for the Resend receiving capability to verify.
- [ ] Set `INBOUND_EMAIL_DOMAIN=replies.<ROOT_DOMAIN>`.

Do not create individual mailboxes for Engage-generated signed Reply-To addresses. Resend Receiving accepts mail for local parts on the configured receiving domain, and Engage Core routes/correlates the address after the webhook arrives.

The `reply+...` local-part namespace is reserved for signed ScheduledMessage correlation. Authored Inbound Messaging routes may use other local parts on the same receiving domain.

## API key

Create an environment-specific Resend API key and store it only in the selected-client environment.

Recommended naming shape:

```text
Engage Core — <CLIENT> — Staging
Engage Core — <CLIENT> — Production
```

Permission rule:

```text
Outbound email only
    Sending Access may be used.

Inbound Messaging email receiving enabled
    Full Access is required by the current Engage Core runtime.
```

The reason is executable, not merely administrative: Resend's `email.received` webhook does not contain the complete body, headers, or attachments. Engage Core uses the same `RESEND_API_KEY` to call the Received Emails API and retrieve the received message by `email_id`.

- [ ] API key created.
- [ ] Permission matches the enabled feature set.
- [ ] `RESEND_API_KEY` stored in the correct selected-client environment.
- [ ] Production and staging do not share a key accidentally.

Do not document a separate inbound/read API key until Engage Core runtime explicitly supports separate credentials.

## Delivery/lifecycle webhook

Create a Resend webhook registration with:

```text
URL
https://webhooks.<ROOT_DOMAIN>/message-events/email/resend
```

Subscribe to exactly:

```text
email.sent
email.delivered
email.delivery_delayed
email.bounced
email.complained
email.suppressed
email.failed
contact.updated
```

Do not subscribe this endpoint to:

```text
email.received
email.opened
email.clicked
email.scheduled
suppression.added
suppression.removed
```

The omitted events either belong to the inbound path or do not currently have an Engage Core runtime contract.

- [ ] Delivery/lifecycle webhook created.
- [ ] Exact production/staging hostname confirmed.
- [ ] Signing secret copied.
- [ ] Signature verification tested.
- [ ] Timestamp drift setting remains deliberate.

## Inbound-email webhook

When Inbound Messaging email is enabled, create a second Resend webhook registration with:

```text
URL
https://webhooks.<ROOT_DOMAIN>/inbound/email/resend
```

Subscribe only to:

```text
email.received
```

- [ ] Inbound webhook created.
- [ ] No delivery/lifecycle events selected on this endpoint.
- [ ] Signing secret copied.
- [ ] A real inbound message reaches the endpoint.

Each Resend webhook registration may have a different Svix secret. Put every currently active trusted secret into the existing variable:

```env
RESEND_WEBHOOK_SECRET=whsec_delivery...,whsec_inbound...
```

The current verifier accepts secrets separated by commas, semicolons, or whitespace.

## Webhook-host prerequisites

Before testing Resend:

- [ ] `webhooks.<ROOT_DOMAIN>` resolves to the correct Engage Core environment.
- [ ] Valid HTTPS certificate is installed.
- [ ] Nginx routes the host to Engage Core `public/`.
- [ ] No HTTP Basic Auth, staging-login redirect, browser challenge, or WAF rule blocks Resend.
- [ ] Canonical webhook routes are deployed before the provider endpoints are changed.

Webhook authentication is the Svix signature. Do not put an interactive browser authentication gate in front of provider callbacks.

## End-to-end Resend verification

Use Resend's provider-safe test addresses for lifecycle cases:

```text
delivered@resend.dev
bounced@resend.dev
complained@resend.dev
suppressed@resend.dev
```

Verify:

```text
[ ] delivered test reaches the Messaging lifecycle webhook and durable receipt
[ ] bounced test creates bounce suppression and a current Contact delivery issue when the destination matches
[ ] complained test creates complaint suppression and the complaint is not casually releasable
[ ] suppressed test creates provider suppression
[ ] repeated provider delivery is deduplicated by webhook receipt identity
```

When Inbound Messaging is enabled, also perform a real reply test:

```text
[ ] send a real Engage Core email
[ ] inspect that its Reply-To uses the configured replies domain
[ ] reply to that message
[ ] Resend emits email.received to /inbound/email/resend
[ ] Engage Core retrieves the received email through the Resend API
[ ] signed Reply-To correlation resolves the originating ScheduledMessage/contact when applicable
[ ] one durable InboundMessage is created
```

Do not treat a successful outbound send as proof that inbound receiving works. The webhook, Receiving API permission, receiving MX, and signed correlation are separate gates.

## Environment separation

Confirm whether staging uses:

```text
same account with separate API key
separate sending/receiving subdomains
separate webhook registrations
safe provider test recipients
```

Production must not point to the staging webhook endpoint, and staging must not send from a production identity unless that sharing is deliberate.

---

# 6. Telnyx

Current primary SMS provider is Telnyx.

Core variables:

```env
SMS_ENABLED=true
SMS_PROVIDER=telnyx
TELNYX_API_KEY=
TELNYX_FROM_TRANSACTIONAL=
TELNYX_FROM_MARKETING=
TELNYX_FROM_NOTIFICATIONS=
TELNYX_WEBHOOK_PUBLIC_KEY=
MESSAGING_SMS_MARKETING_PROFILE_ID=
MESSAGING_SMS_TRANSACTIONAL_PROFILE_ID=
```

Optional generic fallback:

```text
TELNYX_FROM
SMS_FROM
SMS_FROM_TRANSACTIONAL
SMS_FROM_MARKETING
```

Prefer purpose-specific sender numbers when the client has distinct transactional and marketing sender requirements.

## Number/profile setup

- [ ] Telnyx account active.
- [ ] API key created.
- [ ] Transactional sending number assigned.
- [ ] Marketing sending number assigned.
- [ ] Notification number assigned when internal SMS notifications are enabled.
- [ ] Messaging profile IDs recorded when used.
- [ ] Regulatory/brand/campaign requirements satisfied for the target country/use case.

## Inbound webhook setup

Current SMS config expects Telnyx inbound event support for:

```text
message.received
```

Checklist:

- [ ] Correct environment webhook URL configured.
- [ ] Public key recorded in `TELNYX_WEBHOOK_PUBLIC_KEY`.
- [ ] Signature verification succeeds.
- [ ] Inbound message reaches Engage Core.
- [ ] STOP keywords revoke/suppress SMS as designed.
- [ ] HELP keywords receive the configured help response.
- [ ] Normal reply behavior reaches the intended InboundMessaging/InternalNotifications path when enabled.

## Channel availability check

Provider credentials do not automatically make SMS visible everywhere.

Verify:

```text
SMS_ENABLED
Messaging channel availability
surface visibility
purpose/scope eligibility
consent
suppression
recipient phone
```

A valid Telnyx API key is not sufficient proof that a client-facing SMS option should appear.

---

# 7. Twilio, only when intentionally used

Current Core still contains Twilio configuration support, but Telnyx is the canonical primary SMS path.

Optional variables:

```env
TWILIO_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM=
TWILIO_FROM_TRANSACTIONAL=
TWILIO_FROM_MARKETING=
TWILIO_VIRTUAL_PHONE=
```

Do not populate these for a Telnyx-only client merely because the variables exist.

---

# 8. Zoom Server-to-Server OAuth

Webinars uses Zoom Meeting and Webinar API operations through one Server-to-Server OAuth app.
The same provider family supports both Zoom Webinars and Zoom Meetings; each Webinar
series selects its event type, while every synchronized occurrence preserves the remote
type that created it.

Core variables:

```env
WEBINAR_PROVIDER=zoom
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_WEBHOOK_SECRET=
ZOOM_BASE_URL=https://api.zoom.us/v2
ZOOM_OAUTH_URL=https://zoom.us/oauth/token
ZOOM_OAUTH_TOKEN_TTL_SECONDS=3500
ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS=300
```

`WEBINARS_ENABLED` is not a canonical environment variable. Enable the module through
the selected client module configuration.

## App type and activation

- [ ] Server-to-Server OAuth app exists.
- [ ] Correct Zoom account owns the app.
- [ ] The Zoom account has the required Meeting plan.
- [ ] The Zoom account has a Webinar add-on when Webinar event types are used.
- [ ] Account ID recorded.
- [ ] Client ID recorded.
- [ ] Client secret recorded.
- [ ] App activated after every scope change.
- [ ] The app owner/role can access account Reports for attendance reconciliation.

## Exact runtime API calls and granular admin scopes

The following granular admin scope labels were verified against Zoom's official granular
scope catalog on July 22, 2026. Re-check the current Zoom Marketplace UI whenever Zoom
changes its scope catalog, but do not add broader capabilities merely because they are
available.

### Meeting occurrence lookup and registration

```text
meeting:read:list_meetings:admin
meeting:write:registrant:admin
meeting:delete:registrant:admin
```

These support:

```text
GET    /users/me/meetings
POST   /meetings/{meetingId}/registrants
DELETE /meetings/{meetingId}/registrants/{registrantId}
```

### Webinar occurrence lookup and registration

```text
webinar:read:list_webinars:admin
webinar:write:registrant:admin
webinar:delete:registrant:admin
```

These support:

```text
GET    /users/me/webinars
POST   /webinars/{webinarId}/registrants
DELETE /webinars/{webinarId}/registrants/{registrantId}
```

### Attendance reconciliation

```text
report:read:list_meeting_participants:admin
report:read:list_webinar_participants:admin
```

These support:

```text
GET /report/meetings/{meetingId}/participants
GET /report/webinars/{webinarId}/participants
```

The Zoom account must satisfy the plan and Reports-role prerequisites for these
endpoints. Basic Meeting or Webinar access does not imply participant-report access.

### Cloud-recording lookup

```text
cloud_recording:read:list_recording_files:admin
```

This supports:

```text
GET /meetings/{meetingIdOrUuid}/recordings
```

Zoom uses the Meeting recording endpoint for both Meeting and Webinar recordings. The
runtime prefers the provider UUID when one is available.

The current runtime does not need broad meeting/webinar creation, event deletion,
registrant-list, or single-event-read scopes. Add a scope only when a current provider
call requires it.

Official references:

- [Zoom API authentication and access tokens](https://developers.zoom.us/docs/api/)
- [Zoom granular scopes for internal apps](https://developers.zoom.us/docs/internal-apps/oauth-scopes-granular/)
- [Zoom Meeting and Webinar API operations](https://developers.zoom.us/docs/api/meetings/)
- [Zoom Meeting and Webinar webhook events](https://developers.zoom.us/docs/api/meetings/events/)

## Webhook subscriptions

Subscribe the environment-specific Zoom app endpoint to these exact native events:

```text
webinar.ended
meeting.ended
recording.completed
```

Core normalizes them as follows:

```text
webinar.ended          -> webinar.ended
meeting.ended          -> webinar.ended
recording.completed    -> webinar.recording_completed
```

`webinar.completed` remains a compatibility alias in Core configuration. Current Zoom
subscriptions must use `webinar.ended` for Webinar occurrences and `meeting.ended` for
Meeting occurrences.

Current post-event orchestration uses:

```text
webinar.ended
    Resolve the Webinar or Meeting occurrence by provider type plus ID/UUID.
    Record provider attendance.

webinar.recording_completed
    Resolve playback.
    Dispatch post-event follow-ups when their conditions are satisfied.
```

Checklist:

- [ ] Correct environment webhook URL configured.
- [ ] `ZOOM_WEBHOOK_SECRET` recorded from the same app/environment.
- [ ] `webinar.ended` subscribed when Zoom Webinars are used.
- [ ] `meeting.ended` subscribed when Zoom Meetings are used.
- [ ] `recording.completed` subscribed when replay follow-ups are used.
- [ ] Endpoint URL validation succeeds in the Zoom Marketplace.
- [ ] Real signed delivery passes signature verification.
- [ ] Timestamp verification succeeds with the configured drift limit.
- [ ] A completed duplicate returns safely without dispatching twice.
- [ ] A simulated processing failure leaves a retryable durable receipt and the provider retry completes it.
- [ ] Each native event reaches the intended `webhooks` queue and Horizon worker.

## End-to-end Zoom verification

Before a real client event, verify each event type the client will operate:

```text
Webinar lookup succeeds when Webinar series are enabled.
Meeting lookup succeeds when Meeting series are enabled.
Registration creation returns and stores a personalized join URL.
Provider cancellation removes the correct canonical registrant.
Meeting participant-report retrieval succeeds.
Webinar participant-report retrieval succeeds when used.
webinar.ended is accepted and resolves the Webinar occurrence.
meeting.ended is accepted and resolves the Meeting occurrence.
recording.completed is accepted and resolves playback.
Post-event follow-ups dispatch only when playback conditions are satisfied.
```

## Webinar-to-Meeting replacement smoke test

Run this in staging before relying on an event-type switch:

```text
1. Sync a Webinar series and preserve its existing occurrence.
2. Change the series provider event type to Meeting for future synchronization.
3. Sync the replacement Meeting occurrence.
4. Explicitly replace the obsolete Webinar occurrence with the Meeting occurrence.
5. Verify created/adopted/queued/succeeded/failed/reconciliation totals.
6. Verify successful registrants are not reprovisioned twice.
7. Verify failed registrants remain individually retryable.
8. Verify old join links resolve to the canonical Meeting registration.
9. Verify old thank-you links show the canonical occurrence and status.
10. Verify old cancellation links cancel only the canonical provider registrant.
```

Do not wait for the first live event to discover a missing scope, role permission,
webhook subscription, or replacement-recovery problem.

---

# 9. Final external-services handoff gate

```text
[ ] GitHub Core access works from server
[ ] GitHub client-repo access works from server
[ ] All DNS records resolve to intended environment
[ ] SSL plan/certificates cover required hostnames, including the configured CRM_APP_URL host and webhooks host
[ ] Turnstile widget/credentials/hostname policy verified when public forms require human verification
[ ] Spaces credentials work
[ ] Spaces/CDN isolation deliberate
[ ] Resend sending domain/capability verified when email is enabled
[ ] Resend Open Tracking and Click Tracking remain off
[ ] Resend API key works and has Full Access when inbound email is enabled
[ ] Resend delivery/lifecycle webhook points to the correct environment
[ ] Resend receiving domain/capability, INBOUND_EMAIL_DOMAIN, and email.received webhook are verified when inbound email is enabled
[ ] All active Resend endpoint signing secrets are stored in RESEND_WEBHOOK_SECRET
[ ] Transactional and marketing email sender addresses/display names resolve
[ ] Real inbound reply retrieval/correlation succeeds when inbound email is enabled
[ ] Telnyx API key works when SMS enabled
[ ] Transactional/marketing/notification numbers resolve as needed
[ ] Telnyx profile IDs correct when used
[ ] Telnyx inbound webhook points to correct environment when used
[ ] Zoom Server-to-Server app active when Webinars enabled
[ ] Zoom Meeting and Webinar registration/lookup scopes sufficient
[ ] Zoom recording scopes sufficient when replay used
[ ] Zoom Meeting and Webinar attendance-report scopes sufficient
[ ] Zoom webhook subscriptions correct
[ ] No production provider points to staging by accident
[ ] Secrets stored only in approved locations
```