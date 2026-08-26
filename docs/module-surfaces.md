# Engage Core Module Product Surfaces

This document defines how Engage Core modules appear—or intentionally do not appear—as product surfaces.

Use `module-boundaries.md` for ownership, dependency direction, schema, and public seams. Use `project-organization.md` for the quick module map. Use `modules/<module>/module_state.md` for each module's detailed responsibilities and current commitments.

## Two independent classifications

Every capability has an architectural classification:

```text
Core
universal module
vertical module
integration/adapter
```

Feature modules also have a product-surface classification:

```text
loud
silent
```

These classifications answer different questions.

```text
Architectural classification
    Who owns the capability, data, dependencies, and public contracts?

Product-surface classification
    How should operators, administrators, or public users encounter it?
```

A silent module is not less important. It may own substantial schema, contracts, jobs, provider coordination, validation, and historical state. It is silent because its value is normally delivered through another workflow rather than through a standalone product area.

## Loud modules

A loud module owns a recognizable workflow that a client, operator, or public user may intentionally open and use.

A loud module normally has one or more of these characteristics:

```text
named CRM workspace
primary or secondary navigation eligibility
public-facing workflow
routine operator actions
dedicated lifecycle/detail pages
recognizable onboarding or sales value
```

Examples:

```text
Scheduling
Broadcasts
Campaigns
Tasks
Webinars
Routes
Reporting
Relationships
```

Loud does not mean every enabled module must receive a top-level sidebar item. Navigation should still reflect frequency, importance, and the shared UI/UX rules. A loud module may live under a grouped navigation area or be reached contextually when that produces a simpler product.

The important rule is that the workflow is a recognizable product capability rather than hidden infrastructure.

## Silent modules

A silent module owns reusable supporting behavior that other product workflows consume.

A silent module may own:

```text
normalized persistence
public contracts and DTOs
provider-neutral services
background jobs
validation and setup diagnostics
embedded contact-panel contributions
contextual controls
shared-settings sections
```

A silent module should not receive, merely because it exists:

```text
a top-level sidebar item
a generic dashboard
a standalone CRUD workspace
a client-facing builder for every table
its own vocabulary imposed on the consuming workflow
```

Examples:

```text
Messaging
InboundMessaging
InternalNotifications
Workflow
Location
```

A silent module may still expose a narrow settings or diagnostics surface. That surface should appear inside shared settings or the consuming loud module unless administrators genuinely need to operate the silent capability independently.

InboundMessaging's **Reply Handling** workspace is one deliberate exception. Reply profiles are shared by Campaign message journeys and Flow Routes, so placing the authoritative editor inside either consumer would imply false ownership. The narrow workspace exposes only the durable reply vocabulary, its recognition rules, and dependency-aware lifecycle controls; it is not a general InboundMessaging dashboard or inbox.

## Core and integrations

Core is the platform foundation rather than a normal optional feature module. Its Contact and CRM-shell surfaces are loud, but Core itself is not presented to clients as a module named “Core.”

Integrations and provider adapters are not modules. They are silent implementation details behind the contracts of the module that owns the workflow. Provider credentials and connection controls belong in shared settings or the owning module's setup surface—not in a provider-specific product area by default.

## Enabled, loaded, and visible are separate

Do not collapse these states:

```text
installed
    the code/schema exists

explicitly enabled
    the selected client has the runtime capability available

dependency-loaded
    a provider is loaded because another enabled module requires it

visible
    a deliberate product surface or navigation contribution is exposed
```

Rules:

- table existence does not imply availability;
- explicit enablement does not require a sidebar link;
- dependency loading does not make a silent module visible;
- navigation must not be inferred mechanically from the enabled-module list;
- a silent module may be explicitly enabled and remain embedded-only;
- a loud module must still be explicitly available before its product surfaces are exposed.

The module config deliberately does not infer a general `surface_mode`. Documentation remains the authority for loud/silent product classification. Concrete executable contributions are narrower:

```text
nav
    eligible registered routes for the shared CRM navigation

settings
    module-owned links collected into the shared Settings & setup directory
```

`ModuleManager` exposes only contributions from explicitly enabled module definitions and ignores routes that are not registered. Dependency-loaded modules do not gain visibility merely because their providers are available. Settings contributions link to one authoritative maintenance surface; they do not duplicate the owning module's forms or persistence.

The app-level `modules.settings` configuration owns the small category vocabulary and the deliberately capped getting-started list. Getting started is platform orientation, not executable surface metadata for every module and not a second installation checklist.

## The consuming module owns the experience

When a loud module consumes a silent capability, the loud module owns the user's workflow.

Example:

```text
Scheduling
    asks for the service address
    explains why the address is required
    decides whether authoritative availability can be shown
    displays travel-related outcomes
    stores the Appointment-facing historical snapshot

Location
    normalizes the address
    optionally resolves geographic facts
    exposes provider-neutral location contracts
```

Likewise:

```text
Scheduling or Tasks
    decides when an internal alert is useful

InternalNotifications
    resolves recipient preferences and delivery behavior
```

Do not make the user leave the workflow they understand to operate a supporting module they should not need to know exists.

## Research and audit findings are possibility inventories

FOSS reviews, competitive audits, schema audits, and brainstorming documents identify possible feature shapes. They are not requirements specifications.

A discovered capability becomes an Engage Core commitment only when all of these are true:

```text
1. A concrete Engage Core workflow needs it.
2. The owning module is identified.
3. The user or operator outcome is clear.
4. The smallest durable contract is defined.
5. The capability passes the product barometer.
6. Persistence, Project State, and provider boundaries are understood when applicable.
```

Do not build a feature merely because:

```text
a FOSS product includes it
a roomy foundation table can represent it
a provider API exposes it
another module might theoretically use it later
```

Existing module-doc sections labeled as FOSS assumptions or feature-shape references must be read under this rule. They may guide later design, but unchecked items remain deferred possibilities.

## Module definition template

When creating or materially revising a module reference, define this identity before expanding the feature list:

```text
Architecture tier:
    Core | universal | vertical

Product surface:
    loud | silent

Standalone value:
    yes | no

Primary users:
    client/operator | public user | developer/operator | consuming modules

Primary surfaces:
    CRM workspace | public workflow | contact panel | shared settings | embedded only | none

Owns:
    durable facts, lifecycle, contracts, and decisions

Does not own:
    adjacent workflows, provider internals, and vertical interpretation

Consumes / consumed by:
    public seams only

Current committed workflows:
    proven requirements implemented or approved now

Deferred possibilities:
    audited or anticipated capabilities with no current commitment

Project State status:
    transferred | must remain empty | not durable | planned
```

Do not create empty symmetry sections or speculative public seams merely to fill this template.

## Current classification registry

This registry records the current product direction. Reclassification requires a deliberate architecture/product decision and corresponding documentation update; it should not happen accidentally because a controller or view was added.

| Capability | Architecture tier | Product surface | Current presentation rule |
| --- | --- | --- | --- |
| Core | platform foundation | platform exception | Present Contacts and the CRM shell; never present a module named Core. |
| Messaging | universal | silent | Expose templates, consent, and delivery setup contextually or through shared settings; no primary Messaging workspace by default. |
| InboundMessaging | universal | silent with one narrow authority surface | Surface inbound activity through Contact/context panels. Reply Handling owns shared reply-profile rules and dependency safeguards; a future full inbox still requires a deliberate product decision. |
| InternalNotifications | universal | silent | Surface alerts and preferences in consuming workflows, Contact context, dashboards, or shared settings. |
| Tasks | universal | loud | Provide routine Task index/detail and contextual Task actions. |
| Workflow | universal | silent | Provide Contact lifecycle/profile behavior through Contact and consuming-module surfaces. |
| FlowRoutes | universal | loud | Present as Routes/Assignments, not as internal FlowRoute machinery. |
| Campaigns | universal | loud | Provide Campaign authoring, activation, enrollment, and reporting surfaces. |
| Broadcasts | universal | loud | Provide one-time send authoring, scheduling, recipient, and outcome surfaces. |
| Webinars | universal | loud | Provide Webinar setup, registration operations, occurrence, and follow-up surfaces. |
| Reporting | universal | loud | Provide recognizable reports and dashboards while remaining read-only toward source modules. |
| Scheduling | universal | loud | Provide CRM scheduling/configuration and public booking workflows. |
| Portal | universal | loud | Provide a recognizable external user experience plus contextual administration. |
| Forms | universal | loud | Provide form/submission workflows; complex form construction may remain developer/operator work. |
| Documents | universal | loud | Provide request, upload, review, and checklist workflows; requirement design may remain developer/operator work. |
| Commerce | universal | loud | Provide custom storefront/offers, purchase history, provider-backed checkout orchestration, and cross-provider inventory coordination while specialized payment, shipping/warehouse, POS, and deep store operations remain external. |
| Location | universal | silent | Provide normalized location facts and supporting contracts through consuming modules; no standalone Location product by default. |
| Relationships | universal | loud | Provide relationship-scoped Contact workspaces; normal daily lists must not mix materially different relationship populations. |
| Events | universal | loud | Provide concrete Event catalog, readiness, lifecycle, and attendance workflows. |
| Mortgage | vertical | loud | Provide mortgage-specific records, workflow meaning, and operations. |
| PetServices | vertical | loud | Provide pet-service-specific records, workflows, and operations when implemented. |
| Music | vertical | loud | Provide music-specific records, workflows, and operations when implemented. |
| Experiences | vertical | loud | Provide post-purchase package management, participants, credentials, scanning, and fulfillment when implemented. |

Integrations/adapters remain outside this registry because they are not product modules.

## Navigation rule

Top-level navigation is scarce product space.

When Relationships is explicitly enabled, client-facing Contact navigation should prefer configured relationship workspaces (for example Leads and Realtors) over one undifferentiated all-Contacts destination. A mixed all-Contacts view is an administrative/export/debug surface, not the normal daily operating list.

Use this default:

```text
loud + explicitly available + routinely operated
    eligible for primary or grouped navigation

loud + contextual or infrequent
    may be reached from related records, dashboards, or grouped navigation

silent
    no top-level navigation by default
```

A module should not gain navigation merely to prove that implementation exists.

## Adoption rule

Apply this framework when a module is next materially revised. Do not churn every module document or UI solely for wording symmetry.

For each revision:

1. state the architecture tier and product surface;
2. identify current committed workflows;
3. move unsupported audited ideas into deferred possibilities;
4. keep the consuming loud module responsible for the user experience;
5. add a concrete `nav` or `settings` contribution only when a registered product surface requires it; do not add a broad `surface_mode` merely for symmetry.