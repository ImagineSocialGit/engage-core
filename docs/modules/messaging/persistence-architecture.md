# Engage Core — Messaging Persistence and Database Bloat Architecture

## Status

Implemented architecture with explicitly deferred module-specific work.

The core Messaging persistence refactor completed through Batches 15A–15B4 and the full suite is green. The implemented foundation includes immutable templates/chains, lazy chain execution, render contexts, relational components, delivery-attempt authority, terminal outbox authority, strict terminal-result resolution, Broadcast terminal normalization, and removal of the obsolete pending-delivery consolidator.

This document now records both:

```text
implemented post-15B ownership
remaining bounded compatibility fields
separate future Campaign/Broadcast/FlowRoutes/inbound refactors
```

The current schema intentionally still contains bounded `scheduled_messages.payload` and `scheduled_messages.meta` fields for runtime differences and compatibility paths. They must remain canonical and compact; they are not a license to restore the historical copied snapshots measured below.

## Purpose

Engage Core must reduce retained database volume without trading one oversized JSON column for another table containing the same oversized data.

The goal is:

```text
store shared definitions once
store immutable versions only when authored behavior changes
keep high-volume runtime rows narrow
materialize only the next actionable work
store only irreducible per-recipient execution values
separate hot operational state from bulky historical/raw state
```

The number of tables is not the primary metric.

The primary metric is:

```text
bytes per row
× rows created per workflow
× workflow volume
× retention period
+ index footprint
+ maintenance/backup/DDL cost
```

## Research conclusions

The reviewed MySQL discussion does not support a universal database-size or row-count threshold at which performance suddenly becomes unacceptable.

The coherent conclusions are:

```text
query shape matters more than a headline database size
indexes and rows scanned matter
row width and index width matter
the hot working set and buffer-pool fit matter
concurrent query/write volume matters
complex joins can become expensive
backup, restore, replication, and DDL pain may arrive before ordinary indexed reads become slow
old or rarely used data should not burden hot operational paths without a reason
deleting rows does not automatically make InnoDB data files shrink
```

MySQL's own documentation supports the same practical direction:

- InnoDB caches data and index pages in the buffer pool.
- JSON consumes roughly the same storage as the equivalent text representation.
- large table rebuilds and index creation can require substantial temporary disk space and operational time.
- query plans should be evaluated with `EXPLAIN`, not guessed from total database size.
- table count itself is not a useful reason to avoid normalized ownership tables.

Engage Core should therefore avoid both extremes:

```text
bad
    one giant row containing every object, token, config branch, and audit detail

also bad
    dozens of one-to-one tables that reproduce the same giant object through joins
```

The target is deliberate normalization around stable identity, immutable definitions, narrow execution state, and separate retention boundaries.

## Current measured evidence

The historical pre-refactor Webinar confirmation row supplied during the audit contained approximately:

```text
payload JSON    1,866 bytes
meta JSON         991 bytes
combined JSON   2,857 bytes
```

That single scheduled row repeats or embeds:

```text
tokenized subject/body/CTA/link structure
recipient destination
resolved token values
Webinar and registration values
template preset, assignment, definition, and catalog-style identity
schedule profile, profile item, message area, and item labels/keys
resolved conditions
delivery-consolidation recipe
covered consent IDs and intent keys
Webinar/registration IDs already represented by relationships
```

This is before ordinary columns, secondary indexes, clustered-index overhead, delivery-attempt rows, outbox rows, and binary/logging effects.

The problem is not that 2,857 bytes makes one row impossible for MySQL.

The problem is multiplying that shape by:

```text
every registration
× every confirmation/reminder/follow-up
× every retryable or historical delivery
× every client
× long retention
```

## Persistence design rules

### 1. High-cardinality tables must be narrow

Apply the strictest persistence rules to tables that can grow with contacts, registrations, recipients, chain enrollments, scheduled deliveries, provider attempts, inbound events, or webhooks.

Do not add generic `meta`, `payload`, `context`, `snapshot`, or `settings` columns to those tables without a documented bounded contract.

