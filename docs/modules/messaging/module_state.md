# Messaging Module

## Status

Messaging is a reusable capability module.

Operator review of current bounce/suppression problems is documented in
[`delivery-issue-review.md`](delivery-issue-review.md).

The detailed workflow lives in the NEW focused document rather than being vaguely appended to an
arbitrary location in this already-large module state file.

The core persistence refactor is implemented and green:

```text
stable MessageTemplate identity + immutable MessageTemplateVersion
stable MessageChain identity + immutable MessageChainVersion/steps/variants
version-pinned MessageChainEnrollment with lazy next-wave materialization
compact template-difference ScheduledMessage persistence
lazy ScheduledMessageRenderContext token freezing
relational ScheduledMessageComponent composition
delivery-attempt claim/provider authority
terminal outbox authority and immutable ScheduledMessageTerminalResult
```

The completed 15B refactor removed duplicate terminal timestamps, reasons, counters, provider state, and claim state from `scheduled_messages`. It also removed the obsolete mutable pending-delivery consolidator.

Remaining transition work is separate from that completed core refactor:

```text
MessageTemplatePreset / assignment / catalog compatibility surfaces
Campaign-owned step/variant progression -> generic MessageChains
Broadcast private authoring + immutable version pinning + singular recipient delivery relationship complete
FlowRoutes direct-message authoring identity cleanup
Inbound provider raw-payload normalization
```

`scheduled_messages.payload` and `scheduled_messages.meta` remain bounded compatibility/runtime-difference fields. They are not allowed to contain copied template bodies, model snapshots, provider outcomes, terminal history, or consolidation recipes.

Detailed persistence rationale and the implemented/deferred boundary are in [`persistence-architecture.md`](persistence-architecture.md).

## Responsibility

Messaging owns:

- outbound message templates and immutable template versions;
- reusable message chains and immutable chain versions;
- message-chain steps and channel variants;
- message-chain enrollments and progression;
- compact scheduled-message execution records;
- provider delivery attempts and terminal outbox events;
- recipient/destination gates;
- channel+purpose message consent, acknowledgement domains, revocations, and suppressions;
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

### MessageChainEnrollment lifecycle

Messaging owns the generic enrollment lifecycle for every MessageChain consumer.

Public lifecycle actions include:

```text
StartMessageChainEnrollmentAction
PauseMessageChainEnrollmentAction
ResumeMessageChainEnrollmentAction
CancelMessageChainEnrollmentAction
```

Pause is a delivery-safety operation, not only a status label:

```text
active -> paused
pending ScheduledMessages for that enrollment -> skipped
sending/sent/failed/already-skipped deliveries -> unchanged
future progression -> dormant while paused
```

Resume preserves the remaining delay for a future, unmaterialized step by shifting `next_action_at` by the actual pause duration. If the enrollment was already waiting on a materialized wave whose pending messages were skipped during pause, resume makes the enrollment immediately due so the normal chain processor can account for that terminal wave and continue. Resume dispatch is after-commit and idempotent for already-active enrollments.

A delivery already claimed as `sending` cannot be recalled by pause. Consumers that require stronger provider-side recall semantics need a separate provider capability; they must not pretend a sent/claimed delivery was unsent.

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

### Missing-field behavior

Authorable template payloads may define message-specific `token_fallbacks` for dynamic fields used by that message. The supported behaviors are:

```text
required          -> leave the field unresolved so the normal pre-send safety check blocks delivery
fallback_value    -> supply literal replacement text for the missing field
replace_segment   -> replace one exact phrase containing the field, including with an empty string
```

A field with no explicit rule remains fail-safe required. Fallback text is literal and cannot introduce another dynamic field. A replace-segment rule must cover every use of that field in the message so partial personalization cannot escape unresolved. Both `{token}` and `:token` syntax use the same policy.

Missing-field behavior is part of immutable message content. It may be authored in a source definition or a message-specific composition override, but not in shared platform/client/family/context composition layers. This keeps a phrase-level fallback attached to the exact copy it was written for.

Runtime applies the policy only after recipient and execution-context values are assembled and before provider payload construction. When a pinned template uses dynamic fields, the render context freezes either the resolved/fallback values or the fact that a replace-segment fallback was chosen. Retries therefore do not gain later personalization merely because the Contact record changed after the first render.

## Definition availability and module ownership

Messaging may store reusable copy for consuming modules, but globally loaded config is not automatically effective runtime configuration for every client.

Effective definition availability follows the owning runtime module:

```text
standard webinar* definitions
    owned by Webinars
    available only when Webinars is in the enabled runtime dependency closure

campaigns subtrees inside Messaging definition config
    owned by Campaigns
    available only when Campaigns is in the enabled runtime dependency closure

other standard Messaging definitions
    owned by Messaging
    available when Messaging is in the enabled runtime dependency closure
```

Campaign ownership is independent of the surrounding scope name. A Campaign definition under `webinar_nurture` remains Campaign-owned and does not create a Campaigns -> Webinars dependency. The caller remains responsible for supplying any producer-specific context the Campaign template actually requires.

The same availability boundary must be used by:

```text
config -> DB template/preset sync
runtime config fallback
DB-backed assignment resolution
Messaging setup validation
```

Definitions owned by disabled modules are dormant. They must not be synced into active config-owned template rows, selected from persisted assignments, used as runtime fallback, or block setup validation because their producer token contexts are intentionally unavailable. Uncustomized config-owned rows that become dormant may be reconciled away by normal sync; customized historical rows may remain stored but are runtime-inert until their owning module is enabled again.

This availability rule is separate from preset contribution discovery. Installed modules may expose preset contributions independently of runtime enablement, but reusable Messaging definitions become executable only through the owning module's runtime availability.

## Message templates

### `message_templates`

A template is stable authoring identity.

Current fields:

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

Current fields:

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

### Bounded authoring composition

Message content may be composed at authoring time through explicit, bounded Messaging-owned
composition layers. Supported scope identities are platform, client, client-wide family,
context, context-family, and specific message.

`MessageTemplate` stores the generic `composition_context_key` and
`composition_family_key` selectors used to resolve applicable shared authoring state.
`message_template_composition_layers` stores partial channel-specific payload fields only.
It has no recursive parent relationship and no generic metadata recipe.

Publishing resolves the applicable layers plus the current source definition and any explicit
message override into one complete `MessageTemplateVersion`. Runtime delivery never resolves
composition and never changes an already-published version because a shared layer is edited later.

Reply routing remains separate from content composition. A reusable preset does not own
`reply_profile_key`; assignment/usage identity owns direct-definition reply routing and immutable
MessageChain variants own chain-runtime reply routing.

Current config payloads remain authoritative source definitions until a client is deliberately
migrated into shared composition. Do not create shared rows and assume they override duplicated
source fields that are still present in config.

The CRM Message Templates workspace is composition-aware. Existing shared layers may be
edited at their owned scope with an affected-message review before publish. Message-specific
edits are persisted only as top-level delta fields in a message-scoped composition layer.
The source MessageTemplatePreset payload remains partial and source-owned. Publishing shared
or message-level edits creates complete immutable MessageTemplateVersion records; already
pinned ScheduledMessages are not rewritten.

`MessageTemplatePublicationHookRegistry` is the generic post-publication seam for owner-specific derived runtime definitions. The Message Templates controller runs publication plus registered hooks inside one database transaction. A module integration may therefore republish a chain or other future-use definition that pins the newly published template version without teaching Messaging about that owning module. Hooks must never rewrite already-pinned ScheduledMessages or existing MessageChainEnrollments. Scheduling appointment communications use this seam so a copy edit in the generic library updates the current appointment MessageChain for future enrollments while existing appointments remain version-pinned.

Normal message-copy review and editing uses the canonical Messaging carousel/editor rather
than a permanent side-by-side preview/editor layout. A catalog family opens one message at a
time, the top bar identifies the channel plus Published copy/Edit copy state, and Edit replaces
the published preview in the same frame with populated operator-facing fields. Saving still
runs through the composition-aware publish path above, so the simple editing surface does not
materialize inherited fields back into source config.

The atomic copy-field primitive is the shared UI component `<x-ui.message-editor>`. It owns
only the common Email subject/body and SMS message field presentation while accepting
owner-supplied field names, Alpine bindings, required/visibility expressions, values, limits,
and validation errors. Keeping this field shell in shared UI avoids making optional producers
such as Scheduling depend directly on the Messaging module. Messaging continues to own the
higher-level carousel, template composition, publication, Media, token fallback, and delivery
semantics. The canonical carousel, Broadcast authoring, Scheduling communication steps, and
Flow Route inline reusable-message creation reuse the field primitive while keeping their
existing request and domain contracts. Campaigns and Webinars inherit it through the canonical
carousel.

## Message chains

### `message_chains`

A chain is stable reusable sequence identity.

Current fields:

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

Current fields:

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

Current fields:

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

Current fields:

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

## Message-chain presentation and carousel authoring

Messaging owns the reusable read/presentation seam for current immutable MessageChains.

