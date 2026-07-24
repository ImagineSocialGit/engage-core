# FlowRoutes Module

## Config contract

FlowRoute preset definitions are closed by the registered `flow_routes.preset_definition`
contract plus Point-specific schemas contributed through `AutomationPointDefinitionRegistry`.
Preset authoring uses one compact keyed-map shape:

```text
definitions map key -> FlowRoute.key
points map key -> FlowRoutePoint.key
points map order -> sort_order, active start Point, and active next-Point chain
Point type -> one canonical active FlowRouteCapability
Route source_version -> Point source_version
```

A top-level `contact_status_key` creates a contact-status trigger. A top-level `event_key` creates
an automation-event trigger. Omitting both creates a manual Route. They are mutually exclusive.

The removed verbose route fields `key`, `trigger`, and `meta` are invalid. The removed Point fields
`key`, `capability_key`, `sort_order`, `is_start`, `next_point_key`, `settings`, `source_version`,
and `meta` are also invalid. There is no compatibility authoring path for those fields.
Point-specific executable values remain inside the module-owned `definition` object.

Normalization keeps runtime/database records explicit: capabilities, order, start state, next IDs,
settings, source versions, and registered Point-definition defaults are materialized during preset
sync. Disabled Points remain persisted but are omitted from the derived active chain.

Active runtime routes must resolve to exactly one executable start point. A zero-start validation
finding usually indicates preserved customized database state rather than permission to weaken the
contract; reconcile the customized route or deliberately resync it. Capability availability and
handler registration remain semantic checks in setup validation.

A FlowRoute that points to an inactive Campaign remains valid and active. The `enroll_campaign`
Point completes as skipped with `campaign_inactive`; it does not disable or invalidate the Route.
Campaign reactivation permits later events to create new enrollments, while previously cancelled
enrollments and skipped ScheduledMessages remain terminal.

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

FlowRoutes is optional and depends on Workflow.

Use the term `FlowRoutes` in developer/module docs where precise ownership matters.

Do not casually call this domain “flows,” because that creates confusion with Workflow. Client/operator-facing UI may use `Routes` or `Route Management` when the screen is explaining automatic actions in plain language.

FlowRoutes owns:

- `FlowRoute`
- `FlowRoutePoint`
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

FlowRoute preset sync requires referenced Campaign definitions to exist by stable key. An inactive or archived Campaign is a valid dormant reference; runtime enrollment skips it as `campaign_inactive` until the Campaign returns to `active`.

Status-triggered FlowRoutes also assume required ContactStatus definitions already exist.

Normal setup should use the app-level `presets:sync` command so dependencies are created first.

## Runtime execution budget and continuation

Immediately advancing points execute in bounded process slices. The root/process-owned
`FLOW_ROUTE_IMMEDIATE_EXECUTION_BUDGET` setting controls the maximum number of points in one
slice and defaults to `25`.

Exhausting that budget is not a terminal or waiting Route state. FlowRoutes keeps the progress
`active`, records an `immediate_execution_continuation` handoff in progress metadata, and
dispatches `ContinueFlowRouteProgressJob` to `FLOW_ROUTE_CONTINUATION_QUEUE`. The continuation
job executes another bounded slice and repeats this persisted/scheduled handoff until the Route
completes, waits, blocks, fails, or is cancelled/superseded.

The continuation job is unique until processing and uses a per-progress overlap lock. Operators
should treat a failed continuation job together with `immediate_execution_continuation.status =
failed` as actionable queue/runtime failure; the Route remains active so it can be inspected and
deliberately retried.


### Module-first preset contribution architecture

Preset contributions are module-first and explicitly registered.

Examples:

```text
config/presets/modules/webinars/contact-statuses.php
config/presets/modules/webinars/campaigns.php
config/presets/modules/webinars/flow-routes.php

config/presets/modules/{contributor-module}/tasks.php
config/presets/modules/{contributor-module}/campaigns.php
config/presets/modules/{contributor-module}/flow-routes.php
```

FlowRoutes does not care which contributor file supplied a normalized Route definition. `PresetContributionRegistry`, `PresetPackageResolver`, and `PresetCompositionResolver` resolve selected definitions before `SyncFlowRoutePresetsAction` receives a `ResolvedPresetDomain`.

Keep these concepts separate:

```text
module availability
preset contribution availability
client package selection
runtime trigger binding/activation
```