### 2. Low-cardinality definition tables may use bounded JSON

JSON is acceptable where it represents one reusable authored definition and avoids an unstable collection of nullable presentation fields.

Examples:

```text
one immutable email template version
one immutable chain version's exit-condition definition
one low-volume recipient-filter definition on a Broadcast
```

It is not acceptable to copy those same objects into every runtime row.

### 3. Normalize identity, not every scalar

Use foreign keys and small mapping rows for durable ownership and many-to-many relationships.

Do not create lookup tables merely to replace small stable operational columns such as:

```text
channel
purpose
scope
status
queue key
reason code
```

Keeping those columns on `scheduled_messages` avoids deep joins in hot gate, claim, and queue queries. This is intentional operational denormalization, not uncontrolled duplication.

### 4. Immutable versions protect historical behavior

Previously scheduled or sent work must never depend on a mutable template or mutable chain definition.

Edits create new immutable versions.

Existing rows continue to reference the versions they pinned.

### 5. Separate hot state from large or differently retained state

A separate table is justified when it changes cardinality or retention.

Examples:

```text
scheduled_messages
    narrow hot execution state

scheduled_message_render_contexts
    created lazily only when rendering begins
    retained or pruned under a separate content-history policy

webhook_inbox_receipts
    raw provider payload retained under a provider/debug retention policy

inbound_messages
    normalized business record with no copied raw request
```

A one-to-one table that is always created and contains the same old payload is not an improvement.

### 6. Prefer lazy materialization

A ten-step chain should not automatically create ten future `scheduled_messages` rows per enrollment.

Normal progression should retain:

```text
one message-chain enrollment
one next-action timestamp
only the currently actionable step wave
```

The next wave is materialized after the current step reaches the chain's advancement condition.

### 7. Derived labels and counts remain derived

Do not persist:

```text
chain message count
human-readable timing summaries
catalog breadcrumb labels
template names beside template foreign keys
current step number beside a current-step foreign key
campaign key beside a non-null campaign foreign key
scheduled-message ID arrays where a relationship exists
```

Persist a derived value only when a measured hot query or integrity requirement justifies the denormalization.

## Implemented Messaging definition schema

## `message_templates`

Stable editable identity:

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

Rules:

- one template is channel-specific;
- a template does not own timing, chain progression, trigger identity, Campaign identity, Webinar identity, purpose, scope, queue, or conditions;
- `current_version_id` identifies the version selected for future authoring/runtime resolution;
- config sync may update non-customized definitions and preserve customized definitions;
- stable template identity must not depend on a physical config-array position.

No `meta` column is planned.

## `message_template_versions`

Immutable tokenized content:

```text
id
message_template_id
version
subject nullable
content
renderer_key
renderer_version
content_hash
created_by nullable
created_at
```

Rules:

- versions are append-only after publication;
- email subject is first-class and nullable for channels that do not use it;
- `content` is bounded JSON only where the renderer needs structured content such as body, CTA, secondary link, or footer;
- SMS may use the same bounded content envelope with a single message field;
- token usage is derived and validated from subject/content when a version is created;
- do not persist a duplicate `tokens` JSON allowlist;
- renderer identity/version is pinned so historical reconstruction does not depend on an unspecified future renderer.

A new row is created only when authored content changes.

## Bounded template-composition authoring state

`message_template_composition_layers` is low-cardinality authoring state, not runtime delivery
state. It stores only partial payload fields at fixed scopes:

```text
platform
client
client-wide family
context
context + family
specific message
```

The table intentionally has no recursive parent relationship and no generic `meta` column.
Important selectors are first-class. Partial payload JSON is justified because the set of
channel fields is closed and validated, one row is stored per meaningful authoring scope, and
the payload is not copied per recipient or delivery.

`MessageTemplate` stores compact `composition_context_key` and `composition_family_key` selectors
for resolution and impact analysis.

Composition is resolved only when publishing `MessageTemplateVersion`. The immutable version is
the complete runtime artifact. Scheduled messages and provider attempts do not store composition
recipes or shared-layer snapshots.

