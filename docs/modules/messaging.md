# Messaging Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Messaging is a reusable capability module.

Messaging owns outbound and scheduled message infrastructure.

Messaging owns:

- scheduled messages
- message consents
- consent revocations
- message suppressions
- contact permission invitations
- imported-contact one-time opt-in invitation records
- public preference confirmation pages for Messaging consent
- email/SMS provider contracts
- provider managers
- message payloads
- message gates
- eligibility checks
- send guards
- dispatch actions
- schedule actions
- scheduled message jobs
- public opt-out/unsubscribe controllers
- message-related events
- recipient gate extension points
- recipient payload extension points

Messaging does not own:

- inbound webhook normalization/routing
- TeamMember models
- internal notification preferences
- webinar registrations
- campaigns
- FlowRoutes
- task assignment

Other modules may use Messaging through public actions/services/contracts.

Messaging definitions should use a consistent canonical definition shape across config files and DB-adapted inline definitions.

Canonical message definition shape:

    dispatch_key
    message_type
    channel
    purpose
    scope
    timing
    queue
    payload_class
    conditions
    schedule
    payload
    meta

A definition may omit fields that are inferable from caller context, but adapters should normalize into this shape before calling Messaging runtime actions.

Messaging definitions are reusable templates.

## Message template presets and assignments

Messaging template presets are the DB-owned form of reusable message definitions.

They let config-defined message copy be synced into the database, selected by CRM/admin UI, and edited safely without moving reusable copy into Campaign, Webinar, FlowRoute, or Broadcast internals.

Messaging owns:

```text
message_template_presets
message_template_preset_assignments
```

### MessageTemplatePreset

`MessageTemplatePreset` owns reusable message content and delivery-template fields.

Suggested durable fields:

```text
id
key
name
description nullable
channel
purpose
scope
payload_class
payload json
tokens json nullable
status / is_active
source nullable
source_version nullable
is_customized boolean
last_synced_at nullable
meta json nullable
timestamps
```

Examples:

```text
webinar_registration_confirmation.default
webinar_registration_confirmation.short_test
webinar_reminder.full_schedule_24h
webinar_nurture.attended.default_email_step_1
webinar_nurture.attended.default_sms_step_1
```

### MessageTemplatePresetAssignment

`MessageTemplatePresetAssignment` owns which preset is currently selected for a runtime message context.

Suggested durable fields:

```text
id
message_template_preset_id
channel
purpose
scope
surface nullable
message_type nullable
campaign_key nullable
campaign_step nullable
context_type nullable
context_id nullable
is_active
starts_at nullable
ends_at nullable
meta json nullable
timestamps
```

Assignments should support global/default selections and context-specific selections.

Examples:

```text
campaign + webinar_attended_nurture + step 1 + email
webinar + reminder_24h + email + WebinarSeries:5
webinar + post_attended + sms + global default
```

### Runtime resolution target

Messaging resolvers should eventually resolve message definitions in this order:

```text
1. Most specific active MessageTemplatePresetAssignment for the runtime context.
2. Less-specific active assignment for the same channel/purpose/scope/message context.
3. Synced default MessageTemplatePreset.
4. Temporary config fallback only during migration.
```

Long-term runtime should be DB-first. Config should seed/update available presets; it should not remain the only runtime source of reusable message copy.

### Sync/customization rule

Sync may create or update non-customized presets from config.

Sync should not overwrite customized DB copy unless a force option is explicitly used.

Customized fields should remain Messaging-owned and token-validated.


### Messaging channel availability

Messaging owns the canonical channel availability seam.

Channel availability answers whether a channel is:

- runtime-supported
- provider-enabled
- visible for a specific client/admin surface
- allowed for a specific purpose/scope
- explicit-opt-in only

Client/admin surfaces should not read raw SMS/provider config directly.

Surfaces such as Broadcasts, Campaign builders, webinar registration, permission invitation pages, internal notifications, and Route send-message points should ask Messaging’s channel availability service which channels are available for that surface.

Canonical channel availability surface keys are:

- `broadcasts`
- `campaigns`
- `permission_invitations`
- `webinar_registrations`
- `webinar_waitlists`
- `internal_notifications`
- `route_send_message_points`

Surface keys describe UI/admin/client channel-choice surfaces.

They should not replace singular Messaging scopes, sources, message types, consent policy keys, token contexts, or payload/context keys.

Hiding SMS from a surface does not disable SMS runtime safety behavior.

