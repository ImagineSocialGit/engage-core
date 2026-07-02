# Engage Core Module Docs

Detailed module-specific responsibility, dependency, schema, and future-work notes live here.

Use `../module-boundaries.md` for global architectural rules, dependency direction, schema ownership, migration organization, and boundary guardrails.

Use `../project-organization.md` for a quick classification map.

Use `../TODO.md` for actionable backlog.

## Current Core and universal modules

| Module | Doc |
| --- | --- |
| Core | `core.md` |
| Messaging | `messaging.md` |
| InboundMessaging | `inbound-messaging.md` |
| InternalNotifications | `internal-notifications.md` |
| Tasks | `tasks.md` |
| Workflow | `workflow.md` |
| FlowRoutes | `flow-routes.md` |
| Campaigns | `campaigns.md` |
| Broadcasts | `broadcasts.md` |
| Webinars | `webinars.md` |
| Reporting | `reporting.md` |
| Scheduling | `scheduling.md` |
| Portal | `portal.md` |
| Forms | `forms.md` |
| Documents | `documents.md` |
| Commerce | `commerce.md` |
| Location | `location.md` |

## Current and planned vertical modules

| Module | Doc |
| --- | --- |
| Mortgage | `mortgage.md` |
| PetServices | `pet-services.md` |
| Music | `music.md` |

## Split rule

`module-boundaries.md` should stay architectural and global. Module docs should own detailed module-specific notes such as:

```text
- module responsibility
- owns / does not own
- consumes / consumed by
- public seams to add later
- table notes
- status guidance
- deferred work
- open questions
```

When a module-specific section grows large inside `module-boundaries.md`, move the detail into the matching file here and leave only a short pointer or global rule behind.