The current config/preset payload remains the source definition during the compatibility cutover.
As clients are deliberately migrated, genuinely inherited fields should be removed from duplicated
source definitions rather than copied into both source payload and shared composition layers.

## Implemented Messaging chain schema

## `message_chains`

Stable reusable chain identity:

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

A chain is a reusable sequence.

It does not own its business trigger.

Owning modules and FlowRoutes decide when to enroll a recipient.

No `meta` column is planned.

## `message_chain_versions`

Immutable chain behavior:

```text
id
message_chain_id
version
exit_conditions nullable
content_hash
published_at nullable
created_by nullable
created_at
```

`exit_conditions` is low-volume immutable definition data. It is not copied into each enrollment or scheduled message.

Existing enrollments remain pinned to the chain version that was selected when they started.

## `message_chain_steps`

Ordered business moments:

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
conditions nullable
is_active
```

Rules:

- timing is canonicalized into a small fixed shape;
- client-facing minutes/hours/days may be authoring conveniences, but persistence should use one canonical offset;
- conditions are definition-level and are evaluated from current context when the step becomes actionable;
- message count is derived from active steps;
- no reusable message copy is stored here;
- no generic `meta` column is planned.

## `message_chain_step_variants`

Channel-specific delivery options:

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
dependency_policy nullable
conditions nullable
is_active
```

Rules:

- template versions are pinned by the immutable chain version;
- channel strategies such as `first_available`, `send_all_eligible`, and dependency-aware behavior belong to the chain step/version definition;
- reusable copy is not duplicated;
- small routing columns stay first-class because they are operationally useful and avoid expensive hot-path joins;
- no generic `meta` column is planned.

## Implemented chain runtime schema

## `message_chain_enrollments`

One recipient progressing through one immutable chain version:

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

Rules:

- recipient answers who is moving through the chain;
- context answers what domain record supplies chain meaning/token context;
- origin answers which module record or FlowRoute progress item started the enrollment;
- exit rules remain on the immutable chain version;
- the enrollment stores the exit result, not a copy of the rules;
- no `start_context`, `exit_conditions`, or generic `meta` JSON is planned;
- producer data that cannot be resolved from recipient/context/origin must be introduced through an explicit bounded input contract, not an opaque object dump.

## Lazy progression

The chain runner should normally create only the next actionable variant wave.

Example:

```text
registration creates chain enrollment
    no ten-message payload snapshot
    no ten scheduled-message rows by default

first step becomes actionable
    eligible variant wave is materialized

wave reaches advancement policy
    enrollment advances
    next_action_at is calculated
```

A workflow may materialize more than one wave only when concurrent independent variants are deliberately required.

## Implemented scheduled-delivery schema

## `scheduled_messages`

Compact logical delivery/execution record:

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

created_at
updated_at
```

Implemented rules:

- immutable copy is pinned through `message_template_version_id` when a versioned template is used;
- chain-created deliveries pin the exact enrollment and step variant;
- `behavior_owner` preserves the durable owner of resolved lifecycle behavior;
- `payload` is canonicalized and, for versioned templates, stores only differences/operational values rather than reusable template content;
- the normal chain path persists destination-only payload differences and empty generic metadata;
- runtime token values move lazily into `scheduled_message_render_contexts` and are removed from parent payload;
- `meta` is a closed bounded operational contract, not arbitrary snapshots;
- `status` remains a compact parent lifecycle summary;
- attempt count, claim state, provider result, terminal occurrence, terminal timestamps, and terminal reason are not parent columns;
- the stable provider idempotency key remains on the logical delivery row.

The retained JSON fields are accepted only under bounded canonicalizers. They must not contain:

```text
full template content already referenced by version
model arrays or loaded relationships
profile/catalog/assignment snapshots
provider responses or attempt history
terminal timestamps/reasons
consolidation recipes
unbounded debug metadata
```

## `scheduled_message_render_contexts`

Irreducible per-delivery token values:

```text
id
scheduled_message_id unique
values json
content_hash
rendered_at
expires_at nullable
timestamps
```

Rules:

- created lazily during first provider-ready payload resolution;
- contains frozen runtime token values required by the pinned template/components;
- retries reuse the same frozen context;
- parent payload tokens are removed after the context is created;
- retention may differ from the hot ScheduledMessage row.

## `scheduled_message_components`

Optional composed content components:

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

Covered intent/consent identity is relational. No delivery-consolidation recipe is stored on the parent row.

## `scheduled_message_delivery_attempts`

Attempt and provider execution authority:

```text
id
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