Enabling a module does not automatically activate every preset it contributes. Installed preset contributors may remain discoverable even when the runtime module is disabled.

Preset groups are composition-only and are not persisted as durable route ownership.

## Automation Point extension architecture

FlowRoutes now uses shared Support-layer registries so modules can contribute Route behavior without adding branches to central FlowRoutes schema, validation, execution, editor, or presentation switchboards.

Implemented registries:

```text
AutomationCapabilityRegistry
    declares stable capability metadata and required modules

AutomationPointDefinitionRegistry
    point type -> contributor-owned ConfigSchema
    contributor-owned semantic/domain-reference validation

AutomationActionRegistry
    stable action key -> module-owned neutral AutomationActionHandler

AutomationPointAuthoringRegistry
    point type -> module-owned authoring availability, fields, rules,
    definition building, warnings/guidance, names, and summaries
```

Current ownership split:

```text
FlowRoutes owns native orchestration Points
    noop
    wait
    event_wait
    condition
    branch_evaluate
    change_status

Tasks owns
    create_task definition/validation
    tasks.create_task neutral business action
    create_task authoring UX

Messaging owns
    send_message definition/validation
    messaging.dispatch_message neutral business action
    send_message authoring UX and direct-Route template eligibility

Campaigns owns
    enroll_campaign definition/validation/action/authoring
    cancel_campaign definition/validation/action/authoring
```

`FlowRoutePointType` remains the shared stable Point vocabulary. A genuinely new Point type may still require one intentional enum edit; the architecture goal is to avoid unrelated central schema/validator/editor/runtime switchboard edits for every contributed capability.

`FlowRoutePointPlacementPolicy` remains FlowRoutes-owned because Wait-terminal and Change-Status-terminal rules are Route-structure semantics, not module business-action semantics.

### Neutral business-action execution

Cross-module business actions execute through module-owned `AutomationActionHandler` implementations registered in `AutomationActionRegistry`.

FlowRoutes uses one generic `AutomationActionPointHandler` adapter to:

```text
resolve the module-owned business action
supply current contact/current subject/source/behavior context
execute the neutral action
record created-artifact identity and correlation in FlowRoutes-owned state
translate the neutral result into PointExecutionResult
```

Module-owned automation actions must not depend on FlowRoutes progress models, Point handlers, or execution DTOs merely to be reusable by a Route. The same business action may later be reused by another automation surface without duplicating domain behavior.

### Module-owned authoring contributions

Authoring is separate from Point schema/runtime validation. A module-owned `AutomationPointAuthoringContributor` may own:

```text
availability
client-facing name and description
tips and use cases
warnings/callouts
field definitions and resolved options
request rules
definition building
generated Point name
Route summary
editor summary
```

The Route editor renders contributed field definitions generically rather than using one central Point-type Blade switch. Supported field shapes include notices, selects, checkboxes, textareas, numeric/date-time fields, and ordinary inputs.

Server-side authoring checks contributor-owned availability and request rules even when a crafted request bypasses the UI.

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
    FlowRoutePoint belongs to exactly one FlowRoute version
    FlowRoutePoint directly owns type/name/description/definition/settings/cancel conditions
    ContactFlowRouteProgress records active/waiting/completed/cancelled execution state

FlowRoutes runtime behavior should read DB-owned `FlowRoute` / `FlowRoutePoint` definitions.


## Completed global Point collapse

The old global `Point` model/table/template layer has been removed.

The durable definition model is now:

```text
FlowRoute
    reusable logical Route revision

FlowRoutePoint
    one concrete configured action/wait/condition belonging to exactly one FlowRoute version

FlowRouteCapability
    describes what kinds of actions/waits/conditions/events are available

ContactFlowRoutePlanItem
    immutable runtime snapshot of one FlowRoutePoint for one route instance

ContactFlowRouteProgressItem
    execution attempt/result history for that plan item
```

Removed concepts:

```text
Point model
points table
PointPresetDefinition
flow_route_points.point_id
contact_flow_route_plan_items.point_id
contact_flow_route_progress_items.point_id
Point.default_definition
Point.default_settings
$flowRoutePoint->point
{point.id}
condition source = point
```

`FlowRoutePoint` now directly owns:

```text
flow_route_id
flow_route_capability_id nullable
key
type
name
description
sort_order
is_start
is_active
next_flow_route_point_id nullable
definition
settings
cancel_conditions
source_version
is_customized
customized_at
meta
```

