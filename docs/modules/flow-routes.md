# FlowRoutes Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

FlowRoutes is optional and depends on Workflow.

Use the term `FlowRoutes` in developer/module docs where precise ownership matters.

Do not casually call this domain “flows,” because that creates confusion with Workflow. Client/operator-facing UI may use `Routes` or `Route Management` when the screen is explaining automatic actions in plain language.

FlowRoutes owns:

- `FlowRoute`
- `FlowRoutePoint`
- `Point`
- `ContactFlowRouteProgress`
- `ContactFlowRoutePlan`
- `ContactFlowRoutePlanItem`
- `ContactFlowRouteProgressItem`
- `FlowRouteCapability`
- `FlowRouteCapabilityBinding`
- `FlowRouteTriggerBinding`
- route/point automation behavior
- active route execution state
- route cancellation/superseding behavior
- point execution behavior
- wait/resume behavior
- condition/branch evaluation behavior
- external event start behavior
- external event wait/resume behavior
- FlowRoute preset sync
- trigger binding selection behavior
- route owner morph metadata

FlowRoute preset sync assumes required Campaign definitions already exist when a route uses campaign points.

Status-triggered FlowRoutes also assume required ContactStatus definitions already exist.

Normal setup should use the app-level `presets:sync` command so dependencies are created first.

FlowRoutes depends on Workflow for status-triggered route behavior.

FlowRoutes also listens to the generic `AutomationEventRecorded` seam for automation-event-triggered routes and event waits.

FlowRoutes should have exactly these broad listener categories:

1. Workflow lifecycle events, such as `ContactWorkflowStatusChanged`.
2. Generic automation events, such as `AutomationEventRecorded`.

Workflow lifecycle events are allowed to stay direct because they start, supersede, and evaluate route progress based on ContactStatus.

Automation-worthy producer outcomes should go through `AutomationEventRecorded`.

FlowRoutes should not add producer-specific listeners for Webinars, Tasks, Mortgage, Campaigns, Broadcasts, or other vertical modules.

Core should not call FlowRoutes.

Workflow should not call FlowRoutes directly.

Expected status-triggered direction:

1. Contact status/profile changes through Workflow.
2. Workflow records the change.
3. Workflow emits an event.
4. FlowRoutes listens/reacts.
5. FlowRoutes supersedes active route progress if needed.
6. FlowRoutes evaluates whether the new ContactStatus has a FlowRoute.
7. FlowRoutes starts or advances route execution if appropriate.

Expected automation-event-triggered direction:

1. Producer module records its own domain state.
2. Producer module emits `AutomationEventRecorded`.
3. FlowRoutes maps that event to `FlowRouteExternalEvent`.
4. FlowRoutes starts matching active routes whose preset trigger is the automation event.
5. FlowRoutes also resumes any existing progress waiting at matching `event_wait` points.
6. FlowRoutes executes route points through DB-owned definitions.

Examples:

    webinar.attended -> FlowRoutes event-triggered route -> enroll_campaign(webinar_attended_nurture)
    webinar.missed -> FlowRoutes event-triggered route -> enroll_campaign(webinar_missed_nurture)
    task.completed -> FlowRoutes event_wait resume, if configured

Current tables/models:

    flow_routes
    flow_route_trigger_bindings
    points
    flow_route_points
    contact_flow_route_progress
    contact_flow_route_plans
    contact_flow_route_plan_items
    contact_flow_route_progress_items
    flow_route_capabilities
    flow_route_capability_bindings

Current models:

    FlowRoute
    FlowRouteTriggerBinding
    Point
    FlowRoutePoint
    ContactFlowRouteProgress
    ContactFlowRoutePlan
    ContactFlowRoutePlanItem
    ContactFlowRouteProgressItem
    FlowRouteCapability
    FlowRouteCapabilityBinding

A ContactStatus trigger should usually have one selected FlowRoute binding per context in the first implementation.

Automation-event triggers may have multiple selected FlowRoute bindings per context when multiple independent actions should run from the same event, such as webinar.attended changing status and enrolling an attended nurture campaign.

