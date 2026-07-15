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

This module should integrate with FlowRoutes through the ownership-preserving automation extension pattern used across Engage Core.

When this module has automation-worthy outcomes, it records its own domain state first and then emits neutral `AutomationEventRecorded` events. FlowRoutes listens to the generic automation-event seam, not module-specific events.

When FlowRoutes creates or mutates this module's records, it does so only through public actions/services/contracts exposed by this module. FlowRoutes must not write this module's private tables directly.

When this module contributes a cross-module Route business action, the module owns the Point-definition schema, semantic/domain-reference validation, neutral automation action handler, and authoring contribution through the shared Support-layer automation registries. FlowRoutes owns the Route envelope, orchestration/progression, native orchestration Points, created-artifact references, correlation, and resume matching.

Preferred boundary:

```text
Owning module
    owns business records and lifecycle
    owns contributed Point schema and semantic validation
    owns neutral business-action execution
    owns Point-specific authoring fields/rules/guidance when authorable

FlowRoutes
    owns route structure and progression
    adapts neutral business-action results into Point execution results
    records created-artifact identity in FlowRoutes-owned state
    owns correlation and resume matching
```

Do not add `flow_route_*` foreign keys to this module's artifacts merely for provenance symmetry. Add artifact-side provenance only when this module has an independently justified neutral provenance contract that is useful outside FlowRoutes.

