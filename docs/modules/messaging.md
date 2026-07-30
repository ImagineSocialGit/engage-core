# Messaging Module

## Status

Messaging is a reusable capability module.

The versioned-template, versioned-chain, compact-scheduled-message architecture in this document is the approved target and is not yet fully implemented.

The current `MessageTemplatePreset`, assignment, catalog, Webinar schedule-profile, Campaign-step, Broadcast payload, and payload-bearing `ScheduledMessage` paths are transitional. Migrations and models should move first; runtime writers and UI should follow in incremental batches.

Detailed persistence rationale and acceptance targets are in [`../model-persistence-bloat-audit.md`](../model-persistence-bloat-audit.md).

## Responsibility

Messaging owns:

- outbound message templates and immutable template versions;
- reusable message chains and immutable chain versions;
- message-chain steps and channel variants;
- message-chain enrollments and progression;
- compact scheduled-message execution records;
- provider delivery attempts and terminal outbox events;
- recipient/destination gates;
- message consent, consent domains, revocations, and suppressions;
- consent acknowledgement resolution and delivery composition;
- contact permission invitations;
- email/SMS provider contracts and managers;
- renderer contracts;
- schedule, dispatch, skip, and send actions;
- recipient gate and recipient-value extension points;
- message-related lifecycle events.

Messaging does not own:

- the business event that starts a chain;
- Webinar registrations or attendance;
- Campaign audience/marketing identity;
- Broadcast recipient selection;
- FlowRoute progression;
- task assignment;
- inbound webhook normalization/routing;
- TeamMember models or internal-notification preferences.

Other modules use Messaging through public actions, services, contracts, registries, and neutral automation actions.

## Core ownership split

```text
MessageTemplate / MessageTemplateVersion
    immutable reusable copy and renderer identity

MessageChain / MessageChainVersion
    reusable message sequencing, timing, channel variants, dependencies, and exit rules

Owning module or FlowRoutes
    decides which business event starts which chain
    supplies recipient, context, and origin
    owns domain-specific cancellation or outcome meaning

MessageChainEnrollment
    owns one recipient's progression through one immutable chain version

ScheduledMessage
    owns one compact delivery execution

ScheduledMessageDeliveryAttempt
    owns one provider claim/submission/outcome attempt
```

A message chain is not a general trigger engine.

Webinars, Campaigns, Scheduling, Documents, Portal, FlowRoutes, and future modules remain authoritative for their domain events and bindings.

## Config and token contracts

Email, SMS, permission-invitation, and future message-chain seed definitions remain covered by registered closed config contracts.

`TokenContractRegistry` is the executable authority for fields that a producer context can supply.

`MessageTemplateTokenValidator` remains the canonical validator for authorable copy.

Use the same context-aware validation for:

```text
config/setup validation
template/version sync
CRM template editing
chain editing
available-field pickers
pre-publication validation
runtime rendering safety
```

Do not create a second token allowlist from template text, config reference files, caller-supplied arrays, or persisted metadata.

Client-facing aliases may normalize to canonical fields such as `contact.first_name`, but aliases do not create new runtime fields or schema.

## Message templates

### `message_templates`

A template is stable authoring identity.

Target fields:

```text
id
key
name
description nullable
channel
status
current_version_id nullable
source nullable
source_version nullable
is_customized
customized_at nullable
timestamps
```

A template does not own:

```text
purpose
scope
queue
Campaign key
Webinar slot
FlowRoute trigger
timing
conditions
sequence
dependency rules
business enablement
```

No generic `meta` column is planned.

Stable template identity must not depend on list order or physical config path.

### `message_template_versions`

A template version is immutable tokenized content.

Target fields:

```text
id
message_template_id
version
subject nullable
content json
renderer_key
renderer_version
content_hash
created_by nullable
created_at
```

`content` is bounded authored definition data stored once per version.

Examples:

```text
email
    body
    CTA
    secondary link
    footer

sms
    message
```

Token names are derived and validated from subject/content. Do not persist a duplicate `tokens` JSON list.