`FlowRoutePointType` is the shared string-backed type vocabulary.

`FlowRoutePoint.key` remains the durable cross-version reconciliation identity.

Do not recreate global shared Point templates or shared mutable Point linkage across Routes.

Reuse should happen at the correct domain layer:

```text
Tasks owns Task Templates.
Messaging owns Message Templates.
Campaigns owns Follow-up Sequences.
FlowRoutes orchestrates those capabilities.
FlowRoutePoint is one concrete configured action inside one Route version.
```

A Route editor may allow copying an existing `FlowRoutePoint` from another current Route, but cloning creates a new independent `FlowRoutePoint`. Later edits must not propagate back to the source Route.


## Send-message point behavior ownership

A FlowRoute `send_message` point is reached because FlowRoutes execution has already progressed through the Route's prior Points. Reusable Messaging templates must not inject a hidden second workflow layer.

Durable rule:

```text
FlowRoutes
    owns when execution reaches the send_message Point
    owns Route waits and Point-specific behavior

Messaging template
    owns reusable content and delivery-template metadata

ResolvedMessageDispatchBuilder
    assembles the selected template with the FlowRoute-resolved dispatch behavior
```

By default, reaching a `send_message` Point means the message is eligible to send at the Point's resolved execution time. Any additional delay or condition must be explicitly owned by the FlowRoute Point/Route definition, not inherited from reusable template timing.

The resulting `ResolvedMessageDispatch` may preserve the `FlowRoutePoint` as polymorphic behavior provenance. Messaging should not import FlowRoutes internals to interpret the Point.

Direct Route authoring must not expose every active Messaging template. A template is eligible for direct Route use only through the explicit Messaging-owned eligibility seam:

```text
MessageTemplatePreset.meta.route_authoring.eligible = true

or

active MessageTemplateCatalogEntry.meta.route_authoring.eligible = true
```

Additional rules:

```text
template must be active
template must have at least one dispatch key
internal-purpose templates are never eligible for direct Route authoring
```

This explicit opt-in prevents lifecycle-owned templates such as webinar confirmations, webinar reminders, Campaign-step messages, permission invitations, and internal notifications from leaking into the generic Route message picker merely because they exist.

The Route editor should hide `Send message` entirely when no direct-Route-eligible Messaging template exists. Server-side authoring must validate the same eligibility rule and reject an ineligible template even when a request bypasses the UI.

## Manual contact-status automation impact preview

FlowRoutes owns the backend consequence-preview seam for manual contact-status changes.

Current public read services:

```text
FlowRouteTriggerBindingResolver::selectedFlowRoutesForContactStatus(...)
ContactStatusAutomationImpactResolver::forContactStatus(...)
```

The plural trigger resolver returns every currently selected active FlowRoute for a ContactStatus trigger.

The impact resolver is read-only and returns compact consequence data:

```text
has_automation
status_id
status_key
status_name
route_count
routes[]
    id
    key
    name
```

Rules:

- inactive bindings are ignored;
- inactive routes are ignored;
- preview resolution must not start route progress;
- preview resolution must not mutate Workflow or Contact state;
- no acknowledgement or warning state is persisted;
- Core and Workflow should not import FlowRoutes internals to calculate automation impact.

The eventual operator warning UX should consume this FlowRoutes-owned read seam rather than duplicating trigger-binding queries in Core or Workflow.

The actual warning/confirmation interaction remains deferred to a focused Routes consequence-warning UX slice.

## Routes / Route Management product direction

The Route Management audit and first authoring slices established the current client/operator product direction.

Client-facing information architecture:

```text
Routes
    Manage Routes
    Assignments
```

Conceptually:

```text
Manage Routes
    What does this Route do?

Assignments
    When does this automation run?
```

`FlowRoutes` remains the internal/module name. Normal client/operator UI should use `Routes`, `Manage Routes`, `Assignments`, `Route flow`, and `Point` where those terms improve comprehension.

Current implemented Manage Routes behavior:

```text
list multi-step Routes
show assigned vs not assigned state
show business-language trigger and consequence summaries
expand/collapse Route flow
open Route editing in a modal without leaving the index
edit existing Points in a modal
add supported Points
remove Points directly from the Route flow
move Points up/down
drag Points to reorder
save changed drag order explicitly
preserve module-tone wayfinding for cross-module Points
separate one-step automatic behavior from multi-step Routes
show search/assignment filters only when five or more multi-step Routes exist
```

