# Client Request Intake Template

Use this prompt when asking a new thread to convert a client request into Engage Core config files.

```text
We are generating Engage Core client config files.

Rules:
- Views remain in resources/views.
- Modules live under app/Modules.
- Integrations live under app/Integrations.
- Use lead/leads, not borrower/borrowers, in CRM/client-facing text unless explicitly told otherwise.
- Do not preserve old behavior by default.
- Do not invent columns, runtime features, unsupported module behavior, or undocumented tokens.
- Messaging configs own reusable message copy and delivery templates.
- Campaign presets own journey/order/timing/template references.
- Campaign presets do not own payload/copy and do not override payload/copy.
- Campaign preset steps reference Messaging templates with first-class channel, purpose, and scope keys.
- Do not use meta.message for new Campaign preset step message references.
- Campaign messages resolve by:
  messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
- FlowRoute presets own automation/control-flow routing.
- Webinar post-event config owns provider orchestration, not message copy.
- Webinar schedule profile configs own webinar message timing/slot identity, not message copy.
- Schedule profile items may share generic Messaging message types such as `reminder`; use item keys and source config paths for schedule-slot identity instead of schedule-specific message types like `reminder_30_minute`.
- Persisted runtime payloads should be compact. Do not store full Eloquent model arrays or loaded relationship graphs in scheduled message payloads, automation events, route progress, task metadata, or broadcast/inbound metadata unless that column is explicitly a raw provider payload.
- Default webinar configs should be vertical-neutral.
- Vertical-specific copy belongs in vertical-specific scopes such as marketing:mortgage_homebuyer_nurture.
- Email first. SMS may mirror after email passes, but SMS visibility in UI should be config-toggleable per client/surface.
- Normal Broadcasts require normal Messaging consent.
- Imported-contact opt-in invitations are a distinct one-time email flow, not a normal Broadcast bypass.
- Permission invitation copy/style lives in config/messaging/permission_invitations.php or a client override, using permission-invitations-template.php as the config shape.
- Permission invitation public pages may offer email, SMS, or both according to config/client requirements.
- Use purpose/scope pairs:
  - transactional:webinar for confirmations/reminders/replay follow-ups.
  - transactional:permission_invitation for imported-contact one-time opt-in invitation emails.
  - marketing:webinar_nurture for attended/missed webinar nurture campaigns.
  - marketing:webinar_waitlist for waitlist notices.
  - marketing:mortgage_homebuyer_nurture for mortgage-specific long-term homebuyer nurture.
- Use dispatch keys:
  - registration_created for webinar registration confirmation/reminders.
  - consent_granted for opt-in messages.
  - webinar_ended for transactional post-webinar replay/follow-ups.
  - campaign_step_due for campaign step messages.
  - broadcast_send for regular one-time Broadcast sends.
  - imported_contact_permission_invitation for the one-time imported-contact opt-in invitation.
- Do not use marketing_message_sent for new configs.
- Token ownership is layered:
  - Universal Contact tokens are available when the recipient is a Contact.
  - Module-specific tokens are available only when that module supplies its message data object.
  - Campaign/context URL tokens are available only when the enrollment/start path explicitly supplies them.
- Universal Contact tokens:
  - `{first_name}`
  - `{last_name}`
  - `{name}`
  - `{email}`
  - `{phone}`
- Do not use runtime-only URL tokens such as `{next_step_url}`, `{application_url}`, `{contact_url}`, or `{webinar_registration_url}` in campaign copy unless the source payload is documented.
- If client copy requires a missing token, list the required message-data/runtime work separately instead of inventing the token.
- Messaging should block unresolved tokens before provider send.
- Commerce/Location requests should improve admin/client convenience; do not turn Engage Core into a storefront, checkout, GIS, routing, or map product.
- If a request needs module behavior beyond config, identify the owning module and list required code/seam work separately.

Attached reference templates:
- README.md
- TOKEN_REFERENCE.md
- messaging-email-template.php
- messaging-sms-template.php
- permission-invitations-template.php
- campaign-presets-template.php
- contact-status-presets-template.php
- task-presets-template.php
- flow-routes-template.php
- webinar-post-event-template.php
- presets-root-template.php
- modules-template.php

Client request:
[PASTE CLIENT REQUEST HERE]

Return complete config files only, using the same structural shapes as the templates.
List any recommended new keys/tokens separately.
If the request requires unsupported module behavior, list the needed module/seam work separately instead of inventing config keys.
```