Editing creates a new version and updates `message_templates.current_version_id` for future selection.

Existing chains and scheduled messages remain pinned to their prior version IDs.

## Message chains

### `message_chains`

A chain is stable reusable sequence identity.

Target fields:

```text
id
key
name
description nullable
status
current_version_id nullable
source nullable
source_version nullable
is_customized
customized_at nullable
timestamps
```

No generic `meta` column is planned.

### `message_chain_versions`

A chain version is immutable execution behavior for future enrollments.

Target fields:

```text
id
message_chain_id
version
exit_conditions json nullable
content_hash
published_at nullable
created_by nullable
created_at
```

Existing enrollments do not move automatically when a chain is edited.

### `message_chain_steps`

A step is one ordered business moment.

Target fields:

```text
id
message_chain_version_id
key
name nullable
sort_order
timing_type
anchor_key nullable
offset_seconds
advance_policy
conditions json nullable
is_active
```

Do not persist a message count; derive it from active steps.

Do not copy step conditions into enrollments or scheduled messages.

### `message_chain_step_variants`

A variant is a channel-specific delivery option.

Target fields:

```text
id
message_chain_step_id
key
sort_order
message_template_version_id
channel
purpose
scope
message_type
queue nullable
dependency_policy json nullable
conditions json nullable
is_active
```

Small routing columns remain explicit because they are required by hot runtime gates and operational queries.

Reusable copy is referenced through the immutable template-version FK.

Supported strategy concepts remain:

```text
first_available
send_all_eligible
dependency_aware
```

The exact strategy may live on the step or its immutable version definition, but it must not be inferred from channel names or timing collisions.

## Chain enrollment

### `message_chain_enrollments`

One row represents one recipient moving through one immutable chain version.

Target fields:

```text
id
message_chain_version_id
recipient_type
recipient_id
context_type nullable
context_id nullable
origin_type nullable
origin_id nullable
current_message_chain_step_id nullable
next_action_at nullable
status
dedupe_key nullable
started_at
paused_at nullable
resumed_at nullable
exited_at nullable
exit_reason_code nullable
completed_at nullable
cancelled_at nullable
timestamps
```

Meanings:

```text
recipient
    who receives the chain

context
    what domain record supplies business/token context

origin
    which module record or FlowRoute progress item started it
```

The enrollment stores progression and lifecycle results.

It does not copy:

```text
chain definition
exit-condition definition
template content
start-context object graphs
scheduled-message IDs
debug history arrays
```

## Lazy materialization

Normal chain execution should materialize only the next actionable variant wave.

```text
start enrollment
    calculate next_action_at

step becomes due
    evaluate current conditions
    create eligible ScheduledMessages for this wave

wave reaches advance_policy
    advance enrollment
    calculate next_action_at
```

Do not create every future reminder/follow-up delivery merely because the chain contains those definitions.

Concurrent variants may be materialized together only when the chain strategy intentionally requires them.

## Scheduled-message persistence contract

### `scheduled_messages`

A scheduled message is a compact execution record.

Target fields:

```text
id
recipient_type
recipient_id
context_type nullable
context_id nullable
origin_type nullable
origin_id nullable

message_template_version_id
message_chain_enrollment_id nullable
message_chain_step_variant_id nullable

channel
purpose
scope
message_type
queue nullable

destination nullable
send_at
status
attempt_count
dedupe_key nullable
provider_idempotency_key nullable

sent_at nullable
skipped_at nullable
failed_at nullable
terminal_reason_code nullable

timestamps
```

No JSON columns are planned.

Remove from the target row:

```text
payload
dispatch_keys
definition_config_path
payload_class
conditions
template/assignment/profile/catalog snapshots
delivery-consolidation recipes
provider claim state
provider result state
generic meta
```

`message_template_version_id` pins immutable copy for both chain and direct sends.

`origin` is the generic creator/business-owner relationship.

`context` remains the about-this-record relationship.

`destination` is frozen no later than first provider submission. Pending messages may intentionally resolve the latest eligible destination at claim time.

