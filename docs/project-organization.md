# Engage Core Project Organization

## Shared config and token contract infrastructure

Executable authoring contracts live under `app/Support/ConfigContracts` and
`app/Support/TokenContracts`. Each owning module places concrete registrations in its own
`ConfigContracts` and/or `TokenContracts` directory and registers them from its service provider.

`ConfigContracts` is intentionally separate from a module's general `Contracts` directory:
`Contracts` contains PHP collaboration interfaces, while `ConfigContracts` describes the shape,
ownership, and validation of external PHP-array configuration. Keeping the names distinct makes
searching and future schema-driven authoring clearer.

Shared code owns the registry and neutral schema primitives. A module owns the meaning of its
fields, token sources, producer contexts, computed values, and semantic validation. No UI or
documentation layer should maintain a parallel list. See
[`config-contracts.md`](config-contracts.md).


For Messaging copy, `MessageTemplateTokenValidator` is the shared context-aware consumer of
`TokenContractRegistry`. Config/setup validation, MessageTemplatePreset sync, and CRM template
editing reuse it instead of maintaining separate token parsers or allowlists.

## Shared automation capability extension infrastructure

Neutral automation extension contracts live under `app/Support/AutomationCapabilities`.

Current shared registries:

```text
AutomationCapabilityRegistry
AutomationPointDefinitionRegistry
AutomationActionRegistry
AutomationPointAuthoringRegistry
```

Modules contribute their own capability metadata, Point schemas/semantic validation, neutral business-action handlers, and Point-specific authoring UX from module-owned directories and service providers. FlowRoutes owns orchestration, progression, native orchestration Points, created-artifact correlation, and the generic adapter from neutral action results into Route Point execution.

A new module-owned Route action should not require central FlowRoutes imports or new switch branches across config schema, setup validation, editor catalog, request validation, Blade fields, presentation, and runtime handlers.

This document is a quick orientation map for Engage Core. It classifies the project into Core, universal modules, vertical modules, and integrations/adapters.

Use `module-boundaries.md` for detailed ownership and dependency rules. Use `TODO.md` for actionable implementation backlog. Use `model-persistence-bloat-audit.md` for the current system-wide audit of model creation, JSON payload shape, duplicated snapshots, and database-write boundaries.

## Layers

```text
Core
Universal modules
Vertical modules
Integrations/adapters
```

## Product Principles and UI/UX

Engage Core product decisions should follow `product-principles.md`.

Client/operator-facing screens should follow `ui-ux-guide.md`.

The core barometer is:

```text
Can the client realistically complete this task in Engage Core in 10-15 minutes total?
```

Universal modules should provide reusable capability foundations while client-facing UX stays action-oriented, preset-driven, and written in plain business language.

## Core

Core is the required identity/contact foundation.

Core should almost never change. If a new universal module appears to require a Core change, first ask whether the need can be handled through an existing Core extension point, a new generic registry/seam, or a module-owned relation instead of new Core domain state.

Core owns:

- Contacts
- Contact statuses
- Contact tags
- Notes
- Contact imports and import registry/seams
- Generic contact lookup
- Generic contact filters for stable Contact-owned facts
- Contact show extension registries

Core should not own:

- messaging consent or delivery state
- scheduled appointments
- customer portal accounts
- form submissions
- document uploads
- commerce orders or purchases
- pet/dog data
- music/fan-specific data
- mortgage-specific data
- automation route progress
- campaign enrollment state
- task lifecycle state

Decision test:

```text
If it is not needed by almost every client install, it probably does not belong in Core.
```


## Shared CRM Orientation Surfaces

Some CRM surfaces are shared shells rather than feature modules.

### Dashboard

The dashboard is an app-level CRM orientation surface.

It should be driven by config slots, preset priorities, and module-contributed panel providers. It should answer what needs attention today and what is already handled. It should not duplicate the contact index (or its configured client-facing label, such as Leads) or become a grid of module widgets.

### Contact show

The contact show page is Core-owned because Core owns generic contact identity and CRM contact pages.

Feature modules contribute contact-specific information through Core-owned registries. Core should not import feature-module models directly to build the page.

## Shared automation infrastructure

Engage Core has shared app-level automation seams that are not feature modules:

