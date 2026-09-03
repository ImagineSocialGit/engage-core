# InboundMessaging Module

## Status

InboundMessaging is a reusable capability module.

The canonical inbound persistence cutover is complete for normalized inbound messages. Provider webhook ingestion stores one canonical raw receipt in `webhook_inbox_receipts`, while `inbound_messages` stores only normalized business/message facts. The former `inbound_message_receipts` table and generic `inbound_messages.meta` column are removed.

Messaging-owned suppression/revocation evidence remains a separate compliance concern and is not duplicated onto normalized inbound rows.

## Reply correlation foundation

Normal replies may carry narrow first-class correlation evidence back to the originating `ScheduledMessage`. Email correlation may be exact through a signed per-message Reply-To identity; SMS correlation is explicitly heuristic and bounded to recent sent deliveries for the same Contact/destination. Received email `subject` and RFC `message_id` are first-class normalized fields so CRM replies can preserve the visible subject and emit standard `In-Reply-To` / `References` threading headers while continuing to use a newly signed Engage Reply-To identity for the next inbound correlation hop. `reply_intent_key` is deterministic classification evidence, not an automatic business outcome.

## Inbound email route authority

InboundMessaging also owns durable semantic mailbox routes for inbound email that does not correlate to an Engage-originated ScheduledMessage. The database table `inbound_email_routes` is runtime authority. `INBOUND_EMAIL_DOMAIN` remains deployment/DNS infrastructure; route rows own only the local-part and durable internal routing context.

The CRM **Inbound Addresses** workspace is the authoritative human editor for these rows. The operator experience is deliberately nontechnical: an admin names the address, chooses its mailbox/local-part, and enables or disables it. Internal route key/source/context fields remain durable implementation metadata and are not operator-facing concepts. Existing integration-owned source/context values are preserved when an address is edited. The configured receiving domain is displayed read-only and remains deployment configuration.

The `reply+` local-part namespace is reserved for signed Engage Reply-To identities and may not be used by semantic inbound routes. Runtime route resolution also ignores that namespace defensively, so a direct/imported row cannot shadow exact signed reply correlation.

Example:

```text
website-forms@{INBOUND_EMAIL_DOMAIN}
    displayed as: Website Forms
```

Signed Engage Reply-To correlation always wins. Only a non-correlated recipient address is considered for semantic route resolution. When a route resolves, the normalized inbound row snapshots the stable route key/source/context in narrow first-class columns so historical evidence does not depend on a later route edit or deletion.

A resolved route emits the compact neutral `inbound_email.route_received` automation event. It may have no Contact yet; provider/domain integration code may use the internal route context to parse the normalized inbound message and establish Contact/business state later. The event never copies the inbound body or raw provider payload.

### Optional Flow Routes handoff

When Flow Routes is enabled, the Inbound Addresses workspace may hand an operator into normal Flow Route authoring for one named inbound address. The trigger authoring contribution is owned by InboundMessaging and selects the human-facing address while storing only its stable route key in the Route entry condition. InboundMessaging does not import FlowRoutes. The optional workspace adapter lives under `App\Support\ModuleIntegrations\InboundMessaging\FlowRoutes` and uses the FlowRoutes-owned authoring-link builder.

The same trigger is available from the Flow Routes create surface, so an automation created from Inbound Addresses and one created directly in Flow Routes use the same `inbound_email.route_received` event plus the same `automation_event.payload.inbound_message.inbound_email_route_key` condition. The Inbound Addresses workspace also shows matching current Flow Routes and links back to their authoritative editor.

The Inbox remains additive and authoritative for human review regardless of automation. A named address with no routed-message consumer may still be described as Inbox-only business handling while separately having Flow Route automation. Contact-aware Flow Routes start only when the inbound event carries a Contact ID; contactless messages remain visible for human review or may be interpreted by an owning routed-message consumer that establishes domain identity and emits its own business event.

## Routed-message consumer seam

Named inbound addresses may optionally be connected to one owning business process through the provider-neutral `RoutedInboundMessageConsumer` seam.

The seam is deliberately additive to the Inbox:

```text
named inbound address
    -> normalized InboundMessage
    -> Inbox
    -> zero or one routed-message consumer
```

Zero consumers is valid. The address is **Inbox only** and remains useful for human review.

One consumer means the owning module/integration may interpret the normalized message, establish its own business identity, optionally return a related Contact, record its own durable truth, and emit its own neutral business event.

More than one consumer for the same active address is invalid. Setup validation reports the conflict, and runtime resolution fails closed rather than executing multiple business processes for one message.

Consumers claim durable internal route identity, not sender/body heuristics. A consumer may match the stable route key or other route identity it owns through configuration. Any operator-facing integration setup should present named inbound addresses by their plain-language labels and store the internal route identity invisibly; users must never be asked to type route keys.

`RoutedInboundMessageConsumeResult::handled()` means the owning consumer completed its business processing. InboundMessaging then records `processed_at` and may associate the returned Contact through `related_contact_id`. It does not change Inbox triage state; automation remains additive to human visibility.

