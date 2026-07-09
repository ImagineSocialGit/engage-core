

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

## Message template presets, catalog entries, and assignments

Messaging template presets are the DB-owned form of reusable message definitions.

They let config-defined message copy be synced into the database and edited safely without moving reusable copy into Campaign, Webinar, FlowRoute, Broadcast, or other consuming-module internals.

Messaging owns:

```text
message_template_presets
message_template_catalog_entries
message_template_preset_assignments
```

These three records have distinct responsibilities:

```text
MessageTemplatePreset
    reusable message copy and delivery-template fields

MessageTemplateCatalogEntry
    Messaging-owned catalog/read organization for browsing grouped templates

MessageTemplatePresetAssignment
    selected preset for a runtime message context
```

### MessageTemplatePreset

`MessageTemplatePreset` owns reusable message content and delivery-template fields.

Durable fields include:

```text
id
key
name
description nullable
channel
purpose
scope
message_type nullable
payload_class
queue nullable
dispatch_keys json nullable
timing
schedule json nullable
conditions json nullable
payload json
tokens json nullable
status / is_active
source nullable
source_config_path nullable
source_version nullable
is_customized boolean
customized_at nullable
last_synced_at nullable
meta json nullable
timestamps
```

Examples:

```text
Email / Transactional / Webinars / Registration Confirmation
Email / Transactional / Webinars / 30-Minute Reminder
Email / Marketing / Campaigns / Webinar Attended Nurture / Step 1
SMS / Marketing / Campaigns / Webinar Attended Nurture / Step 1
```

Template names should be readable catalog labels, not raw config paths.

### MessageTemplateCatalogEntry

`MessageTemplateCatalogEntry` owns how a template appears in the Messaging template browser/catalog.

It is a read-organization model, not a runtime behavior model.

It should support browsing and filtering by:

```text
channel
purpose
scope
module_key / module_label
surface
group_key / group_label
item_key / item_label / item_order
usage_type
source / source_config_path
context_type / context_id nullable
is_active
meta json nullable
```

Examples:

```text
Email -> Marketing -> Campaigns -> Webinar Attended Nurture -> Step 1 Email
Email -> Transactional -> Webinars -> Webinar Reminders -> 30-Minute Reminder Email
SMS -> Marketing -> Campaigns -> Webinar Missed Nurture -> Step 1 SMS
```

A catalog entry does not decide which template sends, when it sends, or why it sends.

It should not own:

```text
campaign timing
webinar reminder schedules
FlowRoute trigger behavior
skip rules
conditions that change runtime behavior
channel strategy
```

Those behaviors remain with the owning runtime modules.

Catalog entries exist so Messaging can provide a clean copy-editing and review surface without forcing operators to hunt through Campaign, Webinar, FlowRoute, or config screens just to find message copy.

### MessageTemplatePresetAssignment

`MessageTemplatePresetAssignment` owns which preset is currently selected for a runtime message context.

Durable fields include:

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
campaign_step_variant_key nullable
source_config_path nullable
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
campaign + webinar_attended_nurture + step 1 + variant email
webinar + reminder_30_minute + email + global default
webinar + post_attended + sms + WebinarSeries:5
FlowRoute send-message point + selected transactional email template
```


Campaign-specific assignments should include stable variant identity when variants are involved:

```text
channel
purpose
scope
surface = campaigns
campaign_key
campaign_step
campaign_step_variant_key
source_config_path
```

`source_config_path` should point at the concrete template source, such as:

```text
messaging.email.marketing.webinar_nurture.campaigns.webinar_attended_nurture.steps.1.variants.email
```

This keeps variant-specific assignments distinct even when variants share the same campaign key, step number, dispatch key, and broad message type.

Assignment changes should happen from the consuming module's setup surface, not primarily from the Messaging template copy editor.

Examples:

```text
Campaign step editor chooses which template each campaign step variant uses.
Webinar schedule/profile editor chooses which confirmation/reminder/follow-up template applies.
Automatic Follow-ups / FlowRoutes send-message point editor chooses which message template the point sends.
```

The Messaging template page may show read-only usage, such as "Used by Campaigns -> Webinar Attended Nurture -> Step 1 Email," and link to the owning module UI when that UI exists.

Current CRM/admin surfaces:

```text
Message Templates
    Messaging-owned copy review and copy editing.
    Browses catalog entries by channel, purpose, area/module, group, and message/step.
    Shows read-only usage and links to owning setup surfaces when available.

Campaign Message Templates
    Campaigns-owned setup surface for choosing the active Messaging template for campaign step variants.
    Links back to Message Templates for copy editing.
