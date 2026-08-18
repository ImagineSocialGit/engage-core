# Messaging Change Checklist

Use for repeatable Messaging consent/channel checks. It is not backlog.

## Permission invitations

- Keep the one-time bypass send email-only.
- Normal Broadcasts never inherit imported-contact bypass behavior.
- SMS opt-in remains explicit and requires a phone number when selected.
- Accepted or previously claimed/sent invitations cannot create duplicate consent rows or resend through the bypass.
- Inject the public preference URL at runtime before provider send.
- Accepted consent scopes match `messaging.permission_invitations.consent.scopes`.
- Client copy may change without breaking behavioral tests.

## SMS/channel visibility

- Provider/runtime SMS capability may remain present while a surface hides SMS.
- Hiding SMS never disables consent, suppression/revocation, or inbound STOP/HELP protections.
- SMS appears only on explicitly enabled surfaces.
- Permission-invitation SMS opt-in remains explicit.

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