`FlowRoute.is_active` means the route is available and allowed to run. It does not by itself mean every matching route should execute.

FlowRoute trigger bindings own runtime selection.

Automation-event-triggered FlowRoutes do not require `contact_status_id`.

Runtime meaning:

    ContactStatus may have one selected status-triggered FlowRoute binding per context
    Automation event keys may have selected event-triggered FlowRoute bindings per context
    FlowRoute has many FlowRoutePoints
    FlowRoutePoint belongs to Point
    ContactFlowRouteProgress records active/waiting/completed/cancelled execution state

FlowRoutes runtime behavior should read DB-owned route/point definitions.


## Automatic Follow-ups UI exploration

The current trigger binding runtime model is durable enough to support CRM selection, but the client/operator UI should not be redesigned until the product questions are answered.

This is the next implementation focus after the Webinars message/template/schedule setup slice. Start with an audit and Q&A pass before replacing the current UI.

Questions to answer:

```text
Is the first product surface selection-only, route-editing, or split simple/advanced?
Who is the intended user for each mode?
How should a selected route's points be summarized into consequences?
How should status-triggered one-route selection differ from activity-triggered multi-route selection?
Which point types can be shown to clients?
Which point types are operator/developer-only?
How should unavailable module-owned point types appear when a module is disabled?
Where should send-message point template assignment be edited?
What confirmation is required before a manual status change starts a selected status route?
```

Until those answers are captured, keep implementation focused on stable runtime bindings, capability-aware availability, and diagnostics.

## Trigger bindings

FlowRoute trigger bindings select which available route runs for a trigger/context.

Initial conceptual table:

```text
flow_route_trigger_bindings
- id
- trigger_type
- trigger_key
- flow_route_id
- context_type nullable
- context_id nullable
- is_active
- meta json nullable
- timestamps
```

The first implementation should usually enforce one active selected binding per:

```text
trigger_type + trigger_key + context_type + context_id
```

Future implementations may support owner-group-specific or priority/sort-order bindings if a concrete need appears.

Trigger bindings let the CRM expose simple selectors such as:

```text
Status: Prospect
Selected route: Prospect Sales Follow-Up
```

without deleting, overwriting, or toggling off every other available route.

## Route ownership

FlowRoutes may have an owner morph for operational ownership.

Suggested `flow_routes` fields:

```text
owner_type nullable
owner_id nullable
owner_group nullable
```

`owner_group` is a semantic/admin grouping label such as:

```text
sales
ops
compliance
system
```

Do not use `responsible_party` for FlowRoute ownership. `responsible_party` belongs to Tasks and means who or what must perform a manual task action.

## Route points remain the multi-behavior mechanism

A trigger binding should select a route.

The selected route may contain many points.

Example:

```text
Prospect status changed
→ selected Prospect route
    → create task
    → enroll campaign
    → notify admin
```

Those are points in one route, not several unrelated active routes competing for the same trigger.


Preset config may create/update DB-owned FlowRoute definitions, but runtime execution should not depend directly on config definitions.

Current point handler capabilities include:

- noop
- wait
- condition
- branch_evaluate
- event_wait
- create_task
- send_message
- change_status
- enroll_campaign
- cancel_campaign

FlowRoutes may create Tasks through public task-facing services/contracts.

FlowRoutes `create_task` points may create assigned or unassigned tasks.

FlowRoutes should pass task responsibility fields through `CreateTaskAction` rather than encoding responsibility only in FlowRoute metadata.

FlowRoutes may use Messaging only through public Messaging actions/services.

FlowRoutes may use Campaigns only through public Campaigns actions/services.

Good:

    CreateTaskAction
    DispatchMessageAction
    TransitionContactWorkflowStatusAction
    EnrollContactInCampaignAction
    CancelCampaignEnrollmentAction

Bad:

    Task::create(...)
    ScheduledMessage::create(...)
    CampaignEnrollment::create(...)

Also bad:

    Webinars imports FlowRoutes
    Tasks completion listener inside FlowRoutes
    Producer modules call FlowRouteExternalEvent::make(...)