`MessageChainPresentationService` projects the active current version into business-facing channel groups without copying or persisting another message definition:

```text
MessageChain
    -> current immutable MessageChainVersion
    -> active ordered steps
    -> active channel variants
    -> pinned MessageTemplateVersion payloads
    -> channel-first presentation
```

The presentation is derived only. It does not persist:

```text
preview payload copies
message counts
timing summaries
channel labels
carousel position
edit URLs
```

Owning modules may add business context, friendly anchor labels, and safe edit links around this projection.

Client/operator message-copy UX should default to the same canonical Messaging carousel/editor whether the caller is presenting a MessageChain or a catalog family:

```text
Email / SMS channel choice
Published copy / Edit copy state across the top
one message visible at a time
human-readable timing/context when available
previous / next navigation
large left/right click or tap gutters
swipe navigation on touch screens
published-copy preview
Edit replaces that preview with populated fields in the same frame
Save & publish / Cancel
```

Explicit Previous/Next controls remain available for discoverability and accessibility even when the wider gutters are clickable. Dirty edits must never be discarded silently when the operator changes channel or message.

Owning modules may add business context and may choose whether a particular message is editable, but they should reuse this shell instead of rebuilding stacked message panels or side-by-side preview/editor layouts. The Message Templates workspace is the generic catalog consumer; Webinar is the first MessageChain consumer with inline editing. Campaigns and other chain-owning modules should adopt the same shell when their authoring UX is touched rather than creating a competing editor pattern.

Do not make the normal client mental model a page containing every chain, step, variant, and payload form expanded simultaneously.

The carousel is a presentation/authoring pattern, not a new runtime abstraction. Immutable MessageTemplateVersion and MessageChainVersion ownership remains unchanged, and existing enrollments stay pinned to the versions they started with. Carousel position, edit state, and dirty state remain browser/view state and are not persisted.

## Chain enrollment

### `message_chain_enrollments`

One row represents one recipient moving through one immutable chain version.

Current fields:

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

## Bounded bulk delivery

Messaging owns the operational policy for large simultaneous recipient work. Bulk producers must not translate an arbitrarily large recipient set into an arbitrarily large set of ready or delayed delivery jobs in one request/transaction.

Current policy:

```text
small recipient set
    normal scheduling path

large recipient set
    durable owner-module recipient snapshot
    one bulk producer job at the requested send time
    at most bulk_delivery.chunk_size recipients per chunk
    one continuation producer released after bulk_delivery.release_interval_seconds
    resulting ScheduledMessages use bulk_messages
```

`messaging.bulk_delivery.chunk_size` and `messaging.bulk_delivery.release_interval_seconds` are root/process configuration, not client campaign content. A producing module may snapshot those values into its durable execution record so an in-flight operation is stable across later configuration changes.

`bulk_messages` is an executable Messaging queue isolated from the primary Horizon queue set. Horizon's environment max-process setting remains the total worker budget: the bulk reservation is carved out of that budget rather than added on top of it. This prevents bulk marketing work from consuming every worker needed for transactional, reminder, webhook, or notification traffic.

Broadcasts is the first producer using this seam. Campaign enrollment/runtime cutover should reuse the same bulk policy rather than introducing Campaign-specific chunk constants.

Bulk producer pacing is not the provider rate limit. Before a real provider submission begins, `SendScheduledMessageJob` acquires a slot from `ProviderSubmissionLimiter`. Configured provider limits are shared by all workers that use the same cache store/scope. Deployed environments should keep that limiter Redis-backed so parallel Horizon workers coordinate one provider-wide allowance instead of each assuming its own capacity.

The initial Resend configuration uses the provider's normal per-team requests-per-second limit as a configurable default. The environment value must be updated if the Resend team has a different account limit. Other providers are inert until they receive an explicit Messaging-owned limit definition.

This coordination is only global across workers/processes that share the configured limiter cache namespace. If multiple separate deployments share one Resend team but do not share that Redis-backed limiter namespace, they must divide the team allowance between deployments or move them behind a shared limiter rather than each claiming the full team limit.

The limiter waits inside the claimed job before `provider_submission_started_at` is recorded. It does not release the queue job and therefore does not consume `SendScheduledMessageJob`'s provider retry attempt budget.

Bulk timing/chunk settings belong on the durable producer execution record (for Broadcasts, `Broadcast.meta.scheduling.bulk`). They must not be copied onto every recipient ScheduledMessage merely for diagnostics; ScheduledMessage metadata stays limited to delivery-relevant identity/differences.

