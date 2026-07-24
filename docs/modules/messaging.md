# Messaging Module

## Config and token contracts

Email, SMS, and permission-invitation definitions are covered by registered closed config
contracts. Messaging registers producer contexts in `TokenContractRegistry`; module-specific
sources remain owned by their producer modules.

`MessageTemplateTokenValidator` is the canonical reusable validator for authorable Messaging
copy. Config/setup validation, MessageTemplatePreset sync, and CRM template create/update paths
reuse it so unknown or registered-but-unavailable tokens fail consistently for the exact producer
context.

MessageTemplatePreset assignment resolution uses semantic message/variant identity. A
variant-specific request does not silently fall back to a broad step assignment, and a
context-specific active assignment outranks a global assignment. `source_config_path` may be
retained on synced rows as diagnostics/provenance, but it is excluded from assignment identity,
definition-key inference, dispatch criteria, and Campaign template selection. Field pickers and
strict validation must query the token registry rather than infer availability from template text.

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Messaging is a reusable capability module.

Messaging owns outbound and scheduled message infrastructure.

Messaging owns:

- scheduled messages
- message consents
- consent-domain resolution
- consent acknowledgement resolution
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

## Reusable template vs resolved behavior contract

Messaging templates are reusable content and delivery-template definitions. They are not workflow engines.

The durable ownership split is:

```text
Messaging template / MessageTemplatePreset
    reusable copy
    payload class
    queue
    channel/purpose/scope/message identity
    dispatch identity
    token metadata
    template provenance

Owning module
    whether the message should exist
    exact lifecycle timing
    conditions/eligibility owned by that lifecycle
    sequencing and dependencies
    enablement
    module-specific skip behavior
```

For module-owned flows, reusable Messaging templates must not own competing `timing`, `schedule`, `conditions`, lifecycle enablement, sequencing, dependencies, or module-specific skip rules.

The universal runtime assembly seam is:

```text
Owning module resolves behavior
    -> ResolvedMessageDispatchBuilder
    -> ResolvedMessageDispatch
    -> Messaging gating / persistence / queueing / provider delivery
```

`ResolvedMessageDispatchBuilder` is Messaging-owned and generic. It combines reusable template data with behavior already resolved by the caller. It must not query or interpret Webinar, Campaign, Broadcast, FlowRoute, Task, InternalNotifications, or vertical-module tables.

`ResolvedMessageDispatch` is the normalized final dispatch contract. It contains the exact resolved `send_at`, optional polymorphic `behaviorOwner` provenance, optional stable `occurrenceKey` identity, and the generic information required for Messaging to apply delivery safety and persist a `ScheduledMessage`.

`MessageSendTimeResolver` may return a timezone-aware client-local value because calendar behavior such as `next_day_at` belongs to the client's timezone. `ScheduleMessageAction` is the persistence boundary: it converts that instant to UTC before both database persistence and queue delay registration. The normalized instant is stored once in `scheduled_messages.send_at`; `ScheduledMessage.meta` does not duplicate source or normalized scheduling timestamps. Horizon `send_at` metadata is an ISO-8601 UTC timestamp with an explicit offset; offset-free diagnostic timestamps are not allowed.

The builder accepts an optional polymorphic `behaviorOwner` model. When present, `ScheduledMessage` persists that provenance through `behavior_owner_type` / `behavior_owner_id`. Messaging stores the morph generically and does not import concrete feature-module models to understand their behavior.

Examples:

```text
Webinar message
    behavior owner = WebinarScheduleProfileItem

Campaign message
    behavior owner = CampaignStepVariant

Broadcast message
    behavior owner = Broadcast

FlowRoute send_message
    behavior owner = FlowRoutePoint
```

Not every message requires a behavior-owner record. The morph is provenance, not a requirement that every module adopt a profile/profile-item table pair.

Missing module-owned behavior must never silently fall back to hidden timing or conditions from a reusable template. A consuming module should either resolve a valid dispatch intent or safely decline/fail according to its explicit runtime and setup-validation contract.

There is no implicit immediate fallback. A resolved dispatch must provide either:

```text
exact caller-owned sendAt
or
explicit caller-owned behavior
```

Stable logical occurrence identity is separate from the scheduled timestamp. Module-owned dispatch paths should provide a stable `occurrenceKey` for retry/idempotency identity. The same logical occurrence should keep the same occurrence key even when `send_at` changes during a retry or recalculation. Messaging may use that occurrence identity when building dedupe keys; `send_at` alone must not be treated as logical occurrence identity.

