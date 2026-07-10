
# InboundMessaging Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

InboundMessaging is a reusable capability module.

InboundMessaging owns inbound webhooks and inbound message recording/routing.

InboundMessaging owns:

- inbound messages
- inbound SMS webhook controller/action
- inbound email webhook controller/action
- inbound payload normalization
- inbound message classification
- inbound purpose resolution
- inbound sender resolution
- inbound handler routing
- inbound webhook handler resolver bindings
- `InboundMessageReceived` event

InboundMessaging may depend on:

- Core, for resolving contacts as senders
- Messaging, for STOP/HELP consent-related behavior

InboundMessaging does not own:

- internal notification routing
- TeamMember recipients
- internal notification preferences
- internal notification scheduling

InboundMessaging should not directly notify internal users.

Instead:

1. InboundMessaging records the inbound message.
2. InboundMessaging emits `InboundMessageReceived`.
3. InternalNotifications may conditionally listen to that event and schedule internal alerts.

InboundMessaging must not import InternalNotifications.

InboundMessaging may use Messaging-owned channel and purpose concepts when classifying inbound messages.

That dependency is acceptable because InboundMessaging depends on Messaging.

Inbound message records should remain generic and should not store internal notification routing state.

## Automation event and opportunity-evidence boundary

InboundMessaging records inbound messages and emits `InboundMessageReceived` after persistence.

For a normal reply from a known Contact, the current implementation also emits the neutral automation event:

```text
inbound_message.normal_reply
```

The event uses the `InboundMessage` as subject, includes the Contact ID, and carries compact channel/classification/purpose/scope context.

This event may be retained by shared Automation Opportunities infrastructure as evidence-only:

```text
AutomationEventRecorded(inbound_message.normal_reply)
    -> automation_event.recorded evidence
    -> no standalone opportunity by itself
```

A later manual Contact-associated Task within the current 10-minute correlation window may produce:

```text
task.created_after_automation_event
```

Example future suggestion:

```text
You've created "Call this contact" for 3 Contacts after they replied to a message.
Add that Task to the Route?
```

Do not emit opportunity evidence for:

```text
HELP replies
STOP / consent-revocation replies
unknown-sender normal replies without a resolved Contact
```

HELP and STOP are already handled deterministically by InboundMessaging/Messaging behavior and should not create opportunity noise.

InboundMessaging must not import FlowRoutes or own opportunity aggregation.

