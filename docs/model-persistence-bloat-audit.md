# Engage Core — Messaging Persistence and Database Bloat Architecture

## Status

Approved target architecture.

This document replaces the earlier audit-only plan for Messaging, Campaigns, Broadcasts, Webinars, FlowRoutes message dispatch, and inbound-provider persistence.

The documentation batch defines the target. The next batch should change migrations and models only. Runtime writers, resolvers, jobs, controllers, and UI should move afterward in smaller module-focused batches.

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

The supplied Webinar confirmation row contains approximately:

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

## Approved Messaging definition schema

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

## Approved Messaging chain schema

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

## Approved chain runtime schema

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

## Approved scheduled-delivery schema

## `scheduled_messages`

Compact delivery execution record:

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

created_at
updated_at
```

Rules:

- `message_template_version_id` pins immutable content for every scheduled message, including one-off/direct sends;
- chain references are nullable because direct messages and Broadcasts may schedule without a chain enrollment;
- `origin` is the generic creator/business-owner relationship;
- `context` remains the about-this-record relationship;
- `destination` is resolved and frozen no later than first provider submission; pending messages may intentionally resolve the latest eligible recipient destination at claim time;
- `attempt_count` is an allowed small operational counter because claim/retry policy uses it frequently;
- no `payload`, `dispatch_keys`, `definition_config_path`, or generic `meta` column is planned;
- provider claim and provider-result details belong to attempts, not this row;
- the message row stores terminal summary only.

## `scheduled_message_render_contexts`

Irreducible per-delivery values:

```text
id
scheduled_message_id unique
values
content_hash
rendered_at
expires_at nullable
timestamps
```

Rules:

- this row is created lazily when provider-ready rendering begins;
- it contains only token values actually referenced by the pinned template version and optional composed components;
- it must not contain Eloquent model arrays, loaded relationships, config branches, duplicate context trees, or labels/provenance;
- messages with no runtime tokens need no row;
- retries reuse the same frozen render context;
- retention may differ from the hot scheduled-message row;
- exact customer-facing content is reconstructed from immutable template version + renderer version + frozen render values.

This one-to-one table is justified because it is not created for every planned row and it has a separate retention/archival boundary.

## `scheduled_message_components`

Optional consolidated content components:

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

Rules:

- no row is required for the primary template already referenced by `scheduled_messages.message_template_version_id`;
- rows exist only when additional content is deliberately composed, such as a consent acknowledgement;
- covered intent and consent identity become relational facts rather than a copied consolidation recipe;
- component content is immutable through its template-version FK;
- no generic `meta` column is planned.

## `scheduled_message_delivery_attempts`

Attempt-specific state:

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
destination
provider nullable
provider_message_id nullable
reason_code nullable
reason nullable
timestamps
```

Rules:

- claim leases and provider outcomes live here;
- `scheduled_messages` does not duplicate claim token, claim expiry, submission time, provider, provider message ID, or attempt reason;
- provider idempotency remains stable on the scheduled message when it identifies one logical delivery;
- provider-specific raw responses do not go into generic attempt metadata;
- a dedicated provider-evidence table or object-store reference requires a proven support/replay need and retention policy.

## `scheduled_message_outbox_events`

Keep the current bounded terminal outbox pattern.

It is operational, narrow, and one-row-per-message-event.

Do not add rendered content or module snapshots to it.

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

Target:

```text
Campaign
    owns campaign identity, activation, audience/enrollment intent, reporting

Campaign.message_chain_id
    selects the reusable chain for new Campaign enrollments

CampaignEnrollment
    thin Campaign-specific wrapper/correlation record

MessageChainEnrollment
    owns sequence progression and message execution
```

After cutover:

- Campaign steps and variants move into generic immutable chain versions.
- Campaign enrollment does not copy chain exit rules or start payloads.
- Campaign deactivation cancels active chain enrollments and skips pending scheduled deliveries through Messaging public actions.
- Campaign-specific reporting remains Campaign-owned.

## Broadcasts

Target:

