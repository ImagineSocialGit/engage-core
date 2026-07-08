# Broadcasts Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Broadcasts is optional.

Broadcasts owns one-time and batch recipient sends.

Broadcasts owns:

- broadcasts
- broadcast recipients
- broadcast recipient filter metadata
- broadcast recipient state
- ad hoc one-time message payload/copy
- broadcast scheduling/orchestration behavior
- broadcast-specific metadata
- broadcast delivery bookkeeping

Broadcasts does not own:

- enrolled multi-step campaign journeys
- campaign definitions
- campaign steps
- campaign enrollments
- reusable message templates
- scheduled message delivery infrastructure
- message consent/suppression infrastructure
- message gates
- send jobs

Campaigns and Broadcasts are separate concepts.

Campaigns are enrolled, multi-step journeys with lifecycle/progression.

Broadcasts are one-time or batch sends to recipients.

Broadcasts may store ad hoc payload/copy because broadcasts are not reusable Campaign journeys.

Messaging owns reusable delivery infrastructure, scheduled messages, consent, suppression, gates, payload classes, queues, and send jobs.

Broadcasts may depend on:

- Core
- Messaging

Broadcast recipient selection should use recipient-oriented terminology.

Use:

    recipient_filter
    BroadcastRecipientResolver
    BroadcastRecipient
    recipients

Avoid:

    audience
    audience_filter
    BroadcastAudienceResolver

`broadcasts.recipient_filter` is the canonical storage for recipient selection metadata.

Current supported recipient filter shapes include:

    {
      "type": "all"
    }

    {
      "type": "tag",
      "tags": ["homebuyer"]
    }

    {
      "type": "contact_ids",
      "contact_ids": [1, 2, 3]
    }

    {
      "type": "imported"
    }

    {
      "type": "import_batch",
      "import_batch_ids": [1, 2, 3]
    }

`imported` is a Core-owned contact filter for contacts imported from another system. Current imported detection includes `source = import`, `meta.imported = true`, `meta.imported_at`, or a present `contact_import_batch_id`.

`import_batch` is a Core-owned contact filter for contacts from specific first-class `contact_import_batches` records. Use it when the operator needs to target one exact import file/run instead of all imported contacts.

Recipient filters may also include Broadcast-owned exclusions:

    {
      "type": "all",
      "exclude": {
        "broadcast_ids": [12, 13],
        "statuses": ["scheduled", "sent"]
      }
    }

`exclude.broadcast_ids` removes contacts that already have matching `BroadcastRecipient` rows for those prior Broadcasts.

`exclude.statuses` currently supports:

    scheduled
    sent

This is Broadcast-owned duplicate-send protection. It lets operators send separate single-channel Broadcasts, such as SMS first and email second, without hitting the same contact twice.

Core does not own prior-Broadcast exclusion logic.

Messaging does not own prior-Broadcast exclusion logic.

Broadcasts owns this because it is based on BroadcastRecipient bookkeeping.

Broadcasts may store recipient filter definitions, but Core owns generic contact lookup and generic contact filter resolution.

Broadcasts should not become the app-wide contact query engine.

Broadcasts may use Core-owned contact lookup/picker functionality for individual contact selection.

Good:

    route('crm.contacts.lookup')
    <x-crm.contact-picker />
    BroadcastRecipientResolver

Bad:

    BroadcastRecipientContactSearchController
    Broadcast-specific contact picker components
    duplicated contact lookup logic inside Broadcasts

Broadcast delivery metadata should be first-class on `broadcasts`:

    dispatch_key
    message_type
    payload_class
    queue

Broadcasts should use Messaging public actions/services to schedule or send messages.

Good:

    DispatchMessageAction
    ScheduleMessageAction

Bad:

    ScheduledMessage::query()->create(...)

Broadcasts should not depend on Campaigns.

Campaigns should not depend on Broadcasts.

Broadcasts may reference Messaging-owned scheduled messages for bookkeeping and visibility.

That reference is acceptable because Broadcasts depends on Messaging.

Broadcasts should still send or schedule through Messaging public actions/services.

`broadcast_recipients.scheduled_message_ids` is broadcast bookkeeping, not ownership of scheduled delivery infrastructure.

BroadcastRecipient records are Broadcast bookkeeping.

BroadcastRecipient records may store scheduled message IDs for visibility/audit, but they do not own the scheduled delivery lifecycle.

Current runtime direction:

    Broadcast UI/action creates or edits draft Broadcast
    Broadcast stores recipient_filter metadata
    Broadcast recipient resolver resolves Contacts from recipient_filter
    Broadcast schedule action creates BroadcastRecipients
    Broadcast schedule action calls Messaging public action/service
    Messaging creates ScheduledMessages
    BroadcastRecipient stores resulting scheduled_message_ids/status bookkeeping
    Broadcast listeners record sent/skipped/failed Messaging lifecycle events
    Broadcast completes when every BroadcastRecipient is terminal


### Imported-contact permission invitations

Current imported-contact permission invitation scheduling is Messaging-owned and exposed from Core import batch detail pages when Messaging is enabled.

Broadcasts do not own the current import-batch permission invitation scheduling path.

If a future Broadcast entry point is reintroduced, the permission-invitation rules remain Messaging-owned.

A normal Broadcast must not receive the imported-contact bypass.

Normal Broadcasts remain consent-gated by Messaging.

Broadcast cancellation should use Messaging-owned skip behavior for pending scheduled messages rather than mutating Messaging internals directly.

Good:

    CancelBroadcastAction -> SkipScheduledMessagesAction

Broadcasts should remain simpler than Campaigns.

Broadcasts are single-channel sends.

A single Broadcast should represent one channel and one payload shape.

Examples:

    Email Broadcast -> channel=email, payload.subject, payload.body
    SMS Broadcast -> channel=sms, payload.message

Do not make a normal Broadcast default to both email and SMS.

Multi-channel fallback or “use SMS if available, otherwise email” is future channel-strategy work and should be modeled deliberately if needed.

For now, operators should create separate single-channel Broadcasts and use prior-Broadcast recipient exclusions to avoid duplicate outreach across channels.

Do not add multi-step progression, enrollment lifecycle, or journey logic to Broadcasts.

If a send needs multiple sequenced steps, it belongs in Campaigns, not Broadcasts.

## CRM authoring UX direction

Broadcasts should stay simpler than Campaigns.

The authoring UI should guide one clear one-time send.

Recommended flow:

```text
1. Choose channel.
2. Write the channel-specific payload.
3. Choose recipients.
4. Review duplicate-send protection and schedule/send.
```

The selected channel should shape the payload:

```text
Email Broadcast
    subject
    body

SMS Broadcast
    message
```

Imported-contact opt-in invitations should not receive equal visual weight with normal Broadcast authoring.

They are a distinct Messaging-owned one-time permission flow. Expose them as a secondary action such as:

```text
Send opt-in invitation to imported contacts
```

When no contacts are eligible for the opt-in invitation, the UI should not show the full option/import-batch selection area. It should instead show a calm explanation that no imported contacts are eligible for invitation.

`Avoid Duplicate Sends` is useful but secondary. Prefer a collapsed section with a short summary unless the operator is actively changing exclusions.

A future action such as:

```text
Make a new broadcast from this
```

is useful for repeating a prior Broadcast to a different channel or audience. This can likely be a clone action without schema. Add lineage such as `cloned_from_broadcast_id` only if audit/debug/reporting needs prove lineage should be persisted.
