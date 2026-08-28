# Broadcasts Module

## Status

Broadcasts is optional.

The Broadcast terminal-result normalization is implemented:

```text
BroadcastRecipient.status
BroadcastRecipient.sent_at for sent outcomes
BroadcastRecipient.terminal_reason for skipped/failed/cancelled business outcomes
no BroadcastRecipient.meta.delivery provider/attempt snapshot
```

Messaging delivery attempts and terminal outbox events remain authoritative for provider execution and exact terminal occurrence.

The broader Broadcast content refactor remains future work. `broadcasts.payload`, `broadcast_recipients.scheduled_message_ids`, and generic Broadcast metadata are still transitional. The approved future target stores one authored immutable message version per Broadcast and one compact ScheduledMessage relationship per recipient.

Current CRM authoring now supports explicit reusable-copy promotion without performing that persistence cutover. A regular Broadcast can be saved into Messaging's existing Message Templates catalog, which creates the canonical reusable `MessageTemplate` / immutable version and catalog presentation. Future Broadcasts may load a copy of the latest published reusable version into their current draft. The Broadcast still owns its current runtime `payload` until the separate persistence refactor is performed.

Regular Broadcast authoring also has an executable `broadcast_send` token context. It exposes only Contact fields that the current Messaging recipient-payload path actually materializes for Broadcast delivery: first name, last name, full name, email, phone, source, and subsource. The CRM editor presents those registered fields, validates submitted copy through Messaging's `MessageTemplateTokenValidator`, and stores any explicit `token_fallbacks` beside the current Broadcast copy. Unknown or context-incompatible fields are rejected before the Broadcast can be scheduled.

Personalization remains Messaging-owned at runtime. Broadcasts selects recipients and supplies the same tokenized payload to `DispatchMessageAction`; Messaging resolves Contact values independently for each recipient ScheduledMessage and applies the shared missing-field contract (`required`, `fallback_value`, or `replace_segment`) before provider rendering. A legacy/stale draft that somehow contains an invalid token is revalidated by `ScheduleBroadcastAction` before recipient snapshotting, so an unsafe draft cannot partially materialize a recipient set.

The reusable-message seam preserves this behavior: saving a Broadcast to Message Templates carries its `token_fallbacks` into the immutable Messaging version, and `ReusableMessageTemplateCatalog` returns those rules when a later Broadcast starts from that saved message. Permission invitations remain a separate Messaging-owned special path and do not use the regular Broadcast token editor.

## Responsibility

Broadcasts owns one-time and batch sends.

Campaigns and Broadcasts remain separate:

```text
Campaign
    enrolled multi-step journey with progression

Broadcast
    one-time single-channel send to a resolved recipient set
```

Broadcasts owns:

- Broadcast identity, name, schedule, status, and channel choice;
- recipient-filter definition;
- recipient resolution;
- prior-Broadcast exclusion behavior;
- BroadcastRecipient bookkeeping;
- Broadcast-level counts and completion;
- cancellation orchestration;
- CRM authoring and delivery visibility.

Broadcasts does not own:

- reusable general template infrastructure;
- immutable template rendering/version semantics;
- message-chain progression;
- Campaigns;
- consent or suppression;
- scheduled-message claims/jobs/provider delivery;
- imported-contact permission-invitation policy.

Broadcasts may depend on Core and Messaging.

## Single-channel contract

A normal Broadcast represents one channel and one content shape.

```text
Email Broadcast
    subject + body/structured email content

SMS Broadcast
    message
```

Do not make a Broadcast implicitly multi-channel.

Operators create separate Broadcasts for separate channels and may use exclusion rules to avoid duplicate outreach.

Future fallback/channel-strategy work must be modeled deliberately rather than hidden inside one Broadcast.

## Target content ownership

Every Broadcast should use a Messaging-owned `MessageTemplate`.

A normal one-off Broadcast may create a private template owned by the Broadcast authoring workflow.

Target relationship:

```text
Broadcast.message_template_id
    stable editable draft identity

Broadcast.message_template_version_id nullable
    immutable version pinned when scheduled/published
```

The draft editor may create new private template versions as the operator edits.

Scheduling pins exactly one immutable version.

Existing recipient deliveries never change when the draft is edited later.

The private template may remain hidden from the general reusable-template browser unless an operator explicitly promotes or duplicates it into reusable library content.

The current pre-cutover CRM implementation supports that explicit promotion from an existing regular Broadcast. Promotion creates a separate CRM-authored Messaging template/catalog identity; it does not attach the source Broadcast to that template and does not make the reusable template the runtime owner of the existing Broadcast payload.

Remove the target `broadcasts.payload` JSON column.

Do not move the same content into `broadcasts.meta` or a one-to-one Broadcast payload table.

## Target Broadcast schema

Conceptual fields:

```text
id
user_id nullable
message_template_id
message_template_version_id nullable
name
channel
purpose
scope
status
send_at nullable
recipient_filter json nullable
recipient_count
scheduled_count
sent_count
skipped_count
failed_count
cancelled_at nullable
completed_at nullable
completion_reason_code nullable
timestamps
```