Rules:

- claim leases and provider outcomes live here;
- the parent does not duplicate attempt number, claim, provider, completion, or reason facts;
- provider-specific raw responses require a separate proven evidence/retention contract;
- automatic retry after provider submission requires a verified idempotency guarantee.

## `scheduled_message_outbox_events`

Terminal occurrence and event publication authority:

```text
id
scheduled_message_id unique
delivery_attempt_id nullable
event_type
occurred_at
reason_code nullable
reason nullable
status
available_at
claim_token nullable
claim_expires_at nullable
attempts
last_attempted_at nullable
published_at nullable
last_error nullable
timestamps
```

Sent/failed terminal events reference their exact completed attempt. A pre-attempt direct skip may carry its reason on the outbox event without an attempt.

`ScheduledMessageTerminalResult` resolves only from this outbox row and its attempt. Removed parent terminal columns are not a compatibility fallback.

## Template and chain editing rules

### Editing a template

```text
save draft/publish
    create a new immutable MessageTemplateVersion
    update MessageTemplate.current_version_id for future selection
    do not rewrite existing chain versions
    do not rewrite existing ScheduledMessages
```

### Editing a chain

```text
save draft/publish
    create a new immutable MessageChainVersion and child steps/variants
    pin selected MessageTemplateVersion IDs
    update MessageChain.current_version_id for future enrollments
    do not rewrite existing enrollments or scheduled messages
```

### Duplicating a chain

```text
create one new MessageChain identity
create one new MessageChainVersion
copy small step/variant definitions
reuse existing immutable MessageTemplateVersion IDs initially
create new template/version rows only when copy is customized
```

This is copy-on-write.

Copying a dozen small relationship/definition rows is not database bloat. Copying full message payloads into every recipient execution row is.

## Module cutover contracts

## Campaigns

Current state:

- Campaigns still owns the active CampaignStep/CampaignStepVariant progression engine;
- Campaign terminal reconciliation consumes immutable `ScheduledMessageTerminalResult` data from the durable outbox/attempt contract;
- Campaign metadata does not own provider attempt or terminal history.

Remaining separate target:

```text
Campaign.message_chain_id
CampaignEnrollment -> MessageChainEnrollment
Messaging MessageChain owns reusable progression
```

That future Campaign migration is not required to reopen completed ScheduledMessage terminal persistence.

## Broadcasts

Current state after 15B3:

```text
BroadcastRecipient.status
BroadcastRecipient.sent_at for sent outcomes
BroadcastRecipient.terminal_reason for skipped/failed/cancelled outcomes
no copied meta.delivery provider/attempt snapshot
```

Messaging attempts/outbox remain authoritative for provider execution and exact terminal occurrence.

Still deferred:

- Broadcast payload -> private/reusable immutable template version;
- `scheduled_message_ids` JSON -> one nullable ScheduledMessage FK;
- audit/removal of remaining generic Broadcast metadata.

Export/import must preserve the current Broadcast shape until that separate migration is implemented.

## Webinars

Implemented runtime state:

```text
Webinar schedule profiles/items
    operator-facing cadence authoring and selection

profile sync
    publishes immutable MessageChains/versions/steps/variants
    creates Webinars-owned profile bindings

series customization
    owns copy-on-write series MessageChain bindings

registration/post-event runtime
    starts version-pinned MessageChainEnrollments
    lazily materializes only actionable deliveries
```

Chain-created Webinar ScheduledMessages pin template/chain relationships and retain only runtime differences such as destination. They do not copy profile, label, template, or condition snapshots.