`contact_flow_route_progress` may store denormalized `contact_id` and nullable `contact_status_id` values for query/runtime convenience.

Those fields do not make FlowRoutes the owner of Contact or ContactStatus.

Canonical ownership remains:

- Contact belongs to Core.
- ContactStatus belongs to Core.
- ContactWorkflowProfile belongs to Workflow.
- ContactFlowRouteProgress belongs to FlowRoutes.

For automation-event-started routes, `contact_status_id` and `contact_workflow_profile_id` may be null on `contact_flow_route_progress`.

That is expected.

It means the route started from an automation event rather than a Workflow status transition.

FlowRoutes may support Campaign, Messaging, Task, and status-related point types, but client-facing Route selection/building must be capability-aware. Point types whose owning modules are disabled should be hidden, disabled, or clearly marked unavailable. Campaign-related points must not appear as selectable client-facing behavior for clients without Campaigns enabled.


## Relationship, capability, and instance-plan status

The Phase 4A audit and Phase 4B implementation are complete for backend/schema readiness. FlowRoutes now has subject-scoped route instances, instance-specific route plans, plan items, progress/execution items, capability catalog/bindings, and route-created artifact provenance.

This foundation exists before Phase 5 task-completed event-wait resume behavior so resume behavior can target a specific route instance plan/progress item rather than only a raw reusable route point.

The implemented model preserves these rules:

- Producer modules should not import FlowRoutes.
- FlowRoutes should not import producer module private internals.
- Producer modules should emit neutral `AutomationEventRecorded` events for automation-worthy outcomes.
- FlowRoutes should listen to generic automation events and route/resume internally.
- FlowRoutes should call other modules only through public actions/services/contracts.
- Vertical modules may contribute presets, task templates, labels, and public seams without forcing FlowRoutes or Core to know vertical internals.

## FlowRoute capabilities

FlowRoutes uses DB-owned capability and capability binding records as the durable authoring/validation layer.

The runtime path remains:

```text
FlowRouteCapability
→ point type / handler registry
→ public action/service/contract in the consuming module
```

Capabilities describe what is available to a route, including actions, waits, events, conditions, branches, labels, help text, supported subject types, required modules, input schema, output context, and available fields.

Capability bindings decide which capabilities are enabled, visible, or customized for a client/module/context/owner group/vertical.

Capabilities do not give FlowRoutes permission to mutate another module's private tables. Every module-owned effect still goes through that module's public action/service/contract.

## TaskTemplate requirement for create_task points

Task templates are the durable foundation for reusable FlowRoutes `create_task` behavior.

FlowRoutes `create_task` points may reference DB-owned `TaskTemplate` records by stable key, then create live tasks through Tasks-owned public actions. Inline create_task definitions remain supported for route-specific cases.

Tasks remains the owner of task creation, assignment strategy, responsibility fields, related subject handling, due offsets, and task lifecycle behavior. FlowRoutes passes task intent and route provenance; it does not write Task internals directly.

`tasks.task_template_id` may be treated as a soft/current DB reference while `task_template_key` preserves durable historical identity for route-created tasks.

## Route template vs route instance plan

A FlowRoute definition is a reusable template/default automation plan.

A live `ContactFlowRouteProgress` record is a route instance: one contact, and potentially one subject, moving through that plan.

The system needs contact/subject-specific route instance plans so operators can adjust one live route without mutating the reusable template.

Example:

```text
PetServices installs a Dog Behavior Training Route template.
The route applies to a specific contact/dog combo.
The dog’s training takes longer than expected.
The operator adds more appointments or repeats a final behavior check.
The adjustment affects only that contact/dog route instance.
The reusable route template remains unchanged.
```

Current schema/concepts:

```text
ContactFlowRouteProgress
    route instance; owns contact, optional subject, status, current plan/progress item pointers, and lifecycle timestamps.

ContactFlowRoutePlan
    instance-specific plan seeded from a FlowRoute template.

ContactFlowRoutePlanItem
    ordered route instance item; stores point definition/settings snapshots and source such as template/manual/automation/vertical.

ContactFlowRouteProgressItem
    execution attempt/result for a plan item; stores waiting/resume/correlation/result state and created-artifact references.
```