Rules:

- `message_template_version_id` is required before scheduling;
- channel must match the pinned template;
- purpose/scope remain first-class because they classify the one-time send and drive consent/gating;
- recipient counts are small operational summaries justified by frequent list/detail queries;
- `recipient_filter` remains bounded low-volume definition data;
- no generic `meta` column is planned;
- dispatch key, payload class, and queue should not be copied onto Broadcast when Messaging can derive renderer behavior and the scheduling action supplies the queue/classification contract.

## Recipient filters

Use recipient-oriented terminology:

```text
recipient_filter
BroadcastRecipientResolver
BroadcastRecipient
recipients
```

Avoid `audience` terminology in Broadcast internals.

Current supported filter concepts may remain:

```json
{"type":"all"}
```

```json
{"type":"tag","tags":["homebuyer"]}
```

```json
{"type":"contact_ids","contact_ids":[1,2,3]}
```

```json
{"type":"imported"}
```

```json
{"type":"import_batch","import_batch_ids":[1,2,3]}
```

Broadcast-owned exclusions may remain:

```json
{
  "type":"all",
  "exclude":{
    "broadcast_ids":[12,13],
    "statuses":["scheduled","sent"]
  }
}
```

`recipient_filter` is acceptable JSON because:

- one definition is stored per Broadcast;
- it is not copied per recipient;
- its schema is closed and validated;
- Core still owns generic Contact filter resolution;
- Broadcasts owns prior-Broadcast exclusion semantics.

Do not copy the resolved Contact ID list into the Broadcast row after BroadcastRecipient rows are created.

Composite audience criteria remain inside the existing `recipient_filter` contract:

```json
{
    "type": "criteria",
    "criteria": {
    "status": ["3"],
    "relationship": ["realtor:target_agent"],
    "source": ["Database"]
    }
}
```

Criteria use AND between criterion categories and OR between selected values inside one category.

Core owns the generic `ContactFilterCriterion` / registry/query seam. Core contributes generic source, subsource, tag, and import-batch criteria. Optional modules such as Workflow and Relationships may contribute their own criteria through that Core contract. Broadcasts consumes the generic Core seam and must not import those modules directly.

Empty, unknown, or stale criteria fail closed to zero Contacts rather than broadening to all Contacts.

The one-time imported-contact permission invitation remains Messaging-owned. Broadcasts may surface that special path only when the existing canonical eligibility preview reports eligible Contacts.

## BroadcastRecipient persistence

Current fields:

```text
id
broadcast_id
contact_id
status
scheduled_message_ids json nullable
sent_at nullable
terminal_reason nullable
meta json nullable
timestamps
```

Current rules:

- `status` is compact Broadcast-owned bookkeeping;
- `sent_at` records the Broadcast-owned sent outcome;
- `terminal_reason` stores one bounded business reason for skipped, failed, or cancelled recipients;
- provider, provider message ID, attempt number, exact terminal occurrence, and attempt history remain Messaging-owned;
- `meta.delivery` snapshots are forbidden and removed during terminal reconciliation;
- unrelated bounded Broadcast-owned metadata may remain until the broader Broadcast schema redesign.

Remaining target:

```text
scheduled_message_id nullable
```

Because a normal Broadcast is single-channel, the eventual relationship should replace the current ScheduledMessage ID array with one nullable FK. That future migration should also decide whether any remaining generic recipient metadata has independent Broadcast value rather than moving the same data elsewhere.

## Scheduling flow

```text
operator schedules Broadcast
    lock/validate draft
    pin MessageTemplateVersion
    resolve the recipient query from recipient_filter
    snapshot eligible Contacts into BroadcastRecipients

small recipient set
    schedule the snapshotted recipients immediately

large recipient set
    queue one bulk chunk job for the requested send time
    process at most the snapshotted chunk size
    release the next chunk only after the snapshotted interval

for each processed recipient
    call Messaging public scheduling action
    create compact ScheduledMessage with:
        recipient = Contact
        context = Broadcast
        origin = BroadcastRecipient
        message_template_version_id = pinned Broadcast version
```

Recipient snapshotting is query-based and must not materialize the entire Contact collection in PHP. The snapshot is durable before chunk delivery begins, so later tag/filter changes do not silently alter an already scheduled Broadcast. Existing `(broadcast_id, contact_id)` uniqueness makes snapshot retry idempotent.

Large-send chunk size and release interval are Messaging-owned operational policy. The values are snapshotted into Broadcast scheduling metadata when scheduling begins so an in-flight Broadcast does not change behavior if process configuration is tuned later. Only one continuation chunk is queued at a time. Those producer-level chunk details remain on the Broadcast and are not duplicated onto every recipient ScheduledMessage.

Bulk Broadcast deliveries use the Messaging-owned `bulk_messages` queue. Horizon isolates that queue on a dedicated supervisor while retaining the configured environment max-process value as the total worker budget across the primary and bulk supervisors. Messaging separately enforces shared provider submission limits at the send boundary, so Broadcast chunk pacing does not pretend to be the provider rate limiter.

