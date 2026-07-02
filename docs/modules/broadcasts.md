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


### Broadcast opt-in invitations

Broadcasts may provide a UI entry point for the imported-contact opt-in invitation, but the permission-invitation rules are Messaging-owned.

A permission invitation Broadcast should:

- use `channel = email`
- use `purpose = transactional`
- use `scope = permission_invitation`
- use `dispatch_key = imported_contact_permission_invitation`
- use `message_type = imported_contact_permission_invitation`
- use `recipient_filter = {"type":"imported"}` or a narrower Core-owned imported-contact filter such as `{"type":"import_batch","import_batch_ids":[...]}`

Broadcasts may provide an eligibility preview before scheduling imported-contact opt-in invitations.

The preview should be based on the same recipient-resolution path used for scheduling and may include:

```text
imported_contacts_count
already_consented_count
already_invited_count
ineligible_contacts_count
eligible_contacts_count
excluded_by_prior_broadcast_count
```

Permission invitation Broadcast scheduling should be blocked when no contacts are eligible.

Broadcast-side eligibility narrowing may exclude contacts that:

- already have relevant Messaging consent
- already have an imported-contact email permission invitation row
- are excluded by prior-Broadcast recipient exclusion rules

This Broadcast-side narrowing improves operator safety and preview accuracy.

It does not replace Messaging-owned one-time enforcement.

When a Broadcast uses `recipient_filter.type = import_batch`, the CRM should display selected import batch names/details when available instead of only raw batch IDs.

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
