# Mortgage Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Mortgage is a vertical module.

Mortgage is optional and should not be installed by default.

Mortgage owns:

- mortgage stages
- contact mortgage profiles
- mortgage-specific fields
- LOS automation
- mortgage-specific adapters
- mortgage-specific workflow definitions later
- mortgage-specific FlowRoute definitions later

Mortgage may consume:

- Core
- Workflow
- FlowRoutes
- Tasks
- Messaging
- Campaigns
- Webinars
- Reporting
- Integrations

Mortgage must not push mortgage-specific state into Core contacts.

Vertical-specific migrations belong under:

    database/migrations/verticals/mortgage

Mortgage may depend on Arive or other LOS providers through adapter contracts/services.


## FlowRoutes integration

This module should integrate with FlowRoutes through the same uniform route capability and provenance pattern used by other modules.

When this module has automation-worthy outcomes, it should record its own domain state first and then emit neutral `AutomationEventRecorded` events. FlowRoutes should listen to the generic automation event seam, not module-specific events.

When FlowRoutes creates or mutates this module's records, it should do so only through public actions/services/contracts exposed by this module. FlowRoutes should not write this module's private tables directly.

If FlowRoutes creates a module-owned artifact, that artifact should store the standard FlowRoutes provenance fields where applicable:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
```

This keeps Scheduling, Documents, Forms, Portal, Commerce, Mortgage, PetServices, Music, and other future modules consistent instead of inventing bespoke route metadata per module.