Current implemented authorable Point types:

```text
Wait
Change contact status
Create task
Send message
Start Campaign
Stop Campaign
```

Automatic Route actions must explain repetition and consequences, not merely expose technically valid fields.

For `create_task`, the Tasks-owned authoring contributor enforces:

```text
Create a Task automatically
    requires an active Task Template
    creates a new Task every time a record reaches the Point
    does not create a one-time Task now
    does not expose a title-only freeform automation path
```

The editor should apply the same consequence-first posture to other automatic actions: explain repeated execution, skipped/no-op behavior, availability constraints, and the condition that allows a wait to resume.

Advanced internal Point types may exist in the runtime model, but the normal Route editor does not expose arbitrary branching, graph editing, joins, connectors, nested branch trees, or generic node-canvas behavior.

The durable product rule is:

> Routes are explicitly linear. Their purpose is to remove repetitive coordination and managerial work, not to become a generic automation canvas.

A useful product test is:

```text
Would a human assistant normally have to remember to do this?
    yes -> likely Route material

Is the behavior inherently part of one module's domain?
    yes -> likely module-owned automation instead
```

Examples of good Route material:

```text
status changes to Attempting Contact
→ create an initial task
→ wait 5 days
→ create another follow-up task

webinar attended
→ create internal follow-up work
→ start a Campaign
→ change status

message sent
→ wait
→ create a follow-up task when later work is still needed
```

Examples that should normally remain module-owned:

```text
Scheduling sends an appointment reminder relative to appointment time.
PetServices schedules a vaccination reminder from vaccination expiry.
Music reacts to a new show through Music-owned show behavior.
```

Current product-completeness gaps include:

```text
create a new Route from the client/operator UI
duplicate a Route
activate/deactivate a Route
change a Route trigger
clone a Point from another Route
task assignment/default authoring inside create-task Point UX
business-day/business-hour wait authoring
simple future point eligibility / route-continuation rules
contextual Automation Opportunity suggestion UX
manual status-change consequence warning UX
```

Do not reintroduce a reusable global Point library merely to make Route authoring easier. Copying from another Route, when added, must clone into a new independent `FlowRoutePoint`.

Contextual automation suggestions are the discovery layer. Routes remains the control center for reviewing what happens automatically.

The backend `ContactStatusAutomationImpactResolver` remains the source of truth for the eventual warning shown before a manual status change that would start selected Route automation.

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

A trigger binding selects a Route.

The selected Route may contain many Points.

Example:

```text
Prospect status changed
→ selected Prospect Route
    → create task
    → wait
    → send message
    → change status
```

Those are Points in one Route, not several unrelated active Routes competing for the same trigger.

The normal client/operator authoring product is deliberately linear.

Do not expose:

```text
arbitrary branching
true/false path canvases
joins
nested branch trees
connectors
generic node-editor behavior
arbitrary jump-back loops
```

Internal runtime support for advanced Point types does not make those concepts appropriate for normal Route authoring.

Current Point placement policy:

```text
Wait
    may be first or middle
    cannot be the final Point

Change Status
    must be the final Point

Create Task
    may occur anywhere

Send Message
    may occur anywhere

Start Campaign
    may occur anywhere

Stop Campaign
    may occur anywhere when available
```

The placement policy is server-authoritative and is evaluated against the proposed resulting sequence for structural mutations such as:

```text
add
remove
move up/down
drag-and-drop reorder
```

This matters because removing one Point can make another Point invalid. For example:

```text
Create task
→ Wait
→ Send message
```

Removing `Send message` would leave `Wait` terminal, so removal must be rejected.

Current authoring behavior also preserves valid placement automatically where practical:

```text
adding Wait
    inserts it before the current final Point

adding a normal Point when Change Status is terminal
    inserts the new Point before Change Status
```

The UI mirrors the domain policy:

```text
terminal Change Status has no drag handle
invalid move controls are disabled
a removal that would leave Wait terminal is disabled with an explanatory hover/focus tip
an invalid attempt to drag Wait into the terminal position is shown locally at the terminal slot rather than as a page-level alert
```

The backend remains authoritative. Frontend restrictions are guidance, not the only enforcement.

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

The normal Route editor intentionally exposes only the supported linear subset documented above.

FlowRoutes may create Tasks through public task-facing services/contracts.

