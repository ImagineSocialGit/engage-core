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
crm.<ROOT_DOMAIN>
webinar.<ROOT_DOMAIN>
webhooks.<ROOT_DOMAIN>
```

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

# 3. DigitalOcean Spaces

Current Core storage path supports DigitalOcean Spaces through the Laravel S3 driver.

Required values:

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

# 4. Resend

Current canonical email path is Laravel's `resend` transport plus Messaging's Resend provider integration.

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
RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS=300
```

Provider-specific sender overrides exist but are optional:

```text
RESEND_FROM_EMAIL_TRANSACTIONAL
RESEND_FROM_NAME_TRANSACTIONAL
RESEND_FROM_EMAIL_MARKETING
RESEND_FROM_NAME_MARKETING
```

Do not set optional override variables to blank values merely for symmetry. A blank override can defeat the intended fallback sender identity.

## Domain and sender setup

- [ ] Sending domain added.
- [ ] Required DNS records published.
- [ ] Domain verified.
- [ ] Production sending enabled/approved where required.
- [ ] API key created.
- [ ] Transactional sender address verified.
- [ ] Marketing sender address verified.
- [ ] Display names confirmed.

## Webhook setup

When delivery-event processing is used:

- [ ] Correct environment webhook URL configured.
- [ ] Webhook secret recorded.
- [ ] Signature verification tested.
- [ ] Timestamp drift setting appropriate.
- [ ] A real test reaches the expected sent/delivery lifecycle.

## Environment separation

Confirm whether staging uses:

```text
same verified domain with safe test recipients
separate subdomain/domain
separate API key
same account but separate webhook endpoint
```

Production must not point to the staging webhook endpoint.

---

# 5. Telnyx

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

# 6. Twilio, only when intentionally used

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

# 7. Zoom Server-to-Server OAuth

Current Webinar provider path is Zoom.

Core variables:

```env
WEBINARS_ENABLED=true
WEBINAR_PROVIDER=zoom
ZOOM_ACCOUNT_ID=
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_WEBHOOK_SECRET=
ZOOM_BASE_URL=https://api.zoom.us/v2
ZOOM_OAUTH_URL=https://zoom.us/oauth/token
ZOOM_OAUTH_TOKEN_TTL_SECONDS=3500
ZOOM_WEBHOOK_MAX_TIMESTAMP_DRIFT_SECONDS=300
ZOOM_WEBHOOK_REPLAY_CACHE_TTL_SECONDS=600
```

## App type and activation

- [ ] Server-to-Server OAuth app exists.
- [ ] Correct Zoom account owns the app.
- [ ] Account ID recorded.
- [ ] Client ID recorded.
- [ ] Client secret recorded.
- [ ] App activated after scope changes.

## Required capability scopes

The exact Zoom scope names available in the dashboard can evolve, so verify against the current Zoom app UI. The production setup that informed this checklist required capabilities equivalent to:

### Webinar registration and lookup

```text
webinar:write:registrant:admin
webinar:delete:registrant:admin
webinar:read:list_webinars:admin
webinar:read:webinar:admin
webinar:read:list_registrants:admin
```

### Recording access

```text
cloud_recording:read:list_recording_files:admin
cloud_recording:read:recording:admin
```

### Attendance reports

```text
report:read:list_webinar_participants:admin
```

The attendance-report capability is independent of basic webinar lookup/registrant access. Test it explicitly.

## Webhook subscriptions

Current Core provider mapping handles:

```text
webinar.ended
webinar.completed -> normalized to webinar.ended
recording.completed -> normalized to webinar.recording_completed
```

Current post-event orchestration uses:

```text
webinar.ended
    RecordWebinarProviderAttendanceAction

webinar.recording_completed
    ResolveWebinarPlaybackAction
    DispatchPostWebinarFollowUpsAction
```

Checklist:

- [ ] Correct environment webhook URL configured.
- [ ] `ZOOM_WEBHOOK_SECRET` recorded.
- [ ] `webinar.ended` subscribed.
- [ ] `recording.completed` subscribed when replay follow-ups are used.
- [ ] Any equivalent/required completion event intentionally subscribed.
- [ ] Signature validation succeeds.
- [ ] Replay/timestamp protection succeeds.
- [ ] Real or simulated event reaches the intended queued job.

## End-to-end Zoom verification

Before a real client webinar:

```text
webinar lookup succeeds
registration creation succeeds
personalized join URL stored
attendance report retrieval succeeds
webinar-ended webhook accepted
recording-completed webhook accepted
playback URL resolves
post-event follow-ups dispatch only when playback condition is satisfied
```

Do not wait for the first live event to discover a missing report scope or webhook subscription.

---

# 8. Final external-services handoff gate

```text
[ ] GitHub Core access works from server
[ ] GitHub client-repo access works from server
[ ] All DNS records resolve to intended environment
[ ] SSL plan/certificates cover required hostnames
[ ] Spaces credentials work
[ ] Spaces/CDN isolation deliberate
[ ] Resend domain verified when email enabled
[ ] Resend API key works
[ ] Resend webhook points to correct environment when used
[ ] Transactional and marketing email senders resolve
[ ] Telnyx API key works when SMS enabled
[ ] Transactional/marketing/notification numbers resolve as needed
[ ] Telnyx profile IDs correct when used
[ ] Telnyx inbound webhook points to correct environment when used
[ ] Zoom Server-to-Server app active when Webinars enabled
[ ] Zoom webinar registration/lookup scopes sufficient
[ ] Zoom recording scopes sufficient when replay used
[ ] Zoom attendance-report scope sufficient
[ ] Zoom webhook subscriptions correct
[ ] No production provider points to staging by accident
[ ] Secrets stored only in approved locations
```