Reusable template data is content-only at the builder boundary. `ResolvedMessageDispatchBuilder` rejects behavior fields such as `timing`, `schedule`, `conditions`, and module-specific skip behavior when they arrive on the reusable template itself. Caller-owned behavior is merged only through the explicit behavior input.

Some consuming-module resolvers may attach transient runtime-only keys such as `resolved_behavior` and `behavior_owner` to a resolved definition before calling `DispatchMessageAction`. `DispatchMessageAction` consumes and removes those transient keys before passing the reusable template into `ResolvedMessageDispatchBuilder`; they are not reusable template fields and must not be persisted as template ownership.


Resolved lifecycle conditions are planning-time behavior and must also survive to send time. `DispatchMessageAction` persists resolved `conditions` into `ScheduledMessage.meta.conditions`, and `ScheduledMessageGate` re-evaluates them immediately before provider delivery. A delayed message that no longer satisfies its conditions is skipped rather than sent merely because it was valid when originally scheduled.


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

`MessageTemplatePreset` owns reusable message content and delivery-template metadata. It does not own module lifecycle behavior.

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

For config-defined reusable templates, `MessageTemplatePreset.key` is durable identity. List-based definitions must declare an explicit stable `key`; list position must never determine durable template identity because inserting or reordering sibling definitions would otherwise retarget customized DB-owned templates. `source_config_path` remains provenance/debug location and may change independently of durable template identity.

A singular named definition may continue to derive its preset key from its stable named config path when no explicit key is supplied.

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
```

The semantic tuple keeps variant-specific assignments distinct even when variants share the same
Campaign key, step number, dispatch key, and broad message type. A synced assignment may record
`source_config_path` for diagnostics, but moving the template source must update provenance rather
than create a second runtime assignment.

Assignment changes should happen from the consuming module's setup surface, not primarily from the Messaging template copy editor.

Examples:

```text
Campaign step editor chooses which template each campaign step variant uses.
Webinar schedule/profile editor chooses which confirmation/reminder/follow-up template applies.
Routes / FlowRoutes send-message Point editor chooses which message template the Point sends.
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
4. Canonical config definition resolved from stable semantic identity only where explicitly supported by the resolver.
```

Long-term runtime should be DB-first. Config should seed/update available presets and catalog entries; it should not remain the only runtime source of reusable message copy.

DB-first template resolution does not make Messaging the owner of consuming-module behavior. After the reusable template is selected, the consuming module remains authoritative for its own timing, conditions, sequencing, dependencies, enablement, and skip behavior before `ResolvedMessageDispatchBuilder` assembles the final runtime contract.

A resolved definition's `config_path` and a ScheduledMessage's `definition_config_path` are provenance only. Planning evaluates the resolved definition's enabled state before persistence. Send-time gating uses the persisted plan, conditions, consent, destination, and suppression state; it must not re-open a physical config path, because moving a source file must not invalidate already planned delivery.
 Disabling future planning does not silently rewrite pending work; an owning module that must stop existing deliveries uses an explicit cancellation or skip operation, such as Campaign deactivation.


For Campaign runtime contexts, assignment and fallback resolution must include the variant key when variants are involved:

```text
channel + purpose + scope + campaign_key + campaign_step + campaign_step_variant_key
```

Step-only Campaign assignment wording is legacy compatibility language only. New Campaign template references and assignments should be variant-aware.

### Sync/customization rule

Sync may create or update non-customized presets and catalog entries from config.

Sync should not overwrite customized DB copy unless a force option is explicitly used.

For config-owned presets, normal sync should reconcile stale definitions after the complete current definition set has been collected and validated:

```text
current config-owned preset
    keep/sync

stale config-owned non-customized preset
    remove

stale config-owned customized preset
    preserve

manual/non-config-owned preset
    preserve
