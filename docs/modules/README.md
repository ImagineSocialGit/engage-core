# Engage Core Module Docs

Detailed module-specific responsibility, dependency, schema, current-commitment, and deferred-work notes live here.

Use `../module-boundaries.md` for global ownership, dependency direction, schema ownership, migration organization, and boundary guardrails.

Use `../module-surfaces.md` for loud/silent product-surface rules, the current classification registry, navigation expectations, and the module-definition template.

Use `../project-organization.md` for a quick architecture and surface map.

Use `../TODO.md` for actionable backlog.

## Platform foundation

| Capability | Surface | Doc |
| --- | --- | --- |
| Core | Platform exception; Contacts/CRM shell are loud | `core.md` |

## Current universal modules

| Module | Surface | Doc |
| --- | --- | --- |
| Messaging | Silent | `messaging.md` |
| InboundMessaging | Silent | `inbound-messaging.md` |
| InternalNotifications | Silent | `internal-notifications.md` |
| Tasks | Loud | `tasks.md` |
| Workflow | Silent | `workflow.md` |
| FlowRoutes | Loud; presented as Routes | `flow-routes.md` |
| Campaigns | Loud | `campaigns.md` |
| Broadcasts | Loud | `broadcasts.md` |
| Webinars | Loud | `webinars.md` |
| Reporting | Loud | `reporting.md` |
| Scheduling | Loud | `scheduling.md` |
| Portal | Loud | `portal.md` |
| Forms | Loud | `forms.md` |
| Documents | Loud | `documents.md` |
| Commerce | Loud | `commerce.md` |
| Location | Silent | `location.md` |

## Planned universal modules

| Module | Surface | Doc |
| --- | --- | --- |
| Events | Loud | `events.md` |

## Current and planned vertical modules

| Module | Status | Surface | Doc |
| --- | --- | --- | --- |
| Mortgage | Current | Loud | `mortgage.md` |
| PetServices | Planned | Loud | `pet-services.md` |
| Music | Planned | Loud | `music.md` |
| Experiences | Planned | Loud | `experiences.md` |

Integrations/adapters are not modules and do not receive independent product surfaces by default.

## Module reference standard

When a module doc is materially revised, state:

```text
architecture tier
product surface
standalone value
primary users and surfaces
owns / does not own
consumes / consumed by
current committed workflows
deferred possibilities
Project State status
```

Do not churn every file merely for symmetry. Apply the standard when a module is actively designed or implemented.

## FOSS and competitive references

Sections that describe FOSS, competitive, or provider feature shapes are possibility inventories only.

They do not become module requirements until a concrete Engage Core workflow needs them, ownership is clear, the smallest durable contract is defined, and the capability passes the product barometer.

## Split rule

`module-boundaries.md` should stay architectural and global. Module docs should own detailed module-specific notes such as:

```text
- module identity and product surface
- module responsibility
- owns / does not own
- consumes / consumed by
- current committed workflows
- public seams required by those workflows
- table notes
- Project State status
- deferred possibilities
```

When a module-specific section grows large inside `module-boundaries.md`, move the detail into the matching file here and leave only a short pointer or global rule behind.