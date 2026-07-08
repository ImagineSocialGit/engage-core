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

Current models:

    FlowRoute
    FlowRouteTriggerBinding
    Point
    FlowRoutePoint
    ContactFlowRouteProgress

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


## Relationship, capability, and instance-plan audit

Before deeper FlowRoutes runtime work, complete a FlowRoutes relationship, capability, and instance-plan audit.

This audit must happen before implementing task-completed event-wait resume behavior, because resume behavior may need to target a specific route instance plan item rather than only a raw reusable route point.

The audit must map FlowRoutes against every current/planned universal and vertical module and decide, for each module:

```text
Does the module produce automation events?
Does the module expose public actions FlowRoutes may call?
Does the module contribute point handlers?
Does the module contribute route presets?
Does the module contribute task templates?
Does the module contribute capability metadata/labels/contextual hints?
Does the module expose records that routes can be scoped to through subject morphs?
```

Modules to include:

```text
Messaging
InboundMessaging
InternalNotifications
Tasks
Workflow
Campaigns
Broadcasts
Webinars
Reporting
Scheduling
Portal
Forms
Documents
Commerce
Location
Mortgage
PetServices
Music
```

The audit should preserve these rules:

- Producer modules should not import FlowRoutes.
- FlowRoutes should not import producer module private internals.
- Producer modules should emit neutral `AutomationEventRecorded` events for automation-worthy outcomes.
- FlowRoutes should listen to generic automation events and route/resume internally.
- FlowRoutes should call other modules only through public actions/services/contracts.
- Vertical modules may contribute presets, task templates, labels, and public seams without forcing FlowRoutes or Core to know vertical internals.

## Point capability registry default

Do not add a vertical/point reconciliation table without proving it.

The default path should be:

```text
FlowRoutes point type
→ handler registry
→ optional public action/service in the consuming module
```

A DB-owned capability/binding table may be needed only if the audit proves one of these needs:

```text
one point must be bound to a specific vertical-owned record;
the same generic point type behaves differently depending on a selected vertical domain object;
operators need to select from DB-owned vertical capabilities at runtime;
route presets need durable references to vertical-installed capability records instead of config keys.
```

Until then, prefer registry/config/provider seams.

## TaskTemplate requirement for create_task points

Task templates are required foundation for durable FlowRoutes `create_task` behavior.

FlowRoutes `create_task` points should not hardcode every reusable task shape inline forever.

Phase 3 must settle how `create_task` points reference DB-owned `TaskTemplate` records.

Questions to settle before deeper FlowRoutes runtime work:

```text
Can a create_task point reference a TaskTemplate by id or stable key?
Does TaskTemplate contain enough fields for title/body/default due offsets/assigned_to/responsible_party/related subject rules?
Should FlowRoute point config override template defaults for one route?
Should route presets reference task template keys while runtime resolves DB records?
Should vertical modules contribute task templates without Tasks becoming vertical-specific?
Should customized TaskTemplates be preserved during sync?
```

## Route template vs route instance plan

A FlowRoute definition is a reusable template/default automation plan.

A live `ContactFlowRouteProgress` record is a route instance: one contact, and potentially one subject, moving through that plan.

The system may need contact/subject-specific route instance plans so operators can adjust one live route without mutating the reusable template.

Example:

```text
PetServices installs a Dog Behavior Training Route template.
The route applies to a specific contact/dog combo.
The dog’s training takes longer than expected.
The operator adds more appointments or repeats a final behavior check.
The adjustment affects only that contact/dog route instance.
The reusable route template remains unchanged.
```

Possible schema/concept names:

```text
FlowRoutePlan
FlowRoutePlanItem
ContactFlowRouteProgressItem
ContactFlowRouteProgressAdjustment
Route instance snapshot
```

Audit questions:

```text
Should ContactFlowRouteProgress support subject_type / subject_id?
Should route execution snapshot route points into progress items when a route starts?
Should live route instances execute from the mutable template, or from a plan snapshot?
Should operators be able to insert/repeat/skip/cancel specific plan items for one contact/subject?
Should each plan item track source = template/manual/vertical/automation?
Should plan items store config_snapshot json so template changes do not unexpectedly mutate active instances?
Should event waits and task completion resume a specific plan item rather than only a raw route point?
Should appointments/tasks/messages created by route points attach back to the specific progress item?
```

Do not add route instance plan tables until the audit decides the right shape.

But route instance adjustment is now a first-class schema-discovery concern before production.

## Event-wait and task-completed resume implementation

Implement task-completed resume after the relationship/capability/instance-plan audit.

Target behavior:

```text
Tasks records task completion.
Tasks emits AutomationEventRecorded(task.completed).
FlowRoutes listens to generic AutomationEventRecorded.
FlowRoutes resumes matching event_wait/progress/plan items internally.
```

Do not add Task-specific FlowRoutes listeners.

Do not make Tasks import FlowRoutes.

Add wait-correlation schema only if existing progress/plan metadata is insufficient.

Resume matching should be scoped enough to avoid unrelated task completions resuming the wrong route instance.

Potential matching dimensions:

```text
event_key
contact_id
subject_type / subject_id
task_id
task_template_id or task_template_key
flow_route_id
flow_route_point_id or plan_item_id
contact_flow_route_progress_id
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

Phase 4 should audit whether point handlers/capabilities need label and hint metadata from module providers so Route Management can explain available actions without importing module internals.