The following small repeated values are deliberate operational columns:

```text
channel
purpose
scope
message_type
queue
status
attempt_count
```

They avoid deep joins in claim, gate, queue, and reporting queries.

### `scheduled_message_render_contexts`

Runtime token values are stored separately and lazily.

Target fields:

```text
id
scheduled_message_id unique
values json
content_hash
rendered_at
expires_at nullable
timestamps
```

The context contains only values referenced by the pinned template version and optional composed components.

Do not persist:

```text
whole Contact arrays
whole Webinar arrays
loaded relationships
config branches
duplicate tokens/context trees
template labels
module provenance
```

Messages without runtime tokens need no render-context row.

Retries reuse the same frozen render context.

Exact content is reconstructed from:

```text
immutable template version
+ renderer version
+ immutable render values
```

The separate table is justified because it is created only when rendering begins and has a distinct retention/archival policy.

### `scheduled_message_components`

Additional composed content uses small relational rows.

Target fields:

```text
id
scheduled_message_id
message_template_version_id
role
intent_key nullable
message_consent_id nullable
sort_order
placement_key nullable
timestamps
```

The primary template remains on `scheduled_messages`.

Component rows exist only for deliberate composition such as consent acknowledgements.

Covered intent keys and consent IDs must not be copied into a generic consolidation metadata recipe.

## Direct messages

A direct message does not need a chain enrollment.

Examples:

```text
one-off FlowRoute send
permission invitation
internal notification
one-time Broadcast recipient delivery
```

It still pins a `message_template_version_id`, stores compact recipient/context/origin identity, and follows the same gate/render/attempt lifecycle.

## Delivery claims, attempts, and stale recovery

### `scheduled_message_delivery_attempts`

Attempt rows own:

```text
scheduled_message_id
attempt_number
claim_token
status
claimed_at
lease_expires_at
provider_submission_started_at nullable
completed_at nullable
destination
provider nullable
provider_message_id nullable
reason_code nullable
reason nullable
timestamps
```

The scheduled-message row owns only terminal summary and the stable provider idempotency key.

Do not duplicate on both rows:

```text
claim token
claim expiry
provider
provider message ID
submission timestamp
attempt reason
```

`attempt_count` may remain on `scheduled_messages` as a small operational counter used by claim/retry policy.

An expired pre-submission claim may be returned to pending.

After provider submission begins, automatic retry requires a verified provider idempotency contract that is still within its retention window. Otherwise ambiguous delivery becomes visibly failed for operator review rather than risking a duplicate.

### Terminal outbox

Keep `scheduled_message_outbox_events` as a bounded one-row-per-terminal-event outbox.

It must not carry message content, render context, or module object snapshots.

## Current preset/assignment/catalog transition

Current tables:

```text
message_template_presets
message_template_preset_assignments
message_template_catalog_entries
```

are transitional.

Their current responsibilities overlap:

```text
content
delivery classification
config provenance
selection
Campaign/Webinar identity
UI catalog organization
customization
runtime fallback
```

Target migration:

```text
MessageTemplatePreset
    -> MessageTemplate + MessageTemplateVersion

MessageTemplatePresetAssignment
    -> direct FK references from immutable chain variants or owning-module bindings

MessageTemplateCatalogEntry
    -> derived read model from templates, chains, and usage relationships
       or a later narrow persisted projection only when measured UI/query needs justify it
```

Do not add another permanent assignment table merely to preserve the current resolver shape.

Do not persist catalog labels into runtime rows.

Config remains a seed/source mechanism. Runtime selection becomes DB-owned through template current versions, chain current versions, immutable chain variants, and module-owned bindings.

## Template and chain editing

### Template edit

```text
create new immutable version
validate tokens and renderer shape
update current_version_id
leave existing chain versions and ScheduledMessages unchanged
```

### Chain edit

```text
create new immutable chain version
copy small step/variant definitions
pin chosen template-version IDs
update current_version_id
leave existing enrollments unchanged
```

### Chain duplication