```text
app/Support/AutomationEvents
    neutral business/domain outcomes that automation may react to

app/Support/AutomationCapabilities
    module-contributed catalog of what can be automated

app/Support/AutomationOpportunities
    compact behavior/correlation evidence, repeated-pattern aggregation, and suggestion lifecycle
```

`AutomationOpportunities` should observe only explicitly contributed meaningful manual actions or deliberately selected structured event evidence.

Current implementation includes evaluated manual/compound patterns plus evidence-only occurrences such as:

```text
task.completed_manually
automation_event.recorded
```

Evidence-only rows do not create opportunities by themselves.

This is not clickstream analytics and should not record arbitrary page views, clicks, requests, model saves, or every automation event.

The generic opportunity evaluator should stay free of module/event-specific branching. Producer actions own semantic fingerprints; shared infrastructure owns deterministic hashing, persistence, aggregation, and generic count/distinct-subject/window qualification.

Detailed direction lives in `docs/automation-opportunities.md`.

## Universal Modules

Universal modules are reusable capability modules. They may be disabled for many clients, but they are not tied to a single business vertical.

### Current universal modules

| Module | Responsibility |
| --- | --- |
| Messaging | Outbound/scheduled email/SMS, consent, revocations, suppressions, gates, payloads, providers, opt-outs, permission invitations. |
| InboundMessaging | Inbound SMS/email webhooks, inbound message recording, inbound classification/routing. |
| InternalNotifications | Team members, notification preferences, internal notification gates/routing. |
| Tasks | Generic live work records, optional TaskTemplates, zero-to-many cross-module TaskLinks, assignment/responsibility, lifecycle, Task index/show, and optional notification/digest behavior. |
| Workflow | Contact workflow/profile state and status transition services. |
| FlowRoutes | Automation/control-flow routes, route points, waits, event waits, subject-scoped route progress, instance plans/items, progress/execution items, capability catalog/bindings, and created-artifact tracking/correlation. |
| Campaigns | Enrolled multi-step message journeys and campaign progression. |
| Broadcasts | One-time/batch sends and recipient bookkeeping. |
| Webinars | Webinar series, webinars, registrations, waitlists, schedule profiles, provider behavior, attendance, replay/follow-up orchestration. |
| Reporting | Reporting queries, dashboards, data objects, and report surfaces. |
| Scheduling | Appointments, availability, optional saved appointment places, booking/reschedule/cancel behavior, appointment reminders. |
| Portal | External/customer accounts, portal auth, account invitations, contact-account links, customer-facing shell. |
| Forms | Form definitions, versions, schemas, submissions, submission review state. |
| Documents | Document requests, uploaded document records, review events, document lifecycle state. |
| Commerce | Commerce customers, products, orders, order items, order events, provider-synced purchase history. |
| Location | Contact locations, reusable saved places, addresses, geocoding-derived coordinates, markets/regions, radius/service-area filters. |

### Planned universal modules

None currently documented.

Universal modules should expose public actions/services/contracts/events where other modules need them. Other modules should not write directly to their internals when a public seam exists.

## Vertical Modules

Vertical modules compose Core and universal modules into a domain-specific product. They own business-specific language, records, workflow meaning, rules, and presets.

Vertical modules should not push domain-specific fields into Core contacts.

### Current vertical modules

| Module | Responsibility |
| --- | --- |
| Mortgage | Mortgage stages, contact mortgage profiles, mortgage-specific state, LOS/domain-specific behavior, mortgage presets. |

### Planned vertical modules

| Module | Responsibility | Universal modules it likely consumes |
| --- | --- | --- |
| PetServices | Pets/dogs, pet profiles, training goals, training programs, behavior notes, pet-service-specific rules/workflows. | Scheduling, Portal, Forms, Documents, Tasks, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Reporting. |
| Music | Music-specific fan/customer meaning, release/fan strategy, music product interest categories, show/release logic, music-specific segmentation. | Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Scheduling, Portal, Reporting. |


## Cross-module FlowRoutes integration pattern

When a module participates in FlowRoutes, follow one ownership-preserving process:

