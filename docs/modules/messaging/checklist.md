# Messaging Change Checklist

Use for repeatable Messaging consent/channel checks. It is not backlog.

## Permission invitations

- Keep the one-time bypass send email-only.
- Normal Broadcasts never inherit imported-contact bypass behavior.
- SMS opt-in remains explicit and requires a phone number when selected.
- Accepted or previously claimed/sent invitations cannot create duplicate consent rows or resend through the bypass.
- Inject the public preference URL at runtime before provider send.
- Permission-invitation acceptance creates one marketing consent grant per explicitly selected channel, with `permission_invitation` retained as capture scope/provenance.
- Client copy may change without breaking behavioral tests.

## SMS/channel visibility

- Provider/runtime SMS capability may remain present while a surface hides SMS.
- Hiding SMS never disables consent, suppression/revocation, or inbound STOP/HELP protections.
- SMS appears only on explicitly enabled surfaces.
- Permission-invitation SMS opt-in remains explicit.

## Provider webhooks and inbound replies

- Keep provider delivery/lifecycle callbacks on Messaging-owned `/message-events/...` endpoints.
- Keep human inbound email/SMS on Inbound Messaging-owned `/inbound/...` endpoints.
- Never configure `email.received` on the Resend delivery/lifecycle endpoint.
- Configure the Resend inbound endpoint for `email.received` only.
- Keep every active Resend endpoint signing secret in `RESEND_WEBHOOK_SECRET`; the verifier accepts multiple trusted secrets during endpoint/key rotation.
- When inbound Resend email is enabled, the configured `RESEND_API_KEY` must be able to retrieve received emails; the current runtime therefore requires Resend Full Access rather than Sending Access.
- Configure `INBOUND_EMAIL_DOMAIN` only for the domain actually enabled for Resend Receiving.
- Preserve the reserved `reply+` signed Reply-To namespace for ScheduledMessage correlation; authored inbound routes use other local parts.
- A real reply test must prove webhook receipt, Receiving API retrieval, signed correlation, and durable InboundMessage creation.
- Keep Resend Open Tracking and Click Tracking disabled while Engage Core owns CTA tracking through `tracking_key`.
- Bounce, complaint, provider suppression, and definitive invalid-destination failures must remain Messaging-owned delivery-health consequences.
- Complaint suppressions remain protected from casual operator release.
- Editing a Contact destination must not delete historical suppression evidence; only current destination matches surface as active delivery issues.

See `provider-webhook-routing.md` for the technical contract and `../../operations/client-third-party-services-checklist.md` for provider-account provisioning.

## Email deliverability surfaces

- New tracked CTA redirects must use `messaging.[ROOT_DOMAIN]`, never the authenticated CRM hostname.
- Keep the historical CRM tracking route only as a compatibility alias for already-delivered signed links.
- Contact-backed marketing email must emit an HTTPS `List-Unsubscribe` header plus `List-Unsubscribe-Post: List-Unsubscribe=One-Click`.
- The one-click POST reuses the signed Messaging marketing-unsubscribe URL and must remain CSRF-exempt while still requiring a valid temporary signature.
- Browser unsubscribe GET continues to require explicit confirmation before the human flow revokes consent.
- Transactional and internal email must not acquire marketing list-unsubscribe headers merely because they share the same transport/provider.
- SPF, DKIM, DMARC, provider acceptance, and mailbox placement are separate concerns; these application-level headers/hosts improve the message contract but do not guarantee inbox placement.

## Bulk delivery

- Large recipient sets use the shared bounded bulk-delivery policy rather than module-specific magic numbers.
- Only one continuation producer chunk is queued at a time.
- Chunk size and release interval are configurable process policy.
- Bulk work uses `bulk_messages`; normal transactional/reminder queues remain isolated from bulk pressure.
- Producer retries must remain idempotent and must not duplicate ScheduledMessages or recipient ownership rows.
- Provider submission limits are shared through the configured cache store/scope; deployed environments keep the limiter Redis-backed.
- Resend requests-per-second configuration must match the current team-level provider allowance rather than assuming each worker/API key has separate capacity.
- Provider limiting happens before `provider_submission_started_at` and must not consume `SendScheduledMessageJob` provider-attempt budget.
- Producer-level chunk details stay on the durable producer record and are not copied into every ScheduledMessage meta payload.