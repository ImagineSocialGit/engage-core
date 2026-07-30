# Broadcasts Module

## Status

Broadcasts is optional.

The current Broadcast payload JSON and `broadcast_recipients.scheduled_message_ids` JSON array are transitional.

The approved target stores one authored message version per Broadcast and one compact ScheduledMessage relationship per recipient.

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

## Target BroadcastRecipient schema

Conceptual fields:

```text
id
broadcast_id
contact_id
scheduled_message_id nullable
status
sent_at nullable
skip_reason_code nullable
failure_reason_code nullable
timestamps
```

Rules:

- one Broadcast is single-channel, so one nullable `scheduled_message_id` is sufficient;
- remove `scheduled_message_ids` JSON;
- remove generic `meta`;
- use direct reason-code columns;
- provider failure detail remains on Messaging delivery attempts;
- BroadcastRecipient is Broadcast bookkeeping, not delivery ownership.

`broadcast_id + contact_id` remains unique.

## Scheduling flow

```text
operator schedules Broadcast
    lock/validate draft
    pin MessageTemplateVersion
    resolve Contacts from recipient_filter
    create BroadcastRecipients
    call Messaging public scheduling action per recipient
    create compact ScheduledMessage with:
        recipient = Contact
        context = Broadcast
        origin = BroadcastRecipient
        message_template_version_id = pinned Broadcast version
```

BroadcastRecipient stores the returned ScheduledMessage FK.

Messaging owns consent, destination, suppression, gates, rendering, claims, retries, provider delivery, and terminal events.

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

Messaging terminal events update BroadcastRecipient status.

Target recipient states may include:

```text
pending
scheduled
sent
skipped
failed
cancelled
```

Broadcast completes when every recipient is terminal.

Broadcast-level sent/skipped/failed counts may be maintained transactionally because they are frequent operational summaries and are tiny compared with copied payload/history objects.

Do not copy:

```text
provider response
provider message ID
delivery attempt history
ScheduledMessage failure text
full terminal event payload
```

into BroadcastRecipient metadata.

The scheduled-message and attempt relationships remain the authoritative delivery history.

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
1. choose channel
2. write the message
3. choose recipients
4. review exclusions and count
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

A future “Make a new Broadcast from this” action can:

```text
create a new Broadcast
create or reuse a private template identity according to copy-edit intent
start with a new draft version
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

Large content is stored once in immutable template versions.

Do not retain copied rendered bodies per recipient unless Messaging's separate render-context retention contract requires exact reconstruction.

## Migration boundary

The next migrations/models batch should:

- replace Broadcast payload storage with template/template-version FKs;
- replace recipient ScheduledMessage ID arrays with one nullable FK;
- replace scheduling/delivery metadata with first-class count/reason columns;
- remove generic Broadcast/BroadcastRecipient metadata from the target schema;
- add model relationships and schema tests;
- keep current scheduling actions/listeners operational until later runtime batches;
- use pre-production migration replacement rather than compatibility alter migrations.

The runtime cutover should then migrate draft editing, scheduling, result reconciliation, cancellation, and CRM presentation in focused Broadcast batches.