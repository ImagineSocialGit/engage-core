# FlowRoutes Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

FlowRoutes is optional and depends on Workflow.

Use the term `FlowRoutes`.

Do not casually call this domain “flows,” because that creates confusion with Workflow.

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