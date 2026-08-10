
# Mortgage Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Mortgage is a vertical module.

Mortgage is optional and should not be installed by default.

Mortgage owns:

- mortgage stages
- contact mortgage profiles
- mortgage-specific fields
- LOS automation and provider-neutral LOS integration contracts
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

Mortgage may consume installed LOS providers through provider-neutral adapter
contracts/services. Mortgage must not depend on concrete Arive or other vendor package
classes.

New LOS provider implementations should normally be separate private Composer packages
installed only for clients that use them. Arive is the first likely example:

```text
Engage Core Mortgage
    owns mortgage/LOS domain meaning and neutral contracts
            ^
            |
engage-integration-arive
    owns Arive-specific API/webhook/email parsing and provider translation
```

The exact package name may be finalized when the first external integration package is
created. Provider-specific transport/notification parsing must not hard-code downstream
Contact-status, Task, Campaign, or FlowRoute behavior; those outcomes should pass through
the established Core/Workflow/Automation Event/FlowRoutes/Tasks seams as appropriate.


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