```

This cleanup is important when durable template identity changes, such as moving list-based definitions away from positional config-path keys.

Assignments and catalog entries that belong only to a removed stale preset may be removed through their preset relationship. Do not globally purge manual or customized templates.

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

Reusable copy includes campaign nurture messages, webinar confirmation/reminder/post-event messages, waitlist messages, and internal notification payload templates. Consent acknowledgements are resolved separately from consent-domain configuration rather than authored as per-scope `opt_ins` groups inside reusable Webinar definition files.

Campaigns, Webinars, and FlowRoutes may reference Messaging templates or assignments, but they should not become the primary home for reusable subject/body/message copy.


Reusable config-defined Messaging templates live only under:

    messaging.{channel}.definitions.{purpose}.{scope}

The `definitions` envelope is the canonical boundary between reusable templates and channel infrastructure. Channel-level settings such as `messaging.email.provider`, `messaging.email.providers`, `messaging.email.from`, and `messaging.sms.inbound` remain outside it and must not be inferred to be message definitions.

Campaign-owned message templates live inside Messaging configs under:

    campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}

Those campaign message templates are resolved by:

    channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key

Campaign presets should not duplicate reusable message copy.

Campaign presets should not define or override payloads.

Campaign presets own journey identity, step order, and step timing.

Messaging owns the delivery template for the campaign step.

Post-webinar transactional follow-ups should use the same reusable Messaging definition shape as confirmations, reminders, and campaign message templates. Consent acknowledgements use the separate consent-domain resolver.



### Consent domains and opt-in acknowledgements

Message scope and consent identity are intentionally separate:

```text
Message identity
    channel + purpose + scope

Consent identity
    channel + purpose + consent domain
```

`ConsentDomainRegistry` maps a precise message scope to the consent domain that authorizes it.

Resolution rules:

```text
1. exact scope mapping wins
2. otherwise the longest matching registered prefix wins
3. equal-specificity ambiguity fails loudly
4. unknown unmapped scopes fall back to themselves
```

The narrow fallback is deliberate. An undeclared scope must never accidentally inherit broader consent.

Current Webinar example:

```text
message scopes
    webinar
    webinar_waitlist
    webinar_nurture

consent domain
    webinar
```

The Webinar domain declares exact `webinar` coverage plus the `webinar_` prefix, so future scopes such as `webinar_reengagement` can share the same domain without per-scope consent wiring when that broadening is intentional.

`GrantMessageConsentAction`, `ImportMessageConsentAction`, `RevokeMessageConsentAction`, and `MessageGate` normalize through the consent-domain registry. The existing physical `scope` column on `message_consents` and `consent_revocations` stores the resolved consent-domain key; no schema rename is required merely to express the new semantic layer.

`ConsentOptInDefinitionResolver` owns consent acknowledgement resolution. Generic acknowledgement copy comes from Messaging config and receives human-readable domain context such as:

```text
client name
consent topic
channel
purpose
```

Owning modules/domains supply human-readable topics, for example:

```text
webinars and webinar follow-up
```

Module/client config may override acknowledgement copy for a consent domain. Do not inject raw technical scope keys into customer-facing acknowledgement text.

System markers such as `:client_name` and `:consent_topic` are resolved by the consent acknowledgement path. They are not ordinary `{token}` values and must not be exposed as authorable Messaging tokens unless `TokenContractRegistry` explicitly registers them.

Do not add scope-specific Webinar `opt_ins` groups such as:

```text
transactional:webinar opt_ins
marketing:webinar_waitlist opt_ins
marketing:webinar_nurture opt_ins
```

Normal consent granting may emit `MessageConsentGranted` and resolve an acknowledgement. Imported consent uses `ImportMessageConsentAction` specifically so imported state is normalized without emitting the grant event or sending an opt-in acknowledgement.


#### Consent acknowledgement delivery consolidation

Messaging may deliver a consent acknowledgement as a standalone message or consolidate it into a compatible lifecycle message under an explicit Messaging-owned delivery policy.

Delivery consolidation must preserve separate intent identity even when several intents share one physical `ScheduledMessage`.

A consolidation policy should define:

```text
primary lifecycle intent
eligible acknowledgement intents
channel compatibility
composition/placement behavior
standalone fallback behavior
```

Verified Webinar registration behavior currently follows this shape:

```text
Email registration confirmation
    + transactional Webinar email acknowledgement
    + marketing email acknowledgement

SMS registration confirmation
    + transactional Webinar SMS acknowledgement

Marketing SMS acknowledgement
    standalone when it is not compatible with the transactional confirmation
