# Inbound Reply Outcomes and Route Execution

## Purpose

InboundMessaging owns durable inbound capture, reply correlation, normalized reply intent, and SMS compliance handling.

FlowRoutes consumes the neutral `inbound_message.normal_reply` automation event through the shared automation-event seam. Neither module imports the other.

## Normal reply contract

A correlated normal reply may expose these compact automation facts:

```text
inbound_message.id
inbound_message.channel
inbound_message.classification
inbound_message.purpose
inbound_message.scope
inbound_message.scheduled_message_id
inbound_message.reply_profile_key
inbound_message.reply_intent_key
inbound_message.correlation_method
inbound_message.received_at
```

The inbound body remains on `inbound_messages`. It is not copied into the automation event.

`reply_profile_key` identifies the conversation/business reply vocabulary attached to the outbound ScheduledMessage.

`reply_intent_key` is a profile-owned normalized outcome such as `yes`, `later`, `no`, or another client/domain-defined intent.

Reply profiles may use:

- `exact`: short whole-reply phrases that must match before broader keywords;
- `keywords`: bounded phrase matching inside a reply.

Use `exact` for dangerous short outcomes such as `NO`; do not classify the word `no` as a broad keyword.

## SMS compliance is separate from business reply intent

SMS compliance keywords are classified before ordinary reply correlation:

```text
STOP family  -> consent_revocation
START family -> consent_grant
HELP family  -> help
everything else -> normal_reply
```

`YES` is not a compliance re-opt-in keyword. It remains available to client reply profiles as a business intent.

START only restores historical SMS consent domains whose latest revocation was a STOP revocation. It does not recreate permission after manual, preference, provider-unsubscribe, or other non-STOP revocations, and it does not invent a consent domain that the Contact never held.

## FlowRoutes event execution

For an automation-event-triggered Route, the complete event payload/meta graph is available only as transient point execution metadata:

```text
execution_meta.automation_event.payload...
execution_meta.automation_event.meta...
```

Example reply conditions:

```php
[
    'source' => 'execution_meta',
    'path' => 'automation_event.payload.inbound_message.reply_profile_key',
    'operator' => 'equals',
    'value' => 'cold_lead_nurture',
],
[
    'source' => 'execution_meta',
    'path' => 'automation_event.payload.inbound_message.reply_intent_key',
    'operator' => 'equals',
    'value' => 'yes',
],
```

The full event graph is not persisted to FlowRoutes progress, plan, or progress-item metadata. `automation_event_outbox_events` remains the durable owner of the event payload/meta.

If immediate Route execution exhausts its bounded execution budget and continues asynchronously, transient event data is intentionally not carried into the continuation job. Routes that need reply profile/intent must branch on those values in the initial execution slice, then persist durable business consequences through normal Route actions such as status/tag/task/Campaign operations.

## Attribution

InboundMessaging does not copy Campaign IDs or Campaign state into inbound rows or automation events.

Campaign provenance remains derivable through the correlated ScheduledMessage and Messaging MessageChain enrollment context.

## Persistence/bloat boundary

C5B intentionally removes SMS webhook source/IP/user-agent copies from `inbound_messages.meta` and removes duplicated provider/body evidence from STOP revocation metadata.

Raw provider request evidence belongs to the canonical webhook receipt. Durable normalized facts belong to `inbound_messages`, consent/revocation records, and the compact automation event.

## Reply-outcome business action capabilities

Business consequences remain owned by the module that owns the durable state. FlowRoutes only orchestrates the neutral capability/action seams.

Campaigns contributes:

```text
campaigns.enroll_contact
campaigns.cancel_enrollment
campaigns.pause_enrollment
campaigns.resume_enrollment
```

Pause/resume delegate to the same Campaign enrollment lifecycle actions used outside FlowRoutes. A reply Route may therefore pause a Campaign enrollment that was started by an import, another Route, or another supported Campaign entry path. Pausing may skip pending already-scheduled messages while preserving the enrollment so it can later resume.

Relationships contributes:

```text
relationships.change_stage
```

The relationship-stage action changes only an existing active Contact relationship. It does not create a missing relationship and does not reactivate an inactive relationship. That guard keeps business-role progression separate from Contact identity and prevents a Realtor reply Route from accidentally manufacturing relationship state. The target relationship type and stage must both exist, and the target stage must be active.

For authoring, relationship stage targets may be presented as one combined choice such as `Realtor — Engaged Agent`; the persisted Point definition remains normalized:

```php
[
    'relationship_key' => 'realtor',
    'stage_key' => 'engaged_agent',
    'on_missing_relationship' => 'skipped',
]
```

These capabilities add no new durable reply-outcome table or copied event payload. Campaign state remains in Campaigns/Messaging, relationship state remains in Relationships, and FlowRoutes retains only its normal execution/correlation records.

## CRM Contact conversation replies

The Contact workspace may answer a normal inbound message directly from the CRM.

Ownership remains split deliberately:

```text
InboundMessaging
    validates the selected inbound reply belongs to the Contact
    derives the existing channel / purpose / scope conversation context
    presents the Contact conversation rail and reply composer

Messaging
    remains the outbound delivery authority
    re-checks destination, consent, suppression, and recipient eligibility
    persists the ScheduledMessage
    queues provider delivery
    generates the signed email Reply-To identity for later correlation
```

The operator reply uses the same channel as the inbound message and never bypasses
MessageGate. Purpose and scope are reused from the inbound/correlated ScheduledMessage
when present. If scope is missing but Messaging explicitly maps the channel/purpose pair
to a consent domain, that canonical domain key is used as the reply scope. If purpose or
a safe scope/domain context still cannot be resolved, the composer is not sendable.

CRM replies are normal `ScheduledMessage` records with `message_type=conversation_reply`.
They retain only compact operational provenance (`surface=crm_contact_conversation`) and
reuse the existing ScheduledMessage dedupe key for form-submit idempotency. The inbound
body is not copied into ScheduledMessage metadata.

For email, InboundMessaging stores the received subject and RFC `Message-ID` as narrow
first-class fields. The visible CRM reply subject prefers the received subject, falls back
to the correlated outbound subject, and normalizes repeated reply prefixes to exactly one
`Re:`. When an RFC Message-ID is available, the ScheduledMessage carries it once as
canonical `in_reply_to` delivery data; EmailPayload/provider emission uses that value for
both standard `In-Reply-To` and `References` headers unless a richer References chain is
explicitly present. The
new outbound ScheduledMessage still receives its own signed Engage Reply-To address so
the recipient's next response correlates through the normal inbound path.