A later product simplification may replace schedule-profile authoring tables, but the current hybrid is runtime-correct and should not be described as an unimplemented cutover.

## FlowRoutes

FlowRoutes remains the cross-module trigger/control-flow owner.

Route authoring may:

```text
send one Messaging template
start one Messaging message chain
start or stop one Campaign
```

FlowRoutes stores stable template/chain identity in Point definition data. Each execution pins the then-selected immutable version.

Messaging scheduled rows use the generic `origin` morph for the creating FlowRoute progress item. Messaging does not add a repeated bundle of FlowRoutes foreign keys.

## Inbound messaging and provider receipts

Raw provider webhook content is stored once in `webhook_inbox_receipts.payload`.

Target normalized inbound persistence:

```text
inbound_messages
    webhook_inbox_receipt_id nullable
    sender morph
    normalized provider/message identity
    normalized from/to/body/classification/purpose/scope/timestamps
    no raw request copy
    no generic meta

provider event/message hash keys
    unique on inbound_messages
    replace the separate duplicate inbound receipt identity when all paths use the canonical ingestion boundary
```

Suppression and consent-revocation rows retain:

```text
provider
source_event_id
normalized reason/source
required compliance evidence
```

They do not copy the full email webhook `data` object already retained by the webhook inbox.

Raw webhook retention must be explicit and may be shorter than normalized business/compliance records.

## Consent and compliance history

Consent grants, revocations, and suppressions are not high-priority deletion targets merely because they repeat channel/purpose/scope.

Those rows preserve append-style compliance truth.

The audit should still:

- remove copied raw provider events from generic metadata;
- replace frequently queried metadata keys with first-class columns;
- document retention;
- preserve IP/user-agent evidence only when policy requires it;
- avoid storing the same acknowledgement content on consent and delivery rows.

## Index and query policy

Every high-volume table needs indexes derived from actual query paths.

Required initial hot-path index families include:

```text
scheduled_messages
    status + send_at
    queue + status + send_at
    recipient morph + status
    context morph + message_type
    origin morph
    message_chain_enrollment_id + message_chain_step_variant_id
    dedupe_key unique where applicable
    provider_idempotency_key unique where applicable

message_chain_enrollments
    status + next_action_at
    recipient morph + status
    context morph
    origin morph
    message_chain_version_id + status
    dedupe_key unique where applicable

scheduled_message_delivery_attempts
    scheduled_message_id + attempt_number unique
    status + lease_expires_at
    claim_token unique

inbound_messages
    provider event/message identity keys unique
    sender morph + classification
    received_at
```

Do not add indexes for every available column.

Each secondary index increases storage, write amplification, buffer-pool pressure, backup size, and DDL cost.

Use `EXPLAIN` and production-shaped queries to justify indexes.

## Hot and historical data

Status and time indexes should keep ordinary operational queries inside the active working set.

Likely retention tiers:

```text
hot
    pending/sending/retryable scheduled messages
    active chain enrollments
    recent terminal deliveries
    current delivery attempts

historical
    old terminal delivery summaries
    older render contexts where exact reconstruction is still required
    older provider attempts

raw/diagnostic
    webhook payloads
    provider evidence
```

Do not introduce partitioning merely because a table may become large.

Consider date partitioning or archival only when:

- the table has substantial measured volume;
- most queries restrict by the partition key;
- retention can drop/archive whole ranges;
- operational and uniqueness constraints remain practical.

## DDL, backup, and restore policy

The architecture must account for whole-table operations before tables become enormous.

Before production rollout:

- keep high-volume row shapes narrow;
- avoid unnecessary secondary indexes;
- consolidate branch migrations while the project is pre-production;
- measure migration temporary-space needs;
- avoid late table rebuilds that merely remove obviously redundant JSON;
- establish backup/restore timing and free-space requirements;
- define raw payload/render-context retention before those tables accumulate indefinitely.

Deleting rows is not a substitute for an archival and space-reclamation plan. InnoDB files may not shrink automatically after deletion.

## Anti-shuffling rules

