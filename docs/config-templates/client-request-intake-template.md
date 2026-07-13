# Client Request Intake Template

Use this prompt when asking a new thread to convert a client request into Engage Core config files.

```text
We are generating Engage Core client config files.

Rules:
- Views remain in resources/views.
- Modules live under app/Modules.
- Integrations live under app/Integrations.
- Use `contact` for canonical internal keys, preset identifiers, events, triggers, routes, task-template keys, runtime fields, and generic system terminology. Client-facing copy may use the configured industry noun such as Lead, Fan, Customer, Borrower, Owner, or another deliberate label.
- Do not preserve old behavior by default.
- Do not invent columns, runtime features, unsupported module behavior, undocumented tokens, or available fields.
- Messaging configs own reusable message copy and delivery templates.
- Reusable Messaging templates must not own `timing`, `schedule`, `conditions`, lifecycle enablement, sequencing, dependencies, or module-specific skip behavior for module-owned flows.
- Every resolved module-owned dispatch must provide either exact caller-owned `sendAt` or explicit caller-owned behavior; there is no implicit immediate fallback.
- Module-owned dispatch paths should use stable logical `occurrenceKey` identity for retry/idempotency rather than treating `send_at` as occurrence identity.
- Campaign presets own journey/order/timing/template references.
- Campaign presets do not own payload/copy and do not override payload/copy.
- Campaign preset variants reference Messaging templates with first-class key, dispatch_key, channel, purpose, and scope keys.
- Do not use meta.message for new Campaign preset step message references.
- Campaign messages resolve by:
  messaging.{channel}.definitions.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
- FlowRoute presets own automation/control-flow routing.
- Webinar post-event config owns provider orchestration, not message copy.
- Webinar schedule profile configs own webinar message timing/slot identity, not message copy.
- Schedule profile items may share generic Messaging message types such as `reminder`; use the schedule-profile item key for lifecycle-slot identity and stable `message_template_key` for reusable template identity. `source_config_path` is provenance/debug location only. Do not invent schedule-specific message types such as `reminder_30_minute`.
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
- Authoring UI may expose tokens as friendly available fields, but the field must still come from a documented runtime payload/provider/registry.
- Commerce/Location requests should improve admin/client convenience; do not turn Engage Core into a storefront, checkout, GIS, routing, or map product.
- If a request needs module behavior beyond config, identify the owning module and list required code/seam work separately.

Canonical contact alias rule:
- Internal/runtime/config identifiers use `contact`.
- Client-facing field pickers and copy may expose aliases based on the configured industry noun, such as `fan_first_name` or `lead_first_name`.
- Those aliases must resolve to canonical fields such as `contact.first_name`.
- Do not create separate runtime fields, schema, events, routes, or preset keys for each client-facing noun.

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