```

Webinar and Automatic Follow-up template selection should follow the same ownership split when their setup surfaces are implemented.

### Runtime resolution target

Messaging resolvers should eventually resolve message definitions in this order:

```text
1. Most specific active MessageTemplatePresetAssignment for the runtime context.
2. Less-specific active assignment for the same channel/purpose/scope/message context.
3. Synced default MessageTemplatePreset.
4. Variant-specific config seed/source fallback only where explicitly supported by the resolver.
```

Long-term runtime should be DB-first. Config should seed/update available presets and catalog entries; it should not remain the only runtime source of reusable message copy.


For Campaign runtime contexts, assignment and fallback resolution must include the variant key when variants are involved:

```text
channel + purpose + scope + campaign_key + campaign_step + campaign_step_variant_key
```

Step-only Campaign assignment wording is legacy compatibility language only. New Campaign template references and assignments should be variant-aware.

### Sync/customization rule

Sync may create or update non-customized presets and catalog entries from config.

Sync should not overwrite customized DB copy unless a force option is explicitly used.

Catalog entries are regenerated from source definition context and should stay aligned with the source config/definition shape.

Customized template payload fields remain Messaging-owned and token-validated.

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

    campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}

Those campaign message templates are resolved by:

    channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key

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




## FlowRoutes-created scheduled message provenance

Messaging owns scheduled message delivery, recipient/context morphs, consent, suppression, gates, payloads, scheduling, and send lifecycle.

When FlowRoutes creates or dispatches a message through Messaging public actions/services, the resulting `scheduled_messages` record should keep Messaging's canonical recipient/context shape and also store structured FlowRoutes provenance where applicable:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
```

Do not add `subject_type` / `subject_id` to `scheduled_messages`; `context_type` / `context_id` remains the canonical scheduled-message about-this-record relationship. FlowRoutes subject context belongs on the route progress/plan/progress item and may be available through provenance relationships.

This makes route-created messages queryable and resumable without making Messaging import FlowRoutes execution internals.

### Automatic Follow-ups message usage

FlowRoutes may use Messaging through public Messaging actions/services for send-message points.

Automatic Follow-ups UI may eventually allow an operator to choose which Messaging template assignment a send-message point uses. That selection belongs on the consuming setup surface, not primarily on the Messaging template copy editor.

Before implementing that UI, decide whether the first Automatic Follow-ups surface edits send-message points at all or only previews existing selected routes.

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

## Available field/token picker support

Messaging owns universal Contact-recipient message fields and the reusable template/runtime validation path.

Client/operator editors should eventually expose an `Insert field` / `Add field` interaction instead of requiring users to memorize token syntax.

Messaging should not become the owner of every module-specific field.

Preferred ownership:

```text
Messaging contributes universal Contact/recipient fields.
Webinars contributes webinar fields.
Tasks contributes task fields.
Documents contributes document fields.
Forms contributes form fields.
Commerce contributes commerce fields.
Vertical modules contribute vertical subject fields.
```

Potential public seam to audit:

```text
AvailableFieldProvider
AvailableFieldRegistry
AvailableFieldContext
AvailableFieldOption
```

Message template validation should apply to DB-customized templates as well as config-synced templates. Editing copy in CRM/admin UI does not make an unsupported token valid.


## Canonical available fields and client-facing aliases

Messaging and shared authoring infrastructure should distinguish canonical internal field identity from client-facing alias vocabulary.

Canonical runtime identity should remain universal, for example:

```text
contact.first_name
contact.last_name
contact.email
```

A client-facing editor may expose aliases based on the configured Contact noun, for example:

```text
lead_first_name
fan_first_name
customer_first_name
```

Those aliases are authoring/display conveniences only. They should normalize to the canonical internal field before storage validation/rendering or through another single documented normalization seam.

Do not create separate token registries, payload fields, database columns, or runtime branches for Lead, Fan, Customer, Borrower, Owner, and similar presentation nouns when they all represent Core Contact fields.

The available-field registry/provider should be able to expose:

```text
canonical key
client-facing label
accepted authoring aliases
owning module/provider
available contexts
runtime source
```

A client-facing alias must never be offered unless it resolves unambiguously to a canonical field the runtime context can actually supply.

## Messaging setup validation ownership

Messaging should contribute Messaging-owned checks to the shared app-level setup validation manager instead of placing Messaging-specific logic directly in a global command.

The existing `MessageConfigValidator` should be reused/adapted rather than duplicated.

Messaging validation should cover, as applicable:

```text
canonical message definition shape
dispatch key validity
channel/purpose/scope validity
payload class availability
schedule/timing shape
required payload fields
unresolved or undeclared tokens/available fields
context-specific field availability
client-facing alias normalization to canonical fields
runtime-only URL availability
DB-customized MessageTemplatePreset payloads
MessageTemplatePresetAssignment compatibility
campaign variant template contexts
webinar message/template contexts
channel availability for relevant authoring surfaces
```

Hard errors should represent configuration that cannot safely execute or render. Warnings should represent safe but dormant, unused, unavailable, or potentially surprising setup.

Messaging validation findings should be reusable by:

```text
setup:validate CLI output
staging/client handoff checks
Message Template editing UI
future Campaign/Webinar/Route authoring surfaces
available-field/token picker UX
```

No persistent validation-result tables are required unless a later operator workflow proves retained history or acknowledgement state is needed.

Fields should be filtered by authoring/runtime context so operators cannot insert a field that will be unavailable when the message sends.
