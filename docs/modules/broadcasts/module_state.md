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

Broadcast authoring and delivery now use first-class Messaging template relationships end to end. Each Broadcast owns one private Messaging `MessageTemplate`; draft edits publish immutable `MessageTemplateVersion` rows on that private identity, and scheduling pins the exact current version on `broadcasts.message_template_version_id`. Every recipient delivery pins that same immutable version while ScheduledMessage payload retains only per-delivery runtime differences such as destination/contact identity. `broadcasts.payload` and `broadcast_recipients.scheduled_message_ids` have been removed from the current schema.

Current CRM authoring also supports explicit reusable-copy promotion independently from the private runtime snapshot. A regular Broadcast can be saved into Messaging's existing Message Templates catalog, which creates the canonical reusable `MessageTemplate` / immutable version and catalog presentation. Future Broadcasts may load a copy of the latest published reusable version into their draft. Scheduling snapshots that resulting Broadcast copy into the private immutable runtime version without mutating the reusable library item.

Regular Broadcast authoring also has an executable `broadcast_send` token context. It exposes only Contact fields that the current Messaging recipient-payload path actually materializes for Broadcast delivery: first name, last name, full name, email, phone, source, and subsource. The CRM editor presents those registered fields, validates submitted copy through Messaging's `MessageTemplateTokenValidator`, and stores any explicit `token_fallbacks` in the private immutable Messaging version. Unknown or context-incompatible fields are rejected before the Broadcast can be scheduled.

Personalization remains Messaging-owned at runtime. Broadcasts selects recipients and supplies the pinned private template version to `DispatchMessageAction`; Messaging resolves Contact values independently when each recipient delivery renders and applies the shared missing-field contract (`required`, `fallback_value`, or `replace_segment`) before provider rendering. Recipient-derived token snapshots are not copied into version-pinned ScheduledMessage payload. `ScheduleBroadcastAction` revalidates the current private draft version before it is pinned or recipients are snapshotted, so invalid copy cannot partially materialize runtime state.

The reusable-message seam preserves this behavior: saving a Broadcast to Message Templates carries its `token_fallbacks` and optional primary email `cta` into the immutable Messaging version, and `ReusableMessageTemplateCatalog` returns those values when a later Broadcast starts from that saved message. Permission invitations remain a separate Messaging-owned special path and do not use the regular Broadcast token/CTA editor.

Regular email Broadcasts may author one primary CTA as a button label plus HTTP(S) destination. Broadcasts stores the canonical CTA in its private Messaging payload with the server-owned tracking key `primary`; operators do not author tracking keys or signed click URLs. The source destination stays in the immutable template version. When a recipient ScheduledMessage renders, Messaging uses the existing CTA tracking seam to replace the rendered destination with the signed click route while plain-text email carries the corresponding tracked URL. Draft/show previews use the source URL and do not record delivery engagement. `{cta}` may be placed in the body to control button placement; otherwise the email renderer appends the CTA after the body.

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

## Runtime content ownership

Every Broadcast now uses a Messaging-owned private `MessageTemplate` as its stable editable message identity. Draft saves publish immutable versions on that private template; scheduling pins one version on the Broadcast and every recipient delivery uses that exact version.

Current relationship:

```text
Broadcast.message_template_id
    stable editable draft identity

Broadcast.message_template_version_id nullable
    immutable version pinned when scheduled/published
```

The draft editor creates or reuses private template versions as the operator edits.

Scheduling pins exactly one immutable version.

Existing recipient deliveries never change when the draft is edited later.

The private template remains hidden from the general reusable-template browser unless an operator explicitly promotes the Broadcast copy into reusable library content.

The current CRM implementation supports that explicit promotion from an existing regular Broadcast. Promotion creates a separate CRM-authored Messaging template/catalog identity; it does not attach the source Broadcast to that reusable template. The Broadcast's private template remains its own authoring identity, not the reusable-library identity.

The former `broadcasts.payload` JSON column is removed. Content must not be copied into `broadcasts.meta` or a one-to-one Broadcast payload table.

## Current Broadcast schema

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
scheduled_message_id nullable
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

Because a normal Broadcast is single-channel, `scheduled_message_id` is one nullable FK to the recipient's ScheduledMessage. Terminal reconciliation resolves this first-class relationship first and may fall back to Broadcast/contact context defensively. Any future recipient cleanup should audit whether remaining generic metadata has independent Broadcast value rather than moving the same data elsewhere.

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
        behavior owner = Broadcast
        message_template_version_id = private pinned Broadcast version
```

Recipient snapshotting is query-based and must not materialize the entire Contact collection in PHP. The snapshot is durable before chunk delivery begins, so later tag/filter changes do not silently alter an already scheduled Broadcast. Existing `(broadcast_id, contact_id)` uniqueness makes snapshot retry idempotent.

Large-send chunk size and release interval are Messaging-owned operational policy. The values are snapshotted into Broadcast scheduling metadata when scheduling begins so an in-flight Broadcast does not change behavior if process configuration is tuned later. Only one continuation chunk is queued at a time. Those producer-level chunk details remain on the Broadcast and are not duplicated onto every recipient ScheduledMessage.

Bulk Broadcast deliveries use the Messaging-owned `bulk_messages` queue. Horizon isolates that queue on a dedicated supervisor while retaining the configured environment max-process value as the total worker budget across the primary and bulk supervisors. Messaging separately enforces shared provider submission limits at the send boundary, so Broadcast chunk pacing does not pretend to be the provider rate limiter.

BroadcastRecipient stores the returned ScheduledMessage through its singular nullable `scheduled_message_id` relationship. Dispatch returning more than one ScheduledMessage for a single-channel Broadcast is treated as a contract violation rather than being hidden in an array.

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

The CRM Broadcast detail surface follows the same ownership boundary. Recipient rows are
paginated in bounded pages and may be filtered by recipient status. The side diagnostic
surface shows only skipped/failed recipients; selecting one resolves its ScheduledMessage
terminal outbox event and delivery attempts on demand. It must not render a second generic
list of ScheduledMessages that merely duplicates recipient status.

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
    optional primary CTA label + HTTP(S) destination

SMS
    message
```

Keep duplicate-send protection secondary and collapsible.

Reusable message authoring is explicit:

```text
Save to Message Templates
    copy the regular Broadcast message, token fallback policy, and primary email CTA into Messaging's reusable template/catalog infrastructure
    publish an immutable Messaging version
    expose the saved message on the existing Message Templates screen

Start from a saved message
    load the latest published reusable version, including its primary email CTA, into the current Broadcast draft
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

Current Broadcast draft/source content remains stored once on the Broadcast row. At scheduling, that exact copy is also snapshotted once into a private immutable Messaging template version shared by every recipient delivery; authored copy and Contact token snapshots are not copied into each ScheduledMessage.

Do not retain copied rendered bodies per recipient unless Messaging's separate render-context retention contract requires exact reconstruction.

## Remaining Broadcast migration boundary

Broadcast terminal normalization and recipient-delivery version pinning are complete for the current runtime shape.

The Broadcast authoring/bookkeeping cutover is complete: message content is owned by the private Messaging template/version relationship and each recipient has one nullable ScheduledMessage FK. Remaining persistence work is narrower:

- replace remaining scheduling metadata with justified first-class summaries where a stable relational field is warranted;
- remove generic Broadcast/BroadcastRecipient metadata only after each retained value is audited.

Project State Broadcasts v2 transfers these first-class relationships. The runtime importer remains current-format-only, so refresh exports must be created from code using the current Project State contract.