`RoutedInboundMessageConsumeResult::unresolved()` means the consumer owns the address but could not establish enough business identity from that valid message. The message remains unprocessed and visible as ordinary Inbox work. Consumers should throw only for retryable/system failures so the canonical webhook inbox can retry the provider callback.

Consumer implementations must be idempotent for the `InboundMessage` primary key because provider retries may re-enter normalized processing after a failure.

Provider-specific parsing and domain mutation stay outside InboundMessaging. Cross-module optional behavior should use an app-level integration adapter when importing the InboundMessaging contract directly would create an invalid module dependency.

The Inbound Addresses workspace presents only human outcomes:

```text
Inbox only
Connected to: <business-process label>
Needs setup attention
```

It never exposes consumer keys or route identity to CRM operators.

## Inbox authority

InboundMessaging owns a durable human-review Inbox for normalized inbound email and SMS. The Inbox is the baseline product behavior, not an automation fallback: every ordinary inbound reply or routed inbound email has a human-readable home even when FlowRoutes, Tasks, InternalNotifications, or a domain-specific integration are unavailable.

Inbox state is intentionally small:

```text
new       -> Needs review
reviewed  -> In progress
done      -> Done
```

New ordinary replies enter `new`. Compliance/system-handled classifications such as STOP/START/HELP/ignored enter `done` after capture because their primary system consequence is already deterministic. Historical messages that predate the Inbox are backfilled to `done` during the Inbox migration so deployment does not manufacture a historical work queue.

`sender_type` / `sender_id` continue to mean who actually sent the message. `related_contact_id` is a separate optional human association for a message that is about a Contact even when the transport sender is a vendor or external system. Manually linking or creating a Contact from the Inbox does not rewrite sender provenance and does not retroactively emit automation events.

The Inbox presents business-language context:

```text
From
Received through
Related person
Received at
Needs review / In progress / Done
```

`Received through` is derived from the friendly Inbound Address label, the friendly Reply Handling profile label, or a simple Email/Text fallback. Internal route keys, event keys, IDs, provider context, and other implementation details are not operator-facing Inbox concepts.

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
- neutral `inbound_message.normal_reply` automation events;
- semantic inbound-email route persistence/resolution and neutral `inbound_email.route_received` automation events;
- durable inbound Inbox triage and related-Contact association;
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

## Normalized inbound-message schema

Conceptual fields:

```text
id
webhook_inbox_receipt_id nullable
sender_type nullable
sender_id nullable
related_contact_id nullable
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
inbound_email_route_key nullable
inbound_email_route_source nullable
inbound_email_route_context nullable
received_at nullable
processed_at nullable
inbox_status
reviewed_at nullable
completed_at nullable
timestamps
```

Rules:

- raw request/event data is absent;
- no generic `meta` column exists;
- provider hash keys enforce normalized idempotency directly on the business record;
- the webhook receipt FK connects normalized state to the one raw ingestion record;
- provider event/message IDs remain first-class for support and reconciliation;
- email `message_id` is the RFC Message-ID used for standards-based reply threading, not the provider API resource ID;
- email `subject` is normalized display/reply context and remains nullable for non-email channels and legacy rows;
- sender remains a generic morph;
- body is normalized inbound content, not a raw provider envelope.

## Inbound receipt consolidation

The consolidation is complete.

```text
webhook_inbox_receipts
    provider request idempotency, claims, retry, raw payload, processing result

inbound_messages
    normalized message identity and business state
    provider_event_key / provider_message_key enforce normalized idempotency
    webhook_inbox_receipt_id links back to canonical ingestion when one exists
```

Email and SMS webhook entry points use the shared webhook inbox before normalized processing. SMS uses the provider event ID as the canonical callback identity and falls back to the provider message ID when the provider does not supply a separate event ID. Duplicate webhook deliveries therefore replay the stored canonical webhook outcome instead of rerunning inbound business processing.

`ProcessInboundMessageAction` uses `processed_at` only as the normalized business-processing completion guard. Retry/claim/error state belongs exclusively to `webhook_inbox_receipts`.

A non-webhook importer or manual ingestion path may have a null `webhook_inbox_receipt_id`, but it must provide a stable provider/source event or message identifier so the same normalized provider hash keys prevent duplicate `inbound_messages` rows.

Project State keeps `webhook_inbox_receipt_id` in the complete `inbound_messages` column contract, but classifies it with `null_on_import`. The source value may appear in an export because Project State requires complete schema coverage; it is cleared during import because canonical `webhook_inbox_receipts` are environment-local operational evidence and are not transferred. Durable normalized provider event/message identity remains in the first-class provider ID/hash columns.

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
capture scope/context when available
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

## Persistence boundary

Current persistence authority is:

```text
webhook_inbox_receipts
    one raw provider-ingestion receipt
    request identity + payload fingerprint
    claim/retry/completion + compact outcome

inbound_messages
    one normalized inbound business record
    direct event/message hash identities
    optional canonical webhook receipt link
    no raw request copy
    no generic meta
```

Provider webhooks must enter through the canonical webhook inbox before normalized processing. Normalized processing may fail and retry without creating a second receipt layer; `processed_at` remains null until business processing completes successfully.