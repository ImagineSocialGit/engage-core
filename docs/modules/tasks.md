


# Tasks Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Tasks is a reusable capability module.

Tasks owns tracked manual human actions and dependencies.

A Task represents something a human needs to do, complete, provide, review, confirm, or manually resolve.

Tasks owns:

- live task records;
- task templates;
- task template preset sync;
- task creation from templates;
- task lifecycle state;
- task completion, cancellation, reopen, archive, and restore behavior;
- task assignment and responsible-party semantics;
- task due dates and priority/default metadata;
- task contact/context relationships;
- task events such as `task.completed` when automation-worthy.

Tasks may depend on:

- Core, for Contact-owned task association and generic identity.

Tasks should remain standalone-capable with Core only for core task creation, lifecycle, template/default behavior, and contact-related visibility.

Current assignment behavior may resolve active `TeamMember` records when InternalNotifications is installed and enabled, because TeamMember is the current internal assignee model. That is an optional assignment/notification capability path, not a requirement for Tasks to create, complete, reopen, cancel, archive, restore, or display basic tasks.

InternalNotifications and Messaging are optional consumers/capabilities for assignment notifications and digests. Tasks must not require them for its core model, lifecycle, or template/default behavior.

Tasks does not own:

- Messaging scheduled messages;
- InternalNotifications team notification preferences;
- FlowRoutes route progress;
- Campaign enrollment;
- Workflow status/profile state;
- vertical-specific domain records such as dogs, mortgage loans, music fans, documents, appointments, or commerce orders.

Other modules may request task creation through Tasks public actions/services. They should not directly create task records when a public task creation action exists.

Good:

    CreateTaskAction
    CreateTaskFromTemplateAction
    CompleteTaskAction

Bad:

    Task::create(...)

unless the code is inside Tasks itself or there is an intentional, documented exception.

## Task templates and defaults

Task templates are DB-owned reusable task definitions.

Task template-backed live task creation resolves defaults in this order:

```text
1. Explicit caller value.
2. First-class TaskTemplate field.
3. TaskTemplate.defaults.
4. System fallback.
```

`TaskTemplate.defaults` is real generic fallback data. It is not a dumping ground for values that already have first-class TaskTemplate columns.

Task templates are not optional if FlowRoutes `create_task` points need reusable tasks.

FlowRoutes should not hardcode every reusable task shape inline forever.

Task template preset sync should create DB-owned default task templates only. It should not create live tasks.

Normal sync should preserve customized templates unless an explicit force behavior is chosen.


## Tasks setup validation ownership

Tasks should contribute Tasks-owned checks to the shared app-level setup validation manager.

Task preset/template validation should use the actual Task preset definitions and DB-owned TaskTemplate/runtime rules as executable truth.

At minimum, validate:

```text
selected Task preset groups exist
referenced Task preset definitions exist
Task template keys are valid and unique
definition keys match their config keys
required task-template fields are present
due-offset/default assignment/responsibility shapes are valid
related-subject defaults use supported generic shapes
vertical-contributed task templates remain generic at the Tasks storage/runtime layer
FlowRoute create_task references resolve to available TaskTemplate definitions
internal identifiers do not use client-facing nouns such as lead/fan/customer in place of canonical contact terminology
```

A missing TaskTemplate referenced by a selected FlowRoute is a hard error.

An unused but valid TaskTemplate may be a warning or omitted from findings depending on whether unused-definition visibility is useful to the operator.

Tasks validation should return shared structured findings and should not persist validation history by default.

Task templates should be generic enough to support:

```text
FlowRoutes-created tasks
manually-created tasks with defaults
Webinars follow-up/admin tasks
Documents review/request tasks
Scheduling preparation/follow-up tasks
Mortgage vertical tasks
PetServices vertical tasks
Music vertical tasks
```

Task templates may be contributed by vertical modules through presets, but Tasks must not become vertical-specific.

Good:

```text
PetServices contributes a “Call owner after behavior evaluation” task template.
Tasks stores it as a generic TaskTemplate with metadata/source.
FlowRoutes references the TaskTemplate through a stable key/id and asks Tasks to create the live task.
```

Bad:

```text
Tasks adds pet-specific columns.
FlowRoutes hardcodes dog-training task copy inline.
PetServices directly creates Task records instead of using a Tasks public action.
```

## Phase 3 audit scope

Phase 3 should audit and implement the task template/default foundation before deeper FlowRoutes runtime work.

Goals:

```text
Audit existing task_templates table and model shape.
Confirm task templates can support generated/manual tasks.
Confirm FlowRoutes create_task points can reference TaskTemplate records.
Confirm task templates can define title/body/default due offsets/assigned_to/responsible_party/related-subject rules.
Confirm task templates are generic enough for PetServices, Mortgage, Music, Webinars, Documents, Scheduling, and other modules.
Confirm task template preset sync creates DB-owned default task templates only and does not create live tasks.
Confirm customized templates are preserved.
Decide whether direct template reference is enough or whether template assignment/selection is needed.
Build UI only if needed for client/operator management.
```

Fields/concepts to audit:

