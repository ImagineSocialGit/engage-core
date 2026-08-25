# InboundMessaging Module

## Status

InboundMessaging is a reusable capability module.

The current raw SMS request copy in `inbound_messages.meta`, full email event data copied into suppression/revocation metadata, and overlapping inbound/webhook receipt identity are transitional persistence debt.

The approved target stores one raw provider receipt and one normalized inbound business record.

## Reply correlation foundation

Normal replies may carry narrow first-class correlation evidence back to the originating `ScheduledMessage`. Email correlation may be exact through a signed per-message Reply-To identity; SMS correlation is explicitly heuristic and bounded to recent sent deliveries for the same Contact/destination. Received email `subject` and RFC `message_id` are first-class normalized fields so CRM replies can preserve the visible subject and emit standard `In-Reply-To` / `References` threading headers while continuing to use a newly signed Engage Reply-To identity for the next inbound correlation hop. `reply_intent_key` is deterministic classification evidence, not an automatic business outcome.

## Reply Handling authority

InboundMessaging owns the durable reply vocabulary used to classify ordinary correlated replies:

```text
inbound_reply_profiles
    one outgoing-conversation vocabulary

inbound_reply_intents
    normalized business meanings such as high_intent, later, or no

inbound_reply_rules
    exact-reply and bounded keyword rules
```

The database is runtime authority. `inbound_messaging.reply_profiles` is an idempotent bootstrap source, and `messaging.reply_profiles` remains a temporary compatibility bootstrap source for existing clients. `presets:sync` imports those definitions without overwriting customized database rows unless `--force-reply-profiles` is explicit.

The CRM **Reply Handling** workspace is the authoritative human editing surface. It shows every profile and intent, the exact/keyword recognition rules, and dependencies contributed by Messaging and Flow Routes. Rule changes affect future replies only; historical `InboundMessage.reply_intent_key` evidence is not rewritten.

Profile keys are immutable. A referenced profile cannot be disabled or removed, and a referenced intent cannot be disabled or removed. Recognition phrases and labels remain editable while referenced so operators can correct future classification without rebuilding Campaigns or Routes.

The neutral `inbound_message.normal_reply` event exposes compact correlation/profile/intent identity for optional automation consumers. InternalNotifications remains free to notify a human even when no automation route exists. Domain-specific labels, tags, statuses, tasks, acknowledgements, and other consequences remain configuration/owning-module behavior rather than InboundMessaging features.

## Responsibility

InboundMessaging owns:

- inbound SMS and email webhook entry points;
- provider webhook handler resolution;
- normalized inbound-message recording;
- sender resolution;
- inbound message classification;
- purpose/scope resolution;
- inbound handler routing;
- `InboundMessageReceived`;
- neutral `inbound_message.normal_reply` automation events.
- reply-profile, reply-intent, and reply-rule persistence and authoring.

InboundMessaging may depend on:

- Core, for resolving Contacts as senders;
- Messaging, for consent, suppression, STOP, HELP, and channel/purpose semantics;
- shared webhook-inbox infrastructure.

InboundMessaging does not own:

- outbound scheduled delivery;
- TeamMember notification preferences;
- internal-notification routing;
- FlowRoute progression;
- raw provider payload archives outside the webhook inbox.

## Raw webhook ownership

`webhook_inbox_receipts` is the canonical raw provider-ingestion boundary.

It owns:

```text
client/provider identity
provider event identity
signature/payload fingerprints
raw or deliberately stored provider payload
claim/retry/completion state
processing outcome
last error
```

A provider request must not be copied into:

```text
inbound_messages.meta
message_suppressions.meta
consent_revocations.meta
automation-event payloads
provider-specific debug arrays on normalized rows
```

Raw webhook retention must be explicit.

Normalized business/compliance records may outlive raw receipts.

## Target inbound-message schema

Conceptual fields:

```text
id
webhook_inbox_receipt_id nullable
sender_type nullable
sender_id nullable
client_key nullable
channel
provider
provider_event_id nullable
provider_message_id nullable
provider_context_id nullable
message_id nullable
provider_event_key nullable unique
provider_message_key nullable unique
from_type nullable
from_value nullable
to_type nullable
to_value nullable
subject nullable
body nullable
classification
purpose nullable
scope nullable
received_at nullable
processed_at nullable
timestamps
```

Rules:

- raw request/event data is absent;
- no generic `meta` column is planned;
- provider hash keys enforce normalized idempotency directly on the business record;
- the webhook receipt FK connects normalized state to the one raw ingestion record;
- provider event/message IDs remain first-class for support and reconciliation;
- email `message_id` is the RFC Message-ID used for standards-based reply threading, not the provider API resource ID;
- email `subject` is normalized display/reply context and remains nullable for non-email channels and legacy rows;
- sender remains a generic morph;
- body is normalized inbound content, not a raw provider envelope.

## Inbound receipt consolidation

The current `inbound_message_receipts` table duplicates provider identity and processing state already represented by the webhook inbox plus the normalized inbound record.

Target direction:

```text
webhook_inbox_receipts
    provider request idempotency, claims, retry, raw payload, processing result

inbound_messages
    normalized message idempotency through unique provider event/message hash keys
```

Remove `inbound_message_receipts` after every inbound-message-producing provider path uses the canonical webhook inbox and direct unique identity on `inbound_messages`.

A non-webhook importer or manual ingestion path must either:

- create a canonical webhook/source receipt equivalent; or
- use the same explicit normalized provider/source identity keys.

Do not keep a second receipt table merely for compatibility symmetry.

## SMS normalization

SMS handling should extract only:

```text
provider/event/message/context IDs
normalized from/to phone values
normalized body
classification
purpose/scope
received time
sender Contact when resolvable
```

Current copies such as:

```text
event_type
source
IP address
user agent
raw request
```

belong on the webhook receipt or short-lived operational logs, not `inbound_messages.meta`.

IP/user-agent evidence should be retained only when a concrete security/compliance requirement exists and then in the canonical receipt, not copied across normalized rows.

## Email suppression and revocation

Email bounce, complaint, provider-suppression, failure, and unsubscribe handling should extract durable facts.

Suppression target evidence:

```text
channel
normalized destination
reason code
provider
source_event_id
suppressed_at
released_at nullable
```

Revocation target evidence:

```text
contact/message-consent relationship
channel
purpose
consent domain
reason code
provider/source
source_event_id
revoked_at
```

Do not store the complete email webhook `data` object in suppression or revocation metadata when the webhook inbox already retains the provider event.

If exact provider evidence must outlive the raw receipt, add a narrow first-class evidence field or archive reference after the retention requirement is documented.

## Processing outcome

Webhook processing outcome should remain compact.

Good:

```text
inbound_message_id
suppression_id
revocation_id
response code/category
```

Avoid returning or persisting a second copy of the provider request inside `webhook_inbox_receipts.outcome`.

## Internal notification boundary

InboundMessaging records the message and emits `InboundMessageReceived`.

InternalNotifications may listen and decide whether/how to notify TeamMembers.

InboundMessaging must not import InternalNotifications.

Normalized inbound records should not store notification-routing state.

## Automation event and opportunity-evidence boundary

For a normal Contact reply:

```text
InboundMessaging records InboundMessage
InboundMessaging emits inbound_message.normal_reply
shared automation outbox persists compact event identity
FlowRoutes may react through AutomationEventRecorded
Automation Opportunities may retain selected compact evidence
```

Automation payload should contain only stable normalized facts needed by consumers, such as:

```text
inbound_message_id
contact_id
channel
classification
purpose
scope
received_at
```

Do not copy body text or raw provider payload into the generic automation event by default.

The inbound message remains the canonical source for the normalized body.

## Retention

Suggested boundaries:

```text
webhook raw payload
    short explicit provider/debug retention

normalized inbound message
    product/reporting retention

suppression/revocation evidence
    policy/compliance retention

operational logs
    separate short log retention
```

Deleting raw receipts must not break normalized inbound history or compliance state.

## Setup validation

InboundMessaging validation should verify:

```text
configured provider handler exists
webhook verification requirements are present
canonical webhook inbox is used
provider identity can produce at least one stable idempotency key
normalized rows do not accept raw request/meta objects
suppression/revocation reason mappings are supported
automation events remain compact
```

## Migration boundary

The next migrations/models batch should:

- add the webhook-receipt FK and unique provider identity hashes to `inbound_messages`;
- remove target `inbound_messages.meta`;
- remove or mark `inbound_message_receipts` for deletion as part of the pre-production schema replacement;
- remove target suppression/revocation generic metadata where narrow fields are sufficient;
- add relationships and schema/model tests;
- leave webhook controllers/actions and runtime cutover for a later InboundMessaging batch.

The runtime cutover should then stop copying raw SMS/email provider data and route all provider requests through the canonical receipt boundary.