```text
1. The module owns its domain records and lifecycle.
2. The module emits neutral AutomationEventRecorded events for automation-worthy outcomes.
3. The module exposes public actions/services/contracts before FlowRoutes creates or mutates its records.
4. The module may contribute capability metadata, Point-definition schema/validation, neutral action execution, Point-specific authoring UX, route presets, task templates, labels, and available-field metadata through public seams.
5. FlowRoutes owns route progress, created-artifact references, correlation, and resume matching.
6. Do not require the artifact-owning module to import FlowRoutes models or store FlowRoutes-specific foreign keys merely for provenance symmetry.
```

Preferred created-artifact shape:

```text
FlowRoutes progress/execution item
    created_subject_type
    created_subject_id
    correlation when needed

Owning module artifact
    owns business state/lifecycle
    remains independent from FlowRoutes internals
```

For Tasks specifically:

```text
FlowRoutes -> Tasks public action -> template-backed Task
FlowRoutes stores created Task identity in FlowRoutes-owned state
Task emits neutral lifecycle event
FlowRoutes resumes through its own correlation state
```

This keeps future Scheduling, Documents, Forms, Portal, Commerce, Mortgage, PetServices, Music, and other integrations consistent at the ownership boundary without forcing every module to carry FlowRoutes internals.

## Cross-module resolved message dispatch pattern

Modules that use Messaging should keep business behavior with the module that owns the lifecycle. Reusable Messaging templates own copy and delivery-template metadata; they do not own another module's timing, conditions, sequencing, dependencies, enablement, or skip behavior.

Preferred shape:

```text
Owning module records/resolves its own behavior
    -> selects reusable Messaging template/copy
    -> ResolvedMessageDispatchBuilder
    -> ResolvedMessageDispatch with exact send_at
    -> Messaging gating/persistence/queue/provider delivery
```

The builder is universal and Messaging-owned, but it must not query or interpret concrete feature-module tables. The caller supplies already-resolved behavior.


When caller-owned behavior includes lifecycle conditions, those conditions should persist into `ScheduledMessage.meta.conditions`. `ScheduledMessageGate` re-evaluates them immediately before provider delivery so a delayed message does not send merely because its conditions passed when it was first planned.

`ScheduledMessage` may preserve an optional polymorphic `behavior_owner` reference to the exact module-owned record responsible for the behavior. This provenance must not create a hard Messaging dependency on concrete feature-module classes.

Examples:

```text
Webinars -> WebinarScheduleProfileItem
Campaigns -> CampaignStepVariant
Broadcasts -> Broadcast
FlowRoutes -> FlowRoutePoint
```

Modules do not need to adopt a universal profile/profile-item storage pair. Shared assembly is universal; behavior storage remains module-owned.

## Integrations / Adapters

Integrations/adapters connect modules to external providers. They are not modules.

Adapters should live behind the owning module's contracts, managers, resolvers, or provider services.

| Adapter/integration | Owning/using module |
| --- | --- |
| Resend | Messaging |
| Telnyx | Messaging / InboundMessaging |
| Twilio | Messaging / InboundMessaging |
| Zoom | Webinars |
| Shopify, later | Commerce |
| External calendar providers, later | Scheduling |
| Geocoding/address providers, later | Location |
| LOS providers such as Arive, later | Mortgage |

## Classification Rule

Use this rule when deciding where a new capability belongs:

```text
Core = required identity/contact foundation.
Universal module = reusable capability many verticals can use.
Vertical module = business-domain-specific concepts/rules/language.
Integration = external provider adapter behind the owning module.
```

Examples:

| Need | Belongs in |
| --- | --- |
| Contact name/email/phone/source | Core |
| Appointment booking | Scheduling |
| Customer login/account | Portal |
| Intake questionnaire | Forms |
| Uploaded file/document request | Documents |
| Shopify purchase sync | Commerce + Shopify adapter |
| Dog profile/training goals | PetServices |
| Music fan/release strategy | Music |
| Radius-based targeting | Location |

## Core Change Standard

Before changing Core for a new module, ask:

1. Is this fact needed by almost every client install?
2. Is this a stable Contact-owned fact rather than module behavior?
3. Can the module own the data through its own table and link to Contact?
4. Can Core expose a generic registry/contract/filter seam instead of owning the domain state?
5. Would this change make Core import or understand a feature/vertical module?

Prefer:

```text
Core seam + module-owned data
`````

Avoid:

```text
New Core columns for feature or vertical state
```