FlowRoutes `create_task` Points may create assigned or unassigned Tasks at runtime. The current first authoring slice does not yet expose full task-assignment authoring.

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

For automation-event-started Routes, `contact_status_id` and `contact_workflow_profile_id` may be null on `contact_flow_route_progress`.

That is expected.

It means the Route started from an automation event rather than a Workflow status transition.

FlowRoutes may support Campaign, Messaging, Task, and status-related Point types, but client-facing Route selection/building must be capability-aware. Point types whose owning modules are disabled should be hidden, disabled, or clearly marked unavailable. Campaign-related Points must not appear as selectable client-facing behavior for clients without Campaigns enabled.

## Relationship, capability, and instance-plan status

The Phase 4A audit and Phase 4B implementation are complete for backend/schema readiness. FlowRoutes now has subject-scoped route instances, instance-specific route plans, plan items, progress/execution items, capability catalog/bindings, and durable created-artifact tracking/correlation. Phase 12 revises the Tasks-specific direct provenance coupling while preserving FlowRoutes-owned correlation.

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

Compact presets do not author `capability_key`. `FlowRoutePresetDefinitionFactory` resolves the one active registered capability whose `point_type` matches the authored Point type. Zero matches means the Point type is unavailable; multiple matches are ambiguous and fail normalization. The derived capability is then stored explicitly on the normalized `FlowRoutePoint`.

Capability bindings decide which capabilities are enabled, visible, or customized for a client/module/context/owner group/vertical.

Capabilities do not give FlowRoutes permission to mutate another module's private tables. Every module-owned effect still goes through that module's public action/service/contract.


## FlowRoutes setup validation ownership

`FlowRoutesSetupValidationContributor` owns Route-level orchestration and runtime consistency checks. Point-specific schemas and semantic/domain-reference checks are delegated through `AutomationPointDefinitionRegistry` to the module that owns that Point behavior.

FlowRoutes validation uses these sources of truth:

```text
FlowRoute preset definition DTOs
AutomationPointDefinitionRegistry for Point-specific schemas and validation contributors
AutomationCapabilityRegistry for declared capabilities
PointHandlerRegistry for executable Point types in the current installation
DB-owned FlowRouteCapability / FlowRouteCapabilityBinding records where runtime/client context matters
DB-owned active/current route, trigger-binding, progress, and plan state
```

Shared preset-composition validation owns package/group/definition structure, including missing selected groups and duplicate contributed group/definition keys.

FlowRoutes itself validates at least:

```text
definition and Point map keys are valid durable identities
contact-status and automation-event trigger references are mutually exclusive and supported
Point types have registered definition schemas and executable handlers for the current installation/context
one canonical active capability can be derived for each Point type
required capability modules are available
supported subject types match Route/capability assumptions
ordered active Points normalize to one start Point and a safe next-Point chain
route graph integrity
route-instance plan/snapshot assumptions are supported
runtime capability/catalog consistency
active/current Route consistency
runnable progress/plan consistency
active trigger binding integrity
```

Point-definition contributors own Point-specific checks. Current examples:

```text
FlowRoutes contributor
    wait/event_wait/condition/branch/change_status parsing
    ContactStatus target validation
    branch target validation

Tasks contributor
    create_task parsing
    required TaskTemplate key and availability
    assignment strategy availability

Messaging contributor
    send_message parsing
    dispatch/template reference validation

Campaigns contributor
    enroll/cancel Campaign parsing
    Campaign availability
```

A configured Route Point that cannot execute because its handler, required module, capability, Point-definition contributor, or required referenced definition is unavailable is a hard error for that selected setup.

Do not restore a central FlowRoutes validator that imports every producer module's private models, DTOs, or resolution services.

## TaskTemplate requirement for create_task points

Task templates are required for automation-created Tasks.

FlowRoutes `create_task` points are automation creation paths and therefore must resolve a DB-owned `TaskTemplate` by stable key before creating a live Task.

Tasks owns the Point-definition contract through `TasksAutomationPointDefinitionContributor`, the neutral business action through `CreateTaskAutomationActionHandler`, and the normal editor contribution through `TasksAutomationPointAuthoringContributor`. FlowRoutes owns only the surrounding Route orchestration and its generic neutral-action adapter/correlation responsibilities.

Durable rule:

```text
no-template Task
    manual only

template-backed Task
    manual or automation-created
```