```text
Broadcast
    owns one-time send identity, channel, recipient filter, schedule, status
    references one private or reusable MessageTemplate

published Broadcast
    pins one MessageTemplateVersion

BroadcastRecipient
    stores one nullable scheduled_message_id for the current single-channel contract
```

After cutover:

- Broadcast payload JSON is removed.
- Broadcast recipient scheduled-message ID arrays are removed.
- Delivery-result metadata is replaced by direct status/reason columns and the scheduled-message relationship.
- `recipient_filter` remains bounded low-volume Broadcast definition data.

## Webinars

Target:

```text
Webinars
    owns trigger bindings from registration/waitlist/attendance outcomes to MessageChains

MessageChain
    owns reusable timing, step, variant, and exit behavior

Messaging templates
    own immutable copy

MessageChainEnrollment
    owns each registration/outcome chain instance
```

After cutover:

- Webinar schedule profiles/items are replaced by generic message chains/versions/steps.
- Webinar series/occurrence selections become module-owned chain bindings.
- registration, waitlist, attended, and missed outcomes start the selected chains.
- Webinars does not copy profile, area, template, or condition descriptions into scheduled-message metadata.

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

## Implementation sequence

### Batch 0 — Documentation

Complete in this batch:

- lock the target persistence architecture;
- document MySQL-informed design rules;
- document module ownership and cutover boundaries;
- mark current preset/profile/step persistence as transitional.

### Batch 1 — Migrations and models

Next batch:

- replace pre-production migrations rather than add compatibility alters;
- add template/version models;
- add chain/version/step/variant/enrollment models;
- reshape scheduled-message, render-context, component, attempt, Broadcast, Campaign, Webinar binding, and inbound models;
- add factories and schema/model tests only;
- do not switch runtime writers yet.

### Runtime batches

Then proceed incrementally:

1. template/version sync and editing;
2. compact direct scheduling and rendering;
3. delivery claim/attempt cutover;
4. message-chain runner;
5. Campaign cutover;
6. Webinar cutover;
7. Broadcast cutover;
8. FlowRoutes template/chain actions;
9. inbound/webhook normalization cleanup;
10. historical import/backfill and legacy reader removal.

Each runtime batch should be small enough to prove behavior and row-size effects before continuing.

## Acceptance targets

### Definition cardinality

```text
editing one template
    creates one template version

editing one chain
    creates one chain version plus small child definition rows

duplicating one chain
    creates no recipient/runtime rows
    creates no copied template content until copy is customized
```

### Webinar registration example

For a ten-step registration chain:

```text
one MessageChainEnrollment
zero or one currently actionable ScheduledMessage wave under normal progression
no copied template body
no copied chain conditions
no copied catalog/profile labels
no generic ScheduledMessage metadata
render context created only when rendering begins
```

### Scheduled-message row budget

The hot scheduled row should consist almost entirely of fixed-width IDs/timestamps/status fields plus small classification/destination strings.

No JSON columns are planned on `scheduled_messages`.

### Persistence tests

Required future tests:

- immutable template edit does not alter existing scheduled delivery;
- immutable chain edit does not alter existing enrollment;
- chain duplication reuses template versions until copy changes;
- repeated template saves do not create unrelated assignments/catalog snapshots;
- only next actionable chain wave is materialized;
- scheduled rows contain no payload/meta/config snapshots;
- render context contains only referenced token values;
- retries reuse the same render context and destination;
- Broadcast content is stored once;
- Campaign enrollment does not copy chain rules;
- Webinar dispatch creates chain enrollment rather than all future payload rows;
- inbound normalized rows do not contain raw webhook data;
- raw provider content appears in one canonical receipt only.

## Remaining measurement work

The target architecture is approved, but actual implementation must still measure:

```text
average and p95 template-version content size
average and p95 render-context size
scheduled rows created per workflow before and after lazy materialization
secondary-index size on scheduled_messages and chain enrollments
backup/restore and migration temporary-space requirements
raw webhook retention volume
```

Those measurements may tune indexes and retention.

They should not reopen the core ownership decision unless they reveal a correctness problem.