```text
create new chain identity
create one chain version
copy small step/variant rows
reuse existing template versions
copy template content only when the operator customizes it
```

This copy-on-write rule is required for Webinar-series chain duplication and general CRM authoring.

## Messaging channel availability

Messaging owns the canonical channel-availability seam.

Availability answers whether a channel is:

- runtime-supported;
- provider-enabled;
- visible for a specific client/admin surface;
- allowed for a purpose/scope;
- explicit-opt-in only.

Client/admin surfaces must not infer availability from raw provider config.

Current surface keys include:

```text
broadcasts
campaigns
permission_invitations
webinar_registrations
webinar_waitlists
internal_notifications
route_send_message_points
```

A future chain editor should use a plural surface key such as:

```text
message_chains
```

Hiding SMS from an authoring surface does not disable backend consent, suppression, STOP/HELP, provider, or send-guard behavior.

## Consent domains and opt-in acknowledgements

Message identity and consent identity remain separate:

```text
Message identity
    channel + purpose + scope

Consent identity
    channel + purpose + consent domain
```

`ConsentDomainRegistry` resolution remains:

```text
exact mapping wins
otherwise longest registered prefix wins
equal-specificity ambiguity fails loudly
unknown unmapped scope falls back to itself
```

Current Webinar message scopes may intentionally share the `webinar` consent domain.

`GrantMessageConsentAction`, `ImportMessageConsentAction`, `RevokeMessageConsentAction`, and `MessageGate` normalize through this registry.

Imported consent uses the dedicated import path so it does not emit a grant acknowledgement.

### Consent acknowledgement resolution

`ConsentOptInDefinitionResolver` owns acknowledgement copy and domain topic resolution.

System markers such as:

```text
:client_name
:consent_topic
```

belong to the acknowledgement resolver, not ordinary authorable template tokens.

Acknowledgement copy should migrate into ordinary versioned Messaging templates so composition can reference immutable versions.

## Consent acknowledgement delivery composition

Messaging may deliver an acknowledgement standalone or compose it into a compatible lifecycle message under an explicit policy.

The target representation is relational:

```text
ScheduledMessage
    primary lifecycle MessageTemplateVersion

ScheduledMessageComponent
    acknowledgement MessageTemplateVersion
    role
    intent_key
    message_consent_id
    placement/order
```

Do not copy the current consolidation working set into `ScheduledMessage.meta`.

Composition inherits the primary message's:

```text
send time
recipient
context
origin
channel
purpose/scope compatibility
delivery attempt lifecycle
```

A required acknowledgement must have either:

```text
a successfully composed path
or
an explicit standalone fallback
```

Missing primary behavior must not silently discard acknowledgement delivery.

Reserved `delivery_consolidation_*` placement fields remain internal composer concerns and are not universal tokens.

Readiness should evaluate valid delivery paths, not merely count standalone acknowledgement templates.

## Consent and suppression persistence

Consent grants, revocations, and suppressions preserve append-style compliance or safety truth.

Repeated channel/purpose/scope values in these low-volume evidence rows are acceptable.

Audit improvements should focus on:

```text
removing copied raw provider event objects
promoting queried evidence to first-class columns
retention policy
redaction
avoiding generic meta where a narrow evidence contract exists
```

Suppression remains destination-level operational safety and is not merged into Contact consent history.

## Recipient and context relationships

`recipient_type` / `recipient_id` answer who receives the message.

`context_type` / `context_id` answer what the message is about.

`origin_type` / `origin_id` answer which owning record initiated it.

Examples:

```text
Appointment reminder
    recipient = Contact
    context = Appointment
    origin = MessageChainEnrollment

Webinar reminder
    recipient = Contact
    context = WebinarRegistration
    origin = MessageChainEnrollment

Campaign chain delivery
    recipient = Contact
    context = CampaignEnrollment
    origin = MessageChainEnrollment

Direct FlowRoute message
    recipient = Contact
    context = Contact or route subject
    origin = ContactFlowRouteProgressItem

Broadcast delivery
    recipient = Contact
    context = Broadcast
    origin = BroadcastRecipient
```