This layer preserves the existing delivery-attempt and provider-idempotency semantics.

## Scheduled-message persistence contract

### `scheduled_messages`

A ScheduledMessage is the compact logical delivery/execution row.

Current fields:

```text
id
recipient_type
recipient_id
context_type nullable
context_id nullable
behavior_owner_type nullable
behavior_owner_id nullable

message_template_version_id nullable
message_chain_enrollment_id nullable
message_chain_step_variant_id nullable

channel
message_type
purpose
scope
payload_class
queue nullable
dispatch_keys json nullable
definition_config_path nullable
payload json
send_at
status
provider_idempotency_key nullable
dedupe_key nullable
meta json nullable

timestamps
```

Current ownership rules:

- `message_template_version_id` pins immutable copy whenever the dispatch uses a versioned template;
- chain references identify the exact enrollment and variant for chain-created deliveries;
- `behavior_owner` identifies the record responsible for resolved delivery behavior without importing that module into Messaging;
- `context` remains the about-this-record relationship;
- `payload` stores only canonical runtime differences/operational values needed to resolve delivery, such as destination and direct-send overrides;
- when a template version is pinned, reusable template content is removed from persisted payload differences;
- token values are frozen lazily into `scheduled_message_render_contexts` and then removed from `scheduled_messages.payload`;
- `meta` is canonicalized to a closed bounded operational contract and is not a generic snapshot store;
- `status` is the parent lifecycle summary only;
- terminal occurrence, terminal reason, provider result, attempt count, claim state, and attempt timestamps do not live on this row.

The current bounded JSON fields are compatibility/runtime seams, not permission to restore the historical oversized row. Do not persist:

```text
full template content already pinned by version
full Contact/Webinar/registration/model arrays
loaded relationships
chain/profile/template/catalog snapshots
provider responses or attempt history
terminal timestamps/reasons
copied delivery-consolidation recipes
unbounded arbitrary metadata
```

Small first-class routing/classification columns remain intentional because claim, gate, queue, and operational queries use them directly.

### `scheduled_message_render_contexts`

Runtime token values are stored separately and lazily.

Current fields:

```text
id
scheduled_message_id unique
values json
content_hash
rendered_at
expires_at nullable
timestamps
```

The context contains only values required to reconstruct the pinned template and composed components for that logical delivery.

Messages whose pinned content has no dynamic-field references need no render-context row. If dynamic fields are referenced, an empty `values` object is still meaningful when it freezes a missing-field/replace-segment decision. Retries reuse that same frozen render state.

### `scheduled_message_components`

Additional composed content uses small relational rows.

Current fields:

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

The primary template remains on `scheduled_messages`. Component rows exist only for deliberate composition such as consent acknowledgements. Covered intent and consent identity remain relational rather than copied into metadata.

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

## Delivery claims, attempts, terminal authority, and stale recovery

### `scheduled_message_delivery_attempts`

Attempt rows are the sole claim/provider execution authority.

Current fields:

```text
scheduled_message_id
attempt_number
claim_token
status
claimed_at
lease_expires_at
provider_submission_started_at nullable
completed_at nullable
destination nullable
provider nullable
provider_message_id nullable
reason_code nullable
reason nullable
timestamps
```

Do not duplicate claim token, lease, provider, provider message ID, submission time, attempt number, attempt reason, or completion time onto `scheduled_messages`.

An expired pre-submission claim may be released/recovered according to the delivery policy. After provider submission begins, automatic retry requires a verified provider idempotency contract; ambiguous delivery must not be retried blindly.

### `scheduled_message_outbox_events`

One durable outbox row owns the terminal occurrence for each terminal ScheduledMessage.

Current terminal facts include:

```text
scheduled_message_id unique
delivery_attempt_id nullable
event_type
occurred_at
reason_code nullable
reason nullable
publication claim/status/attempt fields
published_at nullable
last_error nullable
```

Sent and failed terminal events require the matching completed delivery attempt. A direct pre-attempt skip may have no attempt and carries its reason on the outbox row.

`ScheduledMessageTerminalResult` resolves from the outbox event plus its exact attempt. It has no fallback to removed parent summary columns.

A terminal ScheduledMessage must not be reset to pending for recovery. Preserve the original row, attempt, and outbox evidence; use an explicit owning-module retry/reissue path that creates a new logical occurrence.

## Current preset/assignment/catalog compatibility bridge

Current compatibility tables:

```text
message_template_presets
message_template_preset_assignments
message_template_catalog_entries
```

remain active for config sync, selection, and CRM organization.