```text
key
name/title
summary/body/description
category/type
priority
default due offset
default assigned_to_type / assigned_to_id
responsible_party
related subject rules
source module / source preset
owner group / visibility
active/customized/synced flags
meta/config_snapshot
```

If current schema lacks a required field, replace the branch migration while still pre-prod instead of layering unnecessary modify-table migrations.

## FlowRoutes integration

FlowRoutes may create tasks through Tasks public services/actions.

FlowRoutes `create_task` points should be able to reference a TaskTemplate record or stable task template key.

The expected direction is:

```text
FlowRoute create_task point
→ Tasks public action/service
→ TaskTemplate resolution
→ live Task creation
→ optional task.created/task.completed automation events
```

FlowRoutes should not directly create `Task` records.

FlowRoutes should not require Tasks to know route internals.

Task completion should emit a neutral automation event when automation-worthy:

```text
task.completed
```

FlowRoutes can listen to generic `AutomationEventRecorded` and resume matching event-wait/progress/plan items internally.

Tasks should not import FlowRoutes to resume route progress.

## Assignment and responsibility

Task assignment and responsible-party semantics belong to Tasks.

A task may be:

```text
unassigned
assigned to a User
assigned to a TeamMember when InternalNotifications exists
assigned later by an operator
owned/grouped by a module or preset source
```

Tasks should support optional assignment without requiring InternalNotifications.

InternalNotifications may contribute TeamMember notification behavior through public seams, but Tasks must remain usable with Core only.


## FlowRoutes-created task provenance

When FlowRoutes creates a Task, Tasks should continue to own task creation, assignment strategy, due-date resolution, responsibility, lifecycle, and task template behavior. FlowRoutes should call Tasks public actions/services, not create tasks directly.

Route-created tasks should store structured FlowRoutes provenance so task completion can resume the correct route instance without meta-heavy correlation:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
task_template_id
task_template_key
```

`task_template_id` links to the current DB template when available. `task_template_key` preserves the durable stable key for debugging, historical context, and null-on-delete cases.

This split is intentional. `tasks.task_template_id` should be treated as a soft/current database reference to the template that existed when the live task was created. It may be nullable and should not be the only historical identity for the task.

`tasks.task_template_key` is the durable historical identity. It should remain available for debugging, reporting, provenance, route correlation, and future template deletion/replacement scenarios.

`tasks.task_template_id` is a real nullable foreign key with `nullOnDelete()`. Deleting or replacing a TaskTemplate must not delete historical Task rows or erase durable template identity.

`tasks.task_template_key` remains the durable historical identity even when the referenced DB template is later deleted, replaced, or re-synced.

Template-backed task creation must preserve the same structured FlowRoutes provenance as inline task creation. `CreateTaskFromTemplateAction` should pass route provenance through to the Tasks-owned live task creation path and persist both `task_template_id` and `task_template_key`.

Task completion automation events should include enough task identity and structured FlowRoutes provenance for FlowRoutes to resume a specific waiting route progress/plan/progress item. Broad contact-only task completion matching is unsafe.

## Task template UI

A client/operator task template UI is only needed if clients/operators need to manage templates themselves.

Before building a polished UI, prove the schema and public action/service shape.

The first Phase 3 target is the durable task template/default foundation, not a polished task-template builder.

### Task action interaction direction

Task actions should eventually follow the CRM preserve-context pattern where it improves operator flow.

Dashboard task rows, contact task panels, and future task workspaces may support actions such as:

- Print
- View
- Broadcast
- Complete
- Reopen
- Cancel
- Archive
- Restore
- Reassign

For small row/card actions, the preferred long-term behavior is an inline update that preserves the operator’s current dashboard, filter, panel, or modal context instead of forcing a full reload.

Examples:

- completing a task should update the row/card state and counts in place when practical;
- reopening a task should restore it without losing the current task list context;
- broadcasting selected tasks should preserve the current selection or clearly report what happened;
- viewing a task may link to the task detail page, the related contact, or a contact task section depending on the final task workspace model.

This is not part of the Phase 3 schema-discovery requirement unless the task UX reveals missing persisted concepts such as saved task views, per-user task panel state, task action acknowledgements, selected-task batches, or durable task broadcast batches.

## Events

Tasks may emit neutral automation events for lifecycle outcomes that other modules can consume through the generic automation event seam.

Examples:

```text
task.created
task.completed
task.cancelled
task.reopened
```

Only emit events after Tasks records its own state.

Tasks must not import FlowRoutes, Campaigns, Webinars, Documents, Scheduling, or vertical modules to trigger downstream behavior.

## Task template field insertion

Task template titles, descriptions, instructions, and future notification copy may eventually support dynamic fields.

Task-specific editors should follow the shared `Insert field` / available-field picker pattern once the registry/validation work exists.

Potential task fields:

```text
task_title
task_description
task_due_date
task_priority
task_responsible_party
related contact fields
related subject fields, when present
```

Do not make Phase 3 depend on a polished token picker. Phase 3 should first prove the DB-owned task-template/default shape. The field picker belongs to shared authoring UX and Phase 6 validation unless the task-template schema audit proves a missing persisted field/source concept.


