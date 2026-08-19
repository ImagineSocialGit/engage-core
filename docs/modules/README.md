# Engage Core Module Docs

Module-owned documentation lives under one directory per module:

```text
docs/modules/<module>/module_state.md
docs/modules/<module>/TODO.md            # only while actionable backlog exists
```

`module_state.md` is the durable module reference. It owns responsibility, dependency, schema, current committed behavior, public seams, Project State status, and durable deferred direction.

`TODO.md` is disposable module-owned backlog. Delete completed items instead of turning it into release history. Do not create an empty TODO merely for symmetry.

Use `../module-boundaries.md` for platform-wide ownership/dependency rules, `../module-surfaces.md` for loud/silent product-surface rules, and `../TODO.md` only for backlog that genuinely has no single module owner.

## Module index

| Module | Status / surface | State |
| --- | --- | --- |
| Core | Required; platform exception | `core/module_state.md` |
| Messaging | Universal; silent | `messaging/module_state.md` |
| InboundMessaging | Universal; silent | `inbound-messaging/module_state.md` |
| InternalNotifications | Universal; silent | `internal-notifications/module_state.md` |
| Tasks | Universal; loud | `tasks/module_state.md` |
| Workflow | Universal; silent | `workflow/module_state.md` |
| FlowRoutes | Universal; loud; presented as Routes | `flow-routes/module_state.md` |
| Campaigns | Universal; loud | `campaigns/module_state.md` |
| Broadcasts | Universal; loud | `broadcasts/module_state.md` |
| Webinars | Universal; loud | `webinars/module_state.md` |
| Reporting | Universal; loud | `reporting/module_state.md` |
| Scheduling | Universal; loud | `scheduling/module_state.md` |
| Portal | Universal; loud | `portal/module_state.md` |
| Forms | Universal; loud | `forms/module_state.md` |
| Documents | Universal; loud | `documents/module_state.md` |
| Commerce | Universal; loud | `commerce/module_state.md` |
| Location | Universal; silent | `location/module_state.md` |
| Relationships | Universal; loud | `relationships/module_state.md` |
| Events | Planned universal; loud | `events/module_state.md` |
| Mortgage | Current vertical; loud | `mortgage/module_state.md` |
| PetServices | Planned vertical; loud | `pet-services/module_state.md` |
| Music | Planned vertical; loud | `music/module_state.md` |
| Experiences | Planned vertical; loud | `experiences/module_state.md` |

Integrations/adapters are not modules and do not receive independent product surfaces by default.

## Module reference standard

When a module state doc is materially revised, keep these concerns explicit where relevant:

```text
architecture tier
product surface
standalone value
primary users and surfaces
owns / does not own
consumes / consumed by
current committed workflows
public seams required by those workflows
schema/table notes
Project State status
durable deferred possibilities
```

FOSS, competitive, or provider feature inventories are possibility inventories only. They are not requirements until an approved Engage Core workflow adopts them.

## Split rule

Global docs should contain only platform-wide rules. Once a concern is primarily owned by one module, put its durable contract in that module's `module_state.md` (or a focused companion doc in the same directory) and its actionable work in that module's `TODO.md`.

Cross-module docs should remain global only when no one module can own the contract without distorting dependency direction.