Implemented requirements and durable rules:

```text
ContactFlowRouteProgress supports subject_type / subject_id.
Route start creates a ContactFlowRoutePlan and plan items from the selected FlowRoute template.
Runtime execution advances through plan items, not only mutable FlowRoutePoint records.
Plan items store definition/settings snapshots so template edits do not unexpectedly mutate active route instances.
Blocked, cancelled, and superseded route behavior should leave plan/progress items in accurate non-success states.
Operators may later insert/repeat/skip/cancel specific plan items for one contact/subject when Route Management UX supports it.
Phase 5 event waits and task completion should resume a specific plan/progress item.
Tasks/messages/campaign enrollments/appointments/documents/forms/portal records created by route points attach back through standard FlowRoutes provenance fields.
```


## Uniform route-created artifact provenance

Any module-owned artifact created by FlowRoutes should use the same provenance shape where practical:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
```

Current targets:

```text
tasks
scheduled_messages
campaign_enrollments
```

Future targets should follow the same process:

```text
appointments
document_requests
form submissions/requests
portal invitations/access grants
commerce records where appropriate
vertical-owned records
```

The owning module still owns lifecycle and business state. FlowRoutes owns route provenance, route instance correlation, and route resume matching.

## Event-wait and task-completed resume behavior

Task-completed event-wait resume is implemented on top of the relationship/capability/instance-plan foundation.

Runtime behavior:

```text
Tasks records task completion.
Tasks emits AutomationEventRecorded(task.completed).
FlowRoutes listens to generic AutomationEventRecorded.
FlowRoutes maps the event to FlowRouteExternalEvent internally.
FlowRoutes resumes matching event_wait progress/plan/progress items.
```

Do not add Task-specific FlowRoutes listeners.

Do not make Tasks import FlowRoutes.

Do not rely on contact-only fallback for `task.completed` waits.

For `task.completed`, matching is intentionally stricter than generic event waits because a contact may have multiple open tasks and multiple active route instances.

Supported safe matching paths:

```text
1. Explicit event_wait correlation.
2. Unambiguous route-created Task artifact provenance when the route created exactly one Task before the wait.
```

Explicit correlation should be used when a route may create more than one Task before the wait.

Good examples:

```php
'correlation' => [
    'task.task_template_key' => 'route.follow_up',
    'task.flow_route_progress_id' => '{flow_route_progress.id}',
]
```

```php
'correlation' => [
    'task.flow_route_progress_item_id' => '{flow_route_progress_item.id}',
]
```

Use task-template correlation when the author wants to wait for a specific kind of task created by the route.

Use route progress/plan/progress-item correlation when the wait must be tied to a specific route instance.

Generic contact context may be used as a safety filter, but it must not be the only matching rule for task completion waits.

Tasks may carry contact context from related/responsible Contact records or from FlowRoute provenance when the task is subject-scoped to another record such as a dog, appointment, document request, or other future route subject.

Potential matching dimensions:

```text
event_key
contact_id
subject_type / subject_id
task.id
task.task_template_id
task.task_template_key
task.flow_route_progress_id
task.flow_route_plan_id
task.flow_route_plan_item_id
task.flow_route_progress_item_id
task.flow_route_id
task.flow_route_point_id
task.flow_route_capability_id
```

## Client-facing Route Management terminology

`FlowRoutes` remains the module/domain name.

Client/operator-facing UI may use simpler Route language when that improves comprehension.

Preferred public labels:

```text
Routes
Route Management
Automatic routes
Route points
Automatic actions
What happens next
```

Avoid making these the primary client-facing labels:

```text
FlowRouteTriggerBinding
automation_event
event_wait
FlowRouteExternalEvent
raw event keys
point handler config
```

A navigation item such as `Route Management` may use a contextual hint like:

```text
Choose what automatic actions happen after important contact activity.
```

or:

```text
Manage the automatic routes that create tasks, send messages, update statuses, and start follow-up sequences.
```

Route Management UX should explain available actions through FlowRouteCapability metadata rather than importing module internals.