```

Only newly active consent grants should be considered for acknowledgement delivery. Repeated submissions that do not create a new active transition must not create duplicate acknowledgement messages.

A consolidated scheduled message should retain compact audit provenance in:

```text
meta.delivery_consolidation.primary_intent_key
meta.delivery_consolidation.intent_keys
meta.delivery_consolidation.consent_ids
meta.delivery_consolidation.policy
meta.delivery_consolidation.group
```

The consolidated acknowledgement inherits the primary lifecycle message's resolved `send_at`, queue, conditions, and behavior-owner provenance. Consolidation does not imply immediate delivery. For example, an acknowledgement attached to a delayed Webinar confirmation sends with that confirmation.

Any grant not covered by a successfully resolved consolidated message must follow the policy's explicit standalone fallback. A missing or unschedulable primary lifecycle message must never silently discard a required acknowledgement.

Reserved `delivery_consolidation_*` composition placeholders are supplied only by the Messaging consolidation path. They are not universal authorable tokens and must not be treated as ordinary `TokenContractRegistry` fields.

Readiness should evaluate whether each required acknowledgement has a valid delivery path. A zero count of standalone opt-in templates is not itself a readiness failure when the acknowledgement is covered by consolidation with a valid fallback.


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




## ScheduledMessage persistence contract

`ScheduledMessage` should persist enough immutable execution state to send, retry, deduplicate, explain, and audit a delivery without becoming a serialized copy of the surrounding application object graph.

First-class columns should answer:

```text
who receives the message
what domain record it is about
which behavior owns the timing
channel / purpose / scope / message type
when it should send
current delivery state and attempts
provider outcome
dedupe / occurrence identity
skip or failure reason
```

`payload` should contain provider-ready content plus only the minimal late-bound values required for deterministic delivery.

`meta` should contain compact scheduling and domain provenance such as:

```text
resolved conditions
intent keys
consent IDs
delivery-consolidation coverage
template/assignment identity
Campaign, Webinar, FlowRoute, and automation provenance
```

Delivery status, provider outcomes, retry facts, recovery facts, and provider evidence do not belong in `ScheduledMessage.meta`.

Do not persist the same Contact, Webinar, registration, Campaign, Route, Task, or other model snapshot repeatedly under top-level payload fields, `tokens`, `context`, and metadata. Do not persist loaded relationship graphs merely because `toArray()` is convenient.

Raw provider payloads belong only in columns explicitly designed as raw provider snapshots. A runtime producer that violates this contract should be treated as persistence debt to correct through the system-wide model creation and persistence audit, not as an accepted UX tradeoff.

### Delivery claims, attempts, and stale recovery

`pending` to `sending` is a leased claim, not a permanent ownership transfer. Every claim has a unique claim token and explicit expiry. Terminal writes and retry release must present the active claim token so an expired worker cannot overwrite a later worker's outcome.

Each claim creates a `scheduled_message_delivery_attempts` row. Attempt history is separate from the customer-visible terminal state on `scheduled_messages`; it records claim, provider-submission, release, recovery, and terminal outcome facts.

Current delivery state is owned by first-class `scheduled_messages` columns:

```text
status
sending_at
claim_token
claim_expires_at
provider_submission_started_at
recovered_at
last_attempted_at
send_attempts
sent_at
skipped_at
failed_at
provider
provider_message_id
skip_reason
failure_reason
provider_idempotency_key
```

Per-attempt diagnostics are owned by `scheduled_message_delivery_attempts`, including the claim token, attempt number, provider idempotency key, claim/submission/completion timestamps, attempt status, provider identity, provider message ID, reason code, reason, and provider-specific evidence in attempt `meta`.

`ScheduledMessage.meta.delivery` is not a runtime persistence contract. Delivery completion, retry release, and stale-claim recovery must preserve the message's existing canonical metadata without adding delivery or recovery structures. Explicit import canonicalization discards legacy `meta.delivery` rather than promoting it.

The provider idempotency key identifies one logical ScheduledMessage delivery and remains stable across attempts. `SendScheduledMessageJob` injects the column-backed key only into the in-memory provider payload; it is not persisted in `payload` or `meta`. Providers with a verified idempotency contract may safely receive the same key after an ambiguous stale submission only inside the configured provider retention window. Resend receives this key through its supported idempotency header and uses a conservative retry window below its documented 24-hour retention. A provider without a verified idempotency guarantee, or a claim recovered after that guarantee expired, must not be retried automatically after submission began and the outcome became ambiguous; the ScheduledMessage becomes visibly failed for operator review instead of risking a duplicate.

Messaging schedules stale-claim recovery every minute. An expired pre-submission claim is returned to `pending`, marked for recovery dispatch, and repeatedly eligible for recovery dispatch until a new worker successfully claims it. Recovery facts are recorded on the message's recovery/state columns and the completed attempt row. Recovery never rewrites an existing terminal outcome or adds diagnostics to ScheduledMessage metadata.

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

### Routes message usage

FlowRoutes may use Messaging through public Messaging actions/services for `send_message` Points.

Template choice belongs on the consuming Route setup surface, not primarily on the Messaging template copy editor.

The current Route editor uses explicit direct-Route eligibility. A reusable Messaging template is eligible only when:

```text
MessageTemplatePreset.meta.route_authoring.eligible = true