SMS provider integrations, consent gates, revocations, suppressions, STOP/HELP handling, and send guards remain backend/runtime concerns.

Messaging owns reusable message copy and delivery templates, including subject/body/CTA payloads.

Reusable copy includes campaign nurture messages, webinar confirmation/reminder/post-event messages, waitlist messages, opt-in messages, and internal notification payload templates.

Campaigns, Webinars, and FlowRoutes may reference Messaging templates or assignments, but they should not become the primary home for reusable subject/body/message copy.


Campaign-owned message templates live inside Messaging configs under:

    campaigns.{campaign_key}.steps.{step_number}

Those campaign message templates are resolved by:

    channel + purpose + scope + campaign_key + step_number

Campaign presets should not duplicate reusable message copy.

Campaign presets should not define or override payloads.

Campaign presets own journey identity, step order, and step timing.

Messaging owns the delivery template for the campaign step.

Post-webinar transactional follow-ups should use the same Messaging definition shape as confirmations, reminders, opt-ins, and campaign message templates.


Good:

    DispatchMessageAction
    ScheduleMessageAction
    SkipScheduledMessagesAction
    GrantMessageConsentAction
    RevokeMessageConsentAction
    MessageRecipientGate
    MessageRecipientPayloadProvider

Avoid:

    ScheduledMessage::query()->create(...)

unless the code is inside Messaging itself or there is an intentional, documented exception.

Messaging must remain generic.

Messaging must not import InternalNotifications or InboundMessaging.

InternalNotifications can contribute TeamMember-specific behavior to Messaging through Messaging-owned extension points.

Current Messaging extension points include:

- `MessageRecipientGate`
- `MessageRecipientGateRegistry`
- `MessageRecipientPayloadProvider`
- `MessageRecipientPayloadProviderRegistry`

Messaging consent records are currently Contact-scoped.

That is intentional for external contact messaging consent.

Internal team notification preferences belong to InternalNotifications, not Messaging.

Generic recipient support for scheduled delivery lives in `scheduled_messages.recipient_type` / `scheduled_messages.recipient_id`.

Scheduled message domain context lives in `scheduled_messages.context_type` / `scheduled_messages.context_id`.

Meaning:

```text
recipient_type / recipient_id
    Who receives the scheduled message.

context_type / context_id
    What domain record the scheduled message is about or attached to.
```

Examples:

```text
Appointment reminder
    recipient = Contact
    context = Appointment

Document request reminder
    recipient = Contact
    context = DocumentRequest

Webinar reminder
    recipient = Contact
    context = WebinarRegistration or Webinar

Campaign step message
    recipient = Contact
    context = CampaignEnrollment

Task digest
    recipient = TeamMember
    context = TaskDigest or null batch context
```

Do not add separate `subject_type` / `subject_id` columns to `scheduled_messages` unless there is a deliberate future decision to replace the existing `context` morph. The existing `context` morph is the canonical scheduled-message "about this record" relationship.

Messaging may schedule messages for non-Contact recipients through recipient payload/gate extension points, but it should not own those recipient models or their preferences.


### Imported-contact permission invitations

Messaging owns the one-time imported-contact permission invitation capability.

This includes:

- `contact_permission_invitations`
- invitation token generation
- one-time invitation enforcement
- public preference confirmation route/controller
- public preference page consent recording
- accepted channel tracking
- runtime injection of preference URLs into invitation email payloads
- import-batch permission invitation scheduling action
- import-batch permission invitation eligibility checks
- duplicate pending/sent scheduled invitation protection

The invitation is not a general consent bypass.

Rules:

- The bypass applies only to the canonical imported-contact permission invitation message.
- The invitation send is email-only.
- The recipient must be an imported Contact.
- The invitation must use `message_type = imported_contact_permission_invitation`.
- The invitation must carry a `consent_policy.permission_invitation` payload with `source = imported_contact` and `one_time = true`.
- The system must refuse repeat invitations once a `contact_permission_invitations` row exists for the same contact/channel/source.
- The system must also refuse repeat scheduling when a pending or sent imported-contact permission invitation scheduled message already exists for the contact.
- Accepted public preferences create normal Messaging `MessageConsent` records for configured scopes.
- SMS consent must be explicitly selected by the contact and must not be inferred from email consent.

Current import-batch invitation scheduling is Messaging-owned.

Core may expose the operator entry point on the import batch detail page when Messaging is enabled, but Core must not directly import Messaging actions, services, or models.

Other modules may request this flow through Messaging public services/actions, but they must not create invitation records directly.