They no longer mean immutable template identity is absent. `SyncMessageTemplatePresetsAction` now synchronizes each preset key into:

```text
MessageTemplate
    stable canonical identity

MessageTemplateVersion
    immutable current content
```

`MessageTemplatePreset::toMessageDefinition()` resolves content through the canonical template/current version when available and exposes the pinned version ID to runtime dispatch.

Assignments and catalog entries remain compatibility/authoring surfaces for Campaign and general template selection. They should not be copied into ScheduledMessage snapshots or mistaken for delivery authority.

Future cleanup may reduce assignment/catalog indirection after every consuming module has a direct stable binding, but it must preserve current CRM usability and config-sync ownership. Do not add another permanent compatibility layer merely to mirror the existing resolver shape.

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

## Consent boundary, scope metadata, and opt-in acknowledgements

Message identity and hard permission identity are separate:

```text
Message identity
    channel + purpose + operational scope

Hard permission identity
    channel + purpose

Scope
    context + compatibility + attribution + audit metadata
```

Operational scope describes what a specific message is doing. It is not a second
permission gate. A valid grant on `email + marketing` may authorize an eligible email
marketing message carrying another scope. Email never authorizes SMS, and consent for
one purpose never authorizes another purpose.

`MessageConsentStateResolver` evaluates the newest consent and newest revocation across
the entire Contact + channel + purpose boundary. Existing historical rows keep their
stored scopes; no data rewrite is required. When timestamps tie, revocation wins. A later
valid grant can reactivate that channel + purpose.

`RevokeMessageConsentAction` creates one authoritative revocation for the channel +
purpose boundary. The revocation may retain a requested or related scope only as capture
context. SMS STOP therefore blocks all SMS messages for the affected purpose regardless
of message scope without revoking email.

`MessageGate` remains the final send authority. For Contact recipients it checks:

```text
runtime/provider support
valid destination
channel + purpose consent state when required
permission-invitation one-time rules when applicable
suppression
recipient-specific gates
```

Scheduling a message does not freeze consent. Dispatch-time gate evaluation still means a
later revocation prevents an already scheduled consent-gated message from sending.

Messaging still owns `ConsentDomainRegistry`, but domain resolution is not authorization
authority. It supports acknowledgement/context grouping:

```text
1. optional client channel + purpose acknowledgement topic/domain
2. otherwise scope policy:
       exact mapping wins
       otherwise longest registered prefix wins
       equal-specificity ambiguity fails loudly
       unknown unmapped scope falls back to itself
```

This allows related scopes such as `webinar`, `webinar_waitlist`, and
`webinar_nurture` to share human-readable acknowledgement context while authorization
remains channel + purpose.

Email and SMS remain separate permission boundaries. An email unsubscribe revokes email
marketing without revoking SMS marketing; SMS STOP revokes SMS marketing without
revoking email marketing.

CRM/Campaign classification must not be encoded as consent scope. Tags, statuses,
relationship stages, fields, Webinar outcomes, and Campaign eligibility decide whether a
program is relevant. Consent answers only whether the Contact may be contacted on that
channel for that purpose.

Imported consent uses `ImportMessageConsentAction` so provenance/capture scope is
retained without emitting `MessageConsentGranted` or triggering opt-in acknowledgement
behavior. Imported permission still resolves at channel + purpose.

Forms supplies only accepted channel + purpose intents. The Messaging bridge validates
those values and records `forms` as capture scope. Forms does not require a broad consent
domain mapping before a valid explicit grant can be recorded.

Permission-invitation delivery is deliberately separate from normal marketing sends. The
one-time invitation message is `email + transactional + permission_invitation` and still
passes imported-contact/one-time eligibility plus suppression. Public acceptance creates
one normal `marketing` consent grant per explicitly selected channel with
`permission_invitation` retained as capture scope/provenance.

Opt-in acknowledgement definition, delivery policy, consolidation, and fallback remain
Messaging-owned. `ConsentOptInDefinitionResolver` may use the resolved acknowledgement
domain/topic to produce human-readable copy. Do not expose raw scope keys as end-user
consent copy and do not infer authorization from acknowledgement grouping.

## Forms consent bridge

Forms may declare server-owned consent intents using only:

```text
field + channel + purpose
```

Forms does not choose a Messaging permission scope and does not pass an interest/CRM scope
as the permission boundary. The optional Forms-to-Messaging integration validates each
declared channel and purpose and records accepted permission directly on that boundary.
It stores `forms` as capture scope/provenance. `ConsentDomainRegistry` may still provide
acknowledgement/topic context, but no explicit domain mapping is required to authorize the grant.