or

active MessageTemplateCatalogEntry.meta.route_authoring.eligible = true
```

and:

```text
the template is active
it has at least one dispatch key
its purpose is not internal
```

Internal-purpose templates are never eligible for direct Route authoring.

This prevents webinar confirmations, webinar reminders, Campaign-step templates, permission invitations, internal notifications, and other lifecycle-owned templates from leaking into the generic Route message picker merely because they are active.

When no direct-Route-eligible Messaging template exists, the Route editor should hide the `Send message` capability.

The backend must validate the same eligibility rule so direct requests cannot bypass the authoring boundary.

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


### Permission invitation cancellation, skip, and failure bookkeeping

Permission invitation lifecycle state remains:

```text
claimed
sent
failed
accepted
```

Scheduled-message delivery may also be `skipped`, but Messaging does not need matching `skipped` or `cancelled` invitation statuses.

The durable rules are:

```text
pre-claim scheduled-message skip or cancellation
    no ContactPermissionInvitation row is created

post-claim ScheduledMessageSkipped
    matching claimed ContactPermissionInvitation becomes failed

provider/runtime failure after claim
    ScheduledMessage becomes failed
    matching invitation becomes failed

successful send
    ScheduledMessage becomes sent
    invitation becomes sent

later public acceptance
    ScheduledMessage stays sent
    invitation becomes accepted
```

Messaging owns reconciliation from `ScheduledMessageSkipped` to a matching claimed permission invitation. Reconciliation must match by `scheduled_message_id` and only transition `claimed` invitations so existing sent, failed, or accepted invitation rows remain untouched.

Failed invitation rows continue to enforce the current one-time invitation rule. Automatic reissue is not supported.

Accepted imported-contact permission invitations emit the neutral automation event:

```text
permission_invitation.accepted
```

The acceptance transaction owns consent creation, invitation accepted state, accepted channels, and any submitted SMS phone update. The invitation row should be locked and accepted state rechecked inside the transaction so concurrent submissions cannot emit duplicate acceptance events.

After the acceptance transaction succeeds, Messaging emits `AutomationEventRecorded` with the invitation as subject and compact accepted-channel/consent-scope context.

Shared Automation Opportunities infrastructure may independently retain `permission_invitation.accepted` as evidence-only correlation data. That does not make Messaging responsible for opportunity qualification or suggestion UX, and the evidence row does not create a standalone opportunity by itself.

Messaging must remain independent from consumers. It must not import FlowRoutes or decide downstream status changes, tasks, campaigns, notifications, or vertical behavior.

## Available field/token picker support

Messaging owns universal Contact-recipient message fields and the reusable template/runtime validation path.

The implemented executable source of truth is:

```text
TokenSourceProvider
TokenContextProvider
ComputedTokenValueProvider
TokenContractRegistry
MessageTemplateTokenValidator
```

Client/operator editors should eventually expose an `Insert field` / `Add field` interaction instead of requiring users to memorize token syntax, but the picker must consume the same context-aware registry and validator used by server-side authoring paths.

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

Message template validation applies to DB-customized templates as well as config-synced templates. Editing copy in CRM/admin UI does not make an unsupported token valid. Config/setup validation, MessageTemplatePreset sync, and CRM template create/update paths should all use `MessageTemplateTokenValidator` rather than maintaining separate token parsing or allowlists.


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

Messaging contributes Messaging-owned checks through `MessagingSetupValidationContributor`, adapting the existing `MessageConfigValidator` instead of placing Messaging-specific logic directly in a global command.

`MessageConfigValidator` delegates authorable token checks to `MessageTemplateTokenValidator`, which resolves allowed tokens from `TokenContractRegistry` for the exact producer context. The same validator is reused by MessageTemplatePreset sync and CRM template create/update validation.

Messaging setup validation covers current email/SMS config routes, customized DB-owned `MessageTemplatePreset` records, active assignments, unsupported channel/purpose values, incomplete assignment context/campaign identity, inactive or missing presets, exact active-assignment ambiguity using the runtime identity dimensions, and consent-domain configuration ambiguity.

Messaging validation should cover, as applicable:

```text
canonical reusable template definition shape
dispatch key validity
channel/purpose/scope validity
payload class availability
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