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