Inline arbitrary no-template `create_task` definitions are not part of the durable contract.

FlowRoutes should call Tasks-owned public actions/services. Tasks remains the owner of:

```text
Task creation
TaskTemplate resolution rules
TaskTemplate.link_defaults resolution
assignment strategy
responsibility fields
TaskLink creation
TaskLink role vocabulary
due offsets
Task lifecycle behavior
```

When creating a Task, FlowRoutes may supply generic creation context such as:

```text
current_contact
    the route's Contact when present

current_subject
    the route instance's current subject when present
```

Tasks resolves any `TaskTemplate.link_defaults` from that generic context and creates/de-duplicates TaskLinks. FlowRoutes should not create TaskLink rows directly or teach Tasks about FlowRoutes-specific subject types.

FlowRoutes owns automation intent, route execution, created-artifact identity, correlation, and resume matching.

The target creation path is:

```text
FlowRoute create_task point
    -> resolve TaskTemplate by stable key
    -> supply generic current_contact/current_subject creation context
    -> call Tasks public action
    -> Tasks resolves link_defaults and explicit links
    -> Tasks creates live Task and de-duplicated TaskLinks
    -> FlowRoutes records created Task type/id in FlowRoutes-owned progress state
```

`tasks.task_template_id` may remain a soft/current DB reference while `task_template_key` preserves durable historical TaskTemplate identity.

## FlowRoute definition versioning and live-instance reconciliation

`FlowRoute.key` is the durable logical route identity.

`FlowRoute.version` is the definition revision.

`FlowRoute.is_current_version` identifies the selected revision for that logical route key. `is_active` answers whether that selected revision is enabled for runtime use.

The intended meaning is:

```text
current
    selected logical revision

active
    selected revision is enabled

historical
    older revision retained for history/provenance
```

New route starts should use the current active revision.

A new current revision does not permanently pin active or waiting route instances to the revision on which they started. Runnable instances on an older revision should reconcile immediately to the new current revision.

Reconciliation rules:

```text
map route points by durable FlowRoutePoint.key
carry completed state forward when the same point key still exists
carry current/waiting state forward when the same point key still exists
preserve wait timing, event keys, and correlation state
create a new ContactFlowRoutePlan revision
retain old plans as historical execution evidence
mark the old current plan superseded
record reconciled_from_plan_id on the replacement plan
```

An unmappable current/waiting point is a hard reconciliation conflict.

Do not guess, silently skip, restart, cancel, or choose another point automatically.

Route-definition sync should be transactional so a reconciliation conflict rolls back the revision switch instead of leaving route definitions, bindings, and live instances in mixed states.

When a new revision becomes current:

```text
sibling route revisions become historical
old preset-owned default trigger bindings deactivate
the new current default binding activates when the route is active
an inactive current route does not retain an active preset-owned default binding
```

FlowRoute history must remain queryable. Core route-history foreign keys should not cascade-delete route revisions that are still needed as provenance/history.

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
Route-created artifacts should be correlated through FlowRoutes-owned created-subject references and explicit correlation state without forcing every artifact-owning module to store FlowRoutes-specific foreign keys.
```


## Route-created artifact provenance and correlation

FlowRoutes owns route provenance, created-artifact references, correlation, and resume matching.

Do not interpret that ownership as a requirement for every artifact-owning module to import FlowRoutes models or add the same `flow_route_*` foreign keys.

Preferred shape:

```text
FlowRoutes progress/execution item
    created_subject_type
    created_subject_id
    correlation when needed

Owning module artifact
    owns business state and lifecycle
    remains independent from FlowRoutes internals
```

For Tasks specifically, the current implementation is:

```text
FlowRoute create_task point
    -> Tasks public action
    -> template-backed Task
    -> FlowRoutes records created Task identity
    -> Task lifecycle proceeds independently
    -> Task emits neutral automation event
    -> FlowRoutes matches/resumes through FlowRoutes-owned correlation state
```

Tasks should not store FlowRoutes-specific foreign keys or import FlowRoutes-owned models merely to preserve route provenance.

The same ownership test should be applied to future route-created Appointments, DocumentRequests, Forms, Portal records, Commerce records, and vertical-owned records.

Uniformity is useful only when it preserves module ownership. Do not add provenance columns merely for schema symmetry.

Some existing artifact families may retain established provenance fields where independently justified. That does not make those columns a universal requirement for future modules.

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
2. Unambiguous FlowRoutes-owned created Task identity when the route created exactly one Task before the wait.
```