Reject any design that:

- renames `scheduled_messages.payload` to `scheduled_message_payloads.payload` and creates one row for every message;
- moves the current full `meta` object into a one-to-one metadata table;
- creates one row per token value when one bounded render-context JSON document is smaller and clearer;
- stores the same template content in templates, chain steps, broadcasts, and scheduled messages;
- stores the same chain rules in chain versions, enrollments, and scheduled messages;
- creates every future scheduled message at enrollment time without a real concurrent-send requirement;
- copies raw webhook payloads into inbound messages, suppressions, consent revocations, and automation events;
- stores IDs, keys, names, labels, config paths, and full snapshots for the same relationship;
- adds generic `meta` to new high-volume tables as a compatibility escape hatch;
- treats normalization as an excuse for unbounded join depth on every hot query.

## Completed implementation sequence

The persistence refactor was delivered incrementally:

```text
15A
    immutable terminal-result event contract

15B1
    durable terminal outbox and exact attempt ownership

15B2A
    stop duplicate parent terminal writes

15B2B
    remove parent terminal columns and fallback readers

15B3
    normalize Broadcast recipient terminal bookkeeping

15B4
    delete obsolete PendingMessageDeliveryConsolidator
```

Earlier runtime batches also established immutable templates/versions, immutable chains/versions, Webinar bindings, lazy chain execution, render contexts, and relational components.

Separate future projects remain:

```text
Campaign full MessageChain migration
Broadcast content/version and single-FK migration
FlowRoutes direct template/chain identity cleanup
Inbound raw-provider persistence cleanup
measured retention/pruning/index tuning
```

Those projects must not be relabeled as unfinished core 15B terminal persistence.

The post-15B schema is stable enough to resume the versioned DB snapshot/export/import safety tool. That tool must preserve current transitional Campaign/Broadcast fields and explicitly reject or translate removed legacy ScheduledMessage terminal columns.

## Implemented acceptance results and deferred targets

### Implemented

```text
template edits create immutable versions
chain edits create immutable versions
Webinar chain duplication is copy-on-write
Webinar runtime creates MessageChainEnrollments
normal chain execution materializes only the actionable wave
chain-created ScheduledMessages do not copy template/profile/condition snapshots
runtime token values freeze lazily in render contexts
consent composition uses relational component rows
delivery attempts own claims/provider outcomes
terminal outbox owns occurrence/reason
ScheduledMessageTerminalResult has no parent-column fallback
BroadcastRecipient has one bounded terminal_reason and no meta.delivery snapshot
```

### Current bounded compatibility contract

`scheduled_messages.payload` and `scheduled_messages.meta` still exist. Acceptance now means:

- versioned template content is not copied into payload;
- payload retains only canonical runtime differences/operational values;
- tokens move to the lazy render-context row;
- send-time-only permission-invitation URL/CTA overlays remain transient and are not written back into ScheduledMessage payload;
- metadata is canonical, bounded, and free of provider/terminal history and copied definitions.

### Deferred module targets

- Campaign enrollment does not yet wrap generic MessageChainEnrollment;
- Broadcast scheduling now pins one private immutable template version per Broadcast; `broadcasts.payload` remains the transitional draft/source copy until the authoring schema is migrated;
- BroadcastRecipient still uses `scheduled_message_ids` JSON;
- inbound provider normalization remains separate work;
- complete removal of ScheduledMessage compatibility JSON requires a future measured cutover, not a documentation-only declaration.

## Remaining measurement work

The completed refactor should now be measured against production-shaped data:

```text
average and p95 ScheduledMessage payload/meta size
average and p95 template-version content size
average and p95 render-context size
ScheduledMessages created per workflow after lazy materialization
secondary-index size on ScheduledMessage, attempts, outbox, and chain enrollments
backup/restore and migration temporary-space requirements
raw webhook retention volume
```

Those measurements may justify later retention, indexing, Broadcast/Campaign migration, or final compatibility-field removal. They should not reopen immutable definition ownership or attempt/outbox terminal authority without a correctness issue.