Do not add a second generic subject morph to scheduled messages.

## FlowRoutes usage

FlowRoutes may use Messaging in two ways:

```text
send one template
start one message chain
```

Route authoring stores stable template/chain identity.

At execution time:

```text
direct template
    pin current MessageTemplateVersion
    create compact ScheduledMessage

message chain
    pin current MessageChainVersion
    create MessageChainEnrollment
```

FlowRoutes records the created ScheduledMessage or MessageChainEnrollment on FlowRoutes-owned progress state.

Messaging uses only the generic `origin` morph. It does not store a bundle of FlowRoutes-specific foreign keys.

Direct Route eligibility should become a first-class authoring/usage relationship or template capability flag. Do not preserve route eligibility forever inside generic template/catalog metadata.

Internal-purpose, lifecycle-owned, Campaign-owned, permission-invitation, and Webinar-owned definitions remain excluded from the generic direct-message picker unless deliberately exposed.

## Imported-contact permission invitations

Messaging owns the one-time imported-contact permission-invitation capability.

The send remains:

```text
email-only
transactional
permission-invitation scoped
one-time
limited to imported Contacts
```

The public acceptance path owns:

```text
consent creation
accepted channel recording
submitted SMS phone update when applicable
accepted state
neutral permission_invitation.accepted automation event
```

The invitation should use a versioned template and a compact ScheduledMessage.

The one-time policy should be first-class invitation state/identity, not an unbounded payload policy object.

### Cancellation, skip, and failure

Keep the current invitation lifecycle:

```text
claimed
sent
failed
accepted
```

Rules remain:

```text
pre-claim skip/cancellation
    no invitation row

post-claim ScheduledMessageSkipped
    matching claimed invitation -> failed

provider/runtime failure
    ScheduledMessage -> failed
    matching claimed invitation -> failed

successful send
    ScheduledMessage -> sent
    invitation -> sent

public acceptance
    ScheduledMessage remains sent
    invitation -> accepted
```

Failed invitations remain one-time blocking until a deliberate reissue policy is designed.

Messaging remains independent from downstream consumers of `permission_invitation.accepted`.

## Available-field picker support

Messaging contributes universal recipient/Contact fields.

Producer modules contribute domain-specific fields.

Preferred ownership:

```text
Messaging
    universal Contact/recipient values

Webinars
    Webinar and registration values

Campaigns
    Campaign-specific context values

Tasks
    task values

Documents
    document values

Scheduling
    appointment values

vertical modules
    vertical subject values
```

Available-field UI must consume the same registry/validator as server-side authoring.

Do not offer aliases or fields that the exact runtime context cannot supply.

## Setup validation ownership

Messaging contributes validation through `MessagingSetupValidationContributor` and reusable lower-level validators.

Target validation covers:

```text
template identity and immutable version shape
renderer availability/version
required subject/content fields by channel
token availability for the exact producer context
chain identity/version integrity
step ordering and timing shape
variant strategy/dependency references
template-version/channel compatibility
purpose/scope/channel availability
module-owned chain bindings
direct template/chain authoring eligibility
consent-domain ambiguity
required acknowledgement delivery paths
scheduled-message schema invariants after cutover
```

Hard errors represent impossible or unsafe execution.

Warnings represent dormant, unused, unavailable, or surprising-but-safe setup.

Do not persist validation findings unless an operator workflow later proves historical acknowledgement or audit state is required.

## Migration boundary

The migrations/models batch should establish the new records and relationships without switching current runtime behavior.

During the transition:

- current readers/writers may remain operational;
- target models should not expose compatibility `meta` escape hatches;
- no production backfill is required while pre-production resets remain allowed;
- config templates should not be rewritten to an unsupported runtime shape until the corresponding sync/runtime batch;
- current scheduled-message history must either be imported into the new compact contract or intentionally excluded according to the project-state migration plan.

Runtime cutover must proceed in focused batches and remove legacy readers once all producers use the target contract.