Explicit correlation should be used when a route may create more than one Task before the wait.

The matching state belongs to FlowRoutes. Tasks should emit neutral Task identity/context and remain independent from FlowRoutes internals.

Use TaskTemplate identity when the author wants to wait for a specific kind of Task created by the Route.

Use FlowRoutes-owned created-subject identity and explicit correlation state when the wait must be tied to a specific Route instance or progress item.

Generic Contact context may be used as a safety filter, but it must not be the only matching rule for Task completion waits.

A Task may carry Contact context through TaskLinks or responsibility. A Task may also be completely contactless.

Durable matching dimensions may include:

```text
event_key
contact_id nullable
event subject_type / subject_id = Task
task.id
task.task_template_id
task.task_template_key
TaskLink context when explicitly needed
FlowRoutes-owned created_subject_type / created_subject_id
FlowRoutes-owned explicit correlation state
```

Do not document `task.flow_route_*` fields as durable matching dimensions. Phase 12 removed that Tasks-owned structural dependency. Explicit event-wait correlation may use neutral Task event fields such as `task.task_template_key` when they truthfully distinguish the intended Task; Route-instance identity and specific created-artifact matching remain FlowRoutes-owned.

## Relationship to Automation Opportunities

FlowRoutes does not own behavior observation, correlation evidence, opportunity aggregation, or generic qualification.

Responsibility split:

```text
AutomationEventRecorded
    neutral domain outcome seam

Automation Opportunities
    stores compact opted-in behavior/correlation evidence
    aggregates repeated evaluated patterns
    applies generic qualification

Automation capability registry
    describes what can be automated

FlowRoutes
    owns accepted route/control-flow definition and execution
```

Some `AutomationBehaviorOccurrence` rows are evidence only and must not create opportunities by themselves:

```text
task.completed_manually
automation_event.recorded
```

Current evaluated patterns include:

```text
task.created_manually
task.created_after_manual_status_change
contact.status_changed_after_manual_task_completion
task.created_after_automation_event
```

Current generic qualification defaults are:

```text
3 occurrences
3 distinct subjects
30-day observation window
```

The generic evaluator should remain free of FlowRoutes/module/event-specific branching.

Automation Opportunities may reference stable capability keys where applicable. Shared infrastructure should not canonically depend on `flow_route_capability_id` merely to represent that a behavior is automatable.

Equivalent-automation checks should be dynamic because Routes, points, capabilities, bindings, active state, and versions can change. Do not persist a permanent `already_automated` truth on an opportunity.

Producer modules must not depend on FlowRoutes to record manual behavior or evidence occurrences.

Current selected event evidence may include:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

That allowlist is evidence policy, not a list of events that automatically deserve suggestions.

Client-facing Route discovery may use contextual suggestions such as:

```text
You've created this Task for 3 Contacts in Attempting Contact.
Add it to their Route so it happens automatically next time?
```

Route Management remains the control center for reviewing what happens automatically. Contextual automation suggestions are the discovery layer.

The backend opportunity foundation is complete and manually smoke-tested. The next work belongs in Route Management/product-completeness and contextual suggestion UX, not in expanding FlowRoutes ownership of the opportunity subsystem.

## Client-facing Route Management terminology

`FlowRoutes` remains the module/domain name.

Client/operator-facing UI should use simpler Route language.

Current information architecture:

```text
Routes
    Manage Routes
    Assignments
```

Preferred public labels:

```text
Routes
Manage Routes
Assignments
Edit Route
Route flow
Show route flow
Hide route flow
Point
Automatic Behavior
Start Campaign
Stop Campaign
```

Avoid making these primary client-facing labels:

```text
FlowRouteTriggerBinding
automation_event
event_wait
FlowRouteExternalEvent
raw event keys
point handler config
campaign enrollment
follow-up sequence
```

Use `Campaign` consistently for Campaign-owned journeys. Do not rename Campaigns to `follow-up sequence` inside Routes.

The Route index should not repeat assignment detail inside Route details. `Runs when` belongs to Assignments; Manage Routes answers what the Route does.

One-step automatic behavior may be presented separately from multi-step Routes so a simple action is not forced into the same visual weight as a real Route.

Route Management UX should explain available actions through `FlowRouteCapability` metadata and module-owned public seams rather than importing module internals.