When a normalized consent field is `true`, the integration grants through
`GrantMessageConsentAction`. A false, omitted, or null field does not revoke existing
consent; unsubscribe, STOP, preference updates, and other Messaging-owned revocation
flows remain authoritative.

Forms passes only bounded provenance pointers such as the FormSubmission ID,
FormVersion ID, and accepting field key. It does not copy disclosure text, interest
tags, Turnstile evidence, or arbitrary form payloads into MessageConsent metadata.
Campaigns and Broadcasts remain unchanged: they keep their operational message scopes
and continue to rely on the Messaging gate, which evaluates active consent on the exact
channel+purpose boundary.

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

The implemented representation is relational:

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

## Recipient, context, and behavior-owner relationships

`recipient_type` / `recipient_id` answer who receives the message.

`context_type` / `context_id` answer what the message is about.

`behavior_owner_type` / `behavior_owner_id` answer which durable record supplied the resolved lifecycle behavior.

MessageChainEnrollment separately retains its own `origin` morph for the record that started the chain.

Examples:

```text
Appointment reminder
    recipient = Contact
    context = Appointment
    behavior owner = MessageChainEnrollment

Webinar reminder
    recipient = Contact
    context = WebinarRegistration
    behavior owner = MessageChainEnrollment

Campaign delivery
    recipient = Contact
    context = CampaignEnrollment
    behavior owner = current Campaign scheduling/progression record until Campaign chain cutover

Direct FlowRoute message
    recipient = Contact
    context = Contact or route subject
    behavior owner = FlowRoutes-owned progress item

Broadcast delivery
    recipient = Contact
    context = Broadcast
    behavior owner = BroadcastRecipient when supplied by that path
```

Do not add another generic subject morph to scheduled messages.

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

Detailed runtime/consent behavior is documented in [`permission-invitations.md`](permission-invitations.md).

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

