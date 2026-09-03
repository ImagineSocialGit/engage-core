# InternalNotifications Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable module backlog in this directory's `TODO.md` when one exists.

InternalNotifications is a reusable capability module.

InternalNotifications owns team-facing notifications and notification preferences.

InternalNotifications owns:

- team members
- team member notification preferences
- internal notification gate
- internal notification channel resolver
- internal notification recipient objects
- internal notification scheduling action
- internal notification preference resolvers
- inbound-message notification listener
- TeamMember-specific Messaging adapters

InternalNotifications may depend on Messaging.

InternalNotifications may conditionally integrate with InboundMessaging through events/listeners when both modules are enabled.

InternalNotifications contributes TeamMember support to Messaging through:

- `TeamMemberMessageRecipientGate`
- `TeamMemberMessageRecipientPayloadProvider`

InternalNotifications owns when an internal notification should exist, how team recipients/channels are resolved, and any InternalNotifications-specific trigger or timing behavior. Messaging remains the generic delivery capability.

When an internal notification uses the shared resolved-dispatch path, InternalNotifications should provide the resolved send time/behavior to `ResolvedMessageDispatchBuilder`; reusable Messaging content must not secretly own Team notification cadence or trigger conditions. Existing direct scheduling paths with an explicit `sendAt` remain architecturally valid when they already provide a complete Messaging scheduling contract.

Core contacts should not know about TeamMembers.

Good:

    InternalNotifications -> Messaging contract

Bad:

    Messaging -> InternalNotifications model## Deployment ownership

InternalNotifications owns these selected-client environment overrides:

- `INTERNAL_NOTIFICATION_FROM_ADDRESS`
- `INTERNAL_NOTIFICATION_FROM_NAME`

They do not create a second delivery-provider stack. Messaging continues to own email/SMS provider selection, credentials, webhook verification, and provider sender numbers.

For live staging/production email notifications, `INTERNAL_NOTIFICATION_FROM_ADDRESS` is required only when the shared `MAIL_FROM_ADDRESS` fallback does not already resolve an internal-notification sender. The display-name override is always optional because the runtime falls back to `MAIL_FROM_NAME` and then the application name.

Blank internal-notification sender overrides are treated like omission so they do not defeat the shared Messaging fallback chain.