BroadcastRecipient stores the returned ScheduledMessage relationship using the current transitional ID array until the planned single-FK persistence cleanup.

Messaging owns consent, destination, suppression, gates, rendering, claims, retries, provider delivery, bulk-delivery queue policy, and terminal events.

Broadcasts does not create ScheduledMessages directly.

## Immediate terminal behavior

The scheduling transaction must handle zero-message outcomes.

```text
no eligible recipients
    Broadcast completes with no_eligible_recipients

eligible recipients but Messaging schedules nothing
    Broadcast completes with no_messages_scheduled

at least one ScheduledMessage remains capable of terminal delivery
    Broadcast remains scheduled
```

Do not leave a Broadcast waiting for an event that cannot occur.

The existing `broadcasts.meta.scheduling` summary should move to first-class count and completion-reason columns.

## Delivery reconciliation

Messaging publishes terminal events whose immutable result resolves through `ScheduledMessageTerminalResult`.

`BroadcastScheduledMessageResultRecorder` maps that result into compact Broadcast bookkeeping:

```text
sent
    status = sent
    sent_at = terminal occurred_at
    terminal_reason = null

skipped
    status = skipped
    sent_at = null
    terminal_reason = bounded reason/reason_code fallback

failed
    status = failed
    sent_at = null
    terminal_reason = bounded reason/reason_code fallback
```

Reconciliation is idempotent and strips any legacy `meta.delivery` snapshot while preserving unrelated Broadcast-owned metadata.

Broadcast completes when every recipient is terminal. Provider execution detail remains available through the ScheduledMessage relationship, delivery attempts, and terminal outbox—not copied recipient metadata.

## Cancellation

Broadcast cancellation should:

```text
set Broadcast cancelled
use Messaging public skip action for pending ScheduledMessages
mark recipients cancelled or mirror resulting skipped outcomes
leave sending/sent/failed/previously skipped deliveries unchanged
```

Broadcasts must not mutate Messaging claim/provider fields directly.

## Imported-contact permission invitations

Permission invitations remain Messaging-owned.

Broadcasts may expose a secondary entry point, but the action is not a normal Broadcast and must not inherit normal Broadcast bypass semantics.

Rules remain:

```text
one-time
email-only
imported Contact only
Messaging-owned eligibility and invitation record
normal Broadcasts remain consent-gated
```

No private Broadcast template/payload path should reimplement invitation token generation, acceptance URLs, one-time enforcement, or consent creation.

## CRM authoring UX

Recommended normal flow:

```text
1. choose recipients / audience
2. preview count, consent context, and prior Broadcast overlap
3. choose channel and write the message
4. review duplicate-send exclusions and timing
5. schedule/send
```

The Broadcast page may create/edit its private Messaging template behind this guided UI.

Operators should not need to understand template-version IDs.

Use business-facing content fields:

```text
Email
    subject
    body

SMS
    message
```

Keep duplicate-send protection secondary and collapsible.

Reusable message authoring is explicit:

```text
Save to Message Templates
    copy the regular Broadcast message into Messaging's reusable template/catalog infrastructure
    publish an immutable Messaging version
    expose the saved message on the existing Message Templates screen

Start from a saved message
    load the latest published reusable version into the current Broadcast draft
    editing the Broadcast does not mutate the saved template

Make a new Broadcast from this
    create a new draft Broadcast with the same channel/content
    clear audience, exclusions, schedule, and delivery counts
    require the operator to choose WHO again before scheduling
```

Do not persist clone lineage unless audit/reporting needs prove it useful.

## Setup validation

Broadcast validation should verify:

```text
channel is available for Broadcast authoring
purpose/scope are valid
recipient_filter shape is valid
pinned template version exists before scheduling
template channel matches Broadcast channel
Broadcast is not scheduled twice
recipient counts and terminal state remain coherent
normal Broadcast does not use permission-invitation bypass
```

Hard errors represent unsafe/impossible scheduling.

Warnings may represent empty or currently ineligible recipient sets before final scheduling.

## Reporting and retention

Broadcasts owns aggregate/recipient bookkeeping.

Messaging owns delivery history.

Old terminal BroadcastRecipient rows are small and may remain for reporting.

Current Broadcast content is stored once on the Broadcast row rather than copied per recipient. The future content refactor should pin one immutable Messaging template version.

Do not retain copied rendered bodies per recipient unless Messaging's separate render-context retention contract requires exact reconstruction.

## Remaining Broadcast migration boundary

The completed 15B3 work normalized recipient terminal outcomes only.

A separate future Broadcast persistence refactor may:

- replace Broadcast payload storage with stable template/template-version FKs;
- replace recipient ScheduledMessage ID arrays with one nullable FK;
- replace remaining scheduling metadata with justified first-class summaries;
- remove generic Broadcast/BroadcastRecipient metadata only after each retained value is audited;
- update authoring, scheduling, cancellation, and CRM presentation together in focused batches.

That work is not required to reopen the completed Messaging terminal-authority refactor. Export/import tooling should preserve or deliberately transform the current Broadcast payload and ScheduledMessage ID array until that separate migration exists.