Validation should cover:

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
scheduled-message schema and authority invariants
```

Hard errors represent impossible or unsafe execution.

Warnings represent dormant, unused, unavailable, or surprising-but-safe setup.

Do not persist validation findings unless an operator workflow later proves historical acknowledgement or audit state is required.


## Message library discovery and display labels

The CRM Message Templates workspace presents human-facing meaning first and keeps
runtime coordinates as technical metadata.

Presentation rules:

- catalog order and immutable template identity remain authoritative for runtime;
- labels such as `Step 7 Email` or `Reminder 5 Email` are treated as technical
  coordinates, not useful operator-facing names;
- a meaningful catalog label is preferred when one already exists;
- known reminder timing encoded in message identity may be rendered as a semantic
  label such as `10-Minute Reminder`;
- otherwise Email subject copy is the preferred label for technical catalog rows;
- SMS may fall back to a short excerpt of its message copy;
- technical keys/names remain available in the details surface for debugging.

The library search covers family/context labels, resolved human message labels,
subjects, message copy, and technical identity. Normal filtering is business-facing:
Channel and Context are primary filters; Purpose remains an advanced filter.

Reusable selection consumers should not query every active MessageTemplatePreset.
`ReusableMessageTemplateCatalog` is the Messaging-owned safe-selection seam for
operator-authored standalone reusable messages. Lifecycle-owned Campaign steps,
Webinar reminders, reply acknowledgements, and similar definitions are not generic
selection candidates merely because they are active templates.

### Contextual reusable-message creation

`CreateReusableMessageTemplateAction` is the Messaging-owned persistence and versioning
primitive for CRM-authored reusable messages. It does not decide why the message exists.
The calling surface supplies a `ReusableMessageTemplateAuthoringContext` containing the
server-owned purpose, scope, dispatch context, payload class, queue, catalog ownership,
grouping, usage type, and allowed selection contexts. The operator supplies only the
human-facing name and channel payload.

This keeps creation contextual without coupling Messaging to consuming modules:

```text
Broadcasts -> Broadcast authoring context -> Messaging create action
Campaign Annual Touches -> annual-touch authoring context -> Messaging create action
Flow Routes -> Route authoring context -> Messaging create action
```

Authoring context is persisted in existing preset/catalog metadata. It is not a new schema
contract and does not require preset sync. Contextual selectors may ask
`ReusableMessageTemplateCatalog` for a specific selection context so a template created
for one surface does not automatically leak into an incompatible picker. Legacy saved
Broadcast messages remain selectable in Broadcasts and Annual Touches for compatibility.

### Purpose-guided standalone creation

The Message Templates workspace exposes one guided standalone-creation entry point backed by
`ReusableMessageTemplateAuthoringOptionContributor`. Enabled owning capabilities contribute
server-resolved authoring options; `ReusableMessageTemplateAuthoringGuide` validates and presents
those choices, and the generic creation controller passes only the operator-authored name and copy
to `CreateReusableMessageTemplateAction`. Purpose, scope, dispatch key, message type, payload
class, queue, catalog ownership, grouping, and selection contexts are never accepted as browser
authority.

The initial contributors intentionally cover only standalone reusable contexts that already have a
valid downstream selection seam: Broadcast marketing messages, Campaign Annual Touches, and
direct Route messages. Standard Campaign-step, Webinar-lifecycle, and Scheduling communication
authoring remain owner-specific because their runtime identity is tied to Campaign/MessageChain or
Scheduling lifecycle contracts rather than generic standalone selection. Add a new guided choice by
contributing a real reusable-selection capability; do not add a generic module name that creates
templates no owning surface can safely consume. Cross-module Route contribution is registered from
the integration perimeter so FlowRoutes does not acquire a direct Messaging dependency.


### Direct Flow Route reusable messages

Flow Route direct-message authoring uses the contextual reusable-message seam rather than exposing the entire MessageTemplatePreset table. New Route messages are created with selection context `flow_routes`, dispatch context `flow_route_send_message`, scope `general`, surface `route_send_message_points`, and operator-selected channel plus purpose. Marketing is the authoring default; transactional remains an explicit service/operational choice.

The Route Point persists canonical `message_template_key` identity. At execution, `DirectMessageTemplateResolver` resolves that exact active preset/canonical MessageTemplate/current immutable version and supplies exactly one definition to `DispatchMessageAction`. The resulting ScheduledMessage pins `message_template_version_id`; later template edits affect later Route executions but never rewrite already-scheduled messages.

`meta.route_authoring.eligible` remains a bounded legacy read path for previously exposed templates. New CRM-authored templates use `authoring.selection_contexts` and `ReusableMessageTemplateCatalog`; do not add new legacy route-eligibility metadata.

The executable `flow_route_send_message` token context exposes only Contact copy values already guaranteed by Messaging's recipient payload resolver (`first_name`, `last_name`, `name`, `email`, `phone` and canonical Contact forms). Route-only technical interpolation tokens are not presented as client-facing reusable message fields.

### Context-aware available fields

`MessageTemplateAuthoringFieldPresenter` is the reusable Messaging presentation seam for
operator-facing Available fields / Insert field controls. It accepts an executable dispatch
context key and projects only the canonical sources registered on that context in
`TokenContractRegistry`. A source alias is preferred for insertion when one is explicitly
registered (for example `{first_name}` for `contact.first_name`); otherwise the canonical
syntax is inserted (for example `{campaign.name}`).

The presenter does not invent module fields, inspect module tables, or maintain its own token
allowlist. Owning modules continue to contribute `TokenSourceProvider` and
`TokenContextProvider` definitions. Authoring surfaces may reuse the accompanying Messaging
Blade component and handle the emitted insertion event in their local editor. Creation and
update paths must still validate submitted copy through `MessageTemplateTokenValidator`.

Broadcasts registers `broadcast_send` as one of those executable contexts. The context is deliberately limited to Contact values materialized by `MessageRecipientPayloadResolver`; the Broadcast editor does not expose a field merely because a wider Core token source exists. Regular Broadcast draft/source copy is owned by one private Messaging `MessageTemplate` and its current immutable version; scheduling validates that copy, pins the Broadcast to the selected `MessageTemplateVersion`, and gives every recipient ScheduledMessage that same version identity. Messaging therefore persists only runtime differences on recipient delivery rows and resolves/finalizes Contact values lazily. Broadcast recipients retain one nullable `scheduled_message_id` relationship rather than a JSON list of message IDs.

`CreateReusableMessageTemplateAction` and `ReusableMessageTemplateCatalog` preserve `token_fallbacks` along with subject/body/message copy. Reusable email payloads also preserve a bounded first-class `cta` (`tracking_key`, `label`, `url`) when one is present. This is required for Broadcast promotion/reuse: a saved personalized message must not lose either the missing-field behavior or primary CTA that made up the authored message. The reusable template's immutable version remains the canonical copy for the library item; loading it into a Broadcast creates a draft copy and does not mutate the saved template.

## Completed refactor boundary and remaining work

The 15A/15B implementation sequence is complete for the core Messaging persistence contract:

- immutable template and chain identities/versions are live;
- Webinar schedule profiles publish immutable chains and Webinars-owned bindings;
- chain enrollments lazily materialize actionable deliveries;
- render contexts and composed components are relational;
- delivery attempts own claim/provider execution;
- terminal outbox events own terminal occurrence/reason;
- parent terminal summary columns and legacy fallback readers are removed;
- Broadcast terminal outcome snapshots are normalized;
- the obsolete pending-delivery consolidator is deleted.

Separate future refactors may still remove bounded compatibility fields or migrate owning modules further, but they must be justified independently and must not be described as unfinished 15B work.

Before production data migration, the export/import tool should treat the current post-15B schema as the target contract and explicitly map or drop historical legacy payload/terminal fields.

## Reusable Media integration

Messaging does not own uploaded files. When both `messaging` and the silent `media` module are enabled, the support integration layer supplies email-capable message authoring with active reusable Media assets and runtime upload access. SMS authoring remains text-only.

`MessageMediaAuthoringService` is the single Messaging-owned authoring seam for Media presentation, validation, upload/selection resolution, removal, and immutable-snapshot preservation. The request-scoped service memoizes the selectable Media list so a carousel with several email messages does not repeatedly query the Media library. `<x-messaging.message-media-authoring>` supplies that capability to Messaging-owned/coupled authoring surfaces while `<x-ui.message-media-editor>` remains a dependency-neutral field renderer.

The canonical message carousel resolves Media itself, so Message Templates, Campaign message editing, and Webinar message editing inherit the same authoring behavior without Campaigns or Webinars owning separate Media persistence. Guided reusable-template creation, Broadcast create/edit, and direct Route reusable-message creation use the same Messaging authoring seam. Broadcast draft Media is saved into the Broadcast's existing private immutable `MessageTemplateVersion`; Broadcasts does not own Media rows or a separate attachment schema.

Scheduling remains dependency-safe: Scheduling itself receives only plain Media authoring data/rules through `AppointmentCommunications`. The Messaging-backed integration resolves the chosen/uploaded Media and publishes the snapshot into the Scheduling-owned email template version; the Scheduling Blade uses only the dependency-neutral UI component and does not import Messaging or Media module classes.

Published email content stores a resolved media snapshot rather than a live `MediaAsset` foreign key. Scheduled-message canonicalization pins that snapshot with the rest of the immutable payload, so later Media title changes or archive actions cannot rewrite existing message versions. If an asset used by the current immutable version is later archived, authoring keeps that exact current snapshot available so an unrelated copy edit does not silently remove Media or require the archived asset to become selectable again. Operators may deliberately replace or remove it. A new upload takes precedence over an existing-asset selection.

## Contact direct messages

Messaging contributes a compact `Send message` action to the Core Contact show page through the existing `ContactPanelProvider` extension seam. Core remains unaware of Messaging. The modal is a Contact-scoped one-off composer only; there is no separate global compose workspace or recipient picker.

`ContactDirectMessageComposerPresenter` exposes only provider-ready Contact channels and channel+purpose combinations that currently pass `MessageEligibilityGate`. `SendContactDirectMessageAction` repeats those checks server-side and schedules the result through the normal `ScheduleMessageAction` pipeline on the existing `emails`/`sms` queues. Delivery-time `ScheduledMessageGate`, suppressions, consent, provider attempts, reply-address generation, terminal events, and existing Contact message history remain unchanged and authoritative.

A direct message does not create reusable template rows. An operator may optionally start from an active CRM-authored reusable template, but that template is only a source snapshot: its current payload is copied into the one-off message and then the operator's copy/Media choices are applied. The resulting `ScheduledMessage` does not pin the reusable template version and therefore may remove or customize template Media/copy without mutating the library item. Canonical meta retains source reusable-preset identity for audit when one was used. A request-scoped UUID dedupe key protects against accidental duplicate form submission.

Email direct messages reuse universal Media authoring from 24E3; SMS remains text-only. Existing Contact scheduled-message/history providers surface the new delivery automatically, so direct messaging adds no second history or conversation persistence model.

Reusable CRM-authored email normalization and catalog projection preserve valid `MessageMediaPayload` snapshots. This closes the 24E3 persistence gap where Media could be resolved by a creation surface but discarded by `CreateReusableMessageTemplateAction` or omitted by `ReusableMessageTemplateCatalog` before downstream reuse.

The email renderer supports a `{media}` body marker. If a message has media and does not contain that marker, the media card is appended after the body. Video uses an email-safe poster/play card (or a generic play card when no poster is selected), and plain text includes the tracked destination URL. Media clicks use the existing CTA engagement route with the stable `media_primary` tracking key.