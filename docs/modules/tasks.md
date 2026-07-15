# Tasks Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Tasks is a reusable capability module.

Tasks owns tracked manual human actions and dependencies.

A Task is one generic live work record representing something a human needs to do, complete, provide, review, confirm, or manually resolve.

Do not model Tasks as mutually exclusive categories such as “standalone task,” “Contact task,” “automation task,” or “template task.” A Task is instead described by independent dimensions.

## Core Task dimensions

Every Task is described across three independent dimensions.

### 1. Template linkage

```text
template-backed
or
no template
```

A template-backed Task references reusable `TaskTemplate` definition/default infrastructure.

A no-template Task is an ad hoc manual Task.

A TaskTemplate is optional for manual Task creation. It is not a prerequisite for a live Task to exist.

Repeated similar manual no-template Tasks are the primary Task-created signal for Automation Opportunity suggestions. The product should be able to notice repeated ad hoc work and suggest converting that repeated pattern into reusable automation rather than requiring the user to design automation first.

### 2. Domain linkage

```text
unlinked
or
linked to one or more relevant domain records
```

A Task may have zero, one, or many generic cross-module links.

Examples:

```text
Standalone internal work
    Task: Prepare quarterly marketing plan
    links: none

Contact follow-up
    Task: Call Jane about financing questions
    links:
        Contact Jane Smith -> subject

Pet Services scheduling work
    Task: Schedule Max's annual vaccination appointment
    links:
        Pet Max -> subject
        Contact Jane Smith -> context

After the appointment is created
    Task: Schedule Max's annual vaccination appointment
    links:
        Pet Max -> subject
        Contact Jane Smith -> context
        Appointment #482 -> result
```

Domain links are independent of template linkage and creation origin.

### 3. Creation origin

```text
manual
or
automation-created
```

Durable rule:

```text
no template
    -> manual only

template-backed
    -> may be manual or automation-created
```

Any non-manual Task creation path should identify the reusable TaskTemplate that defined the work. Automation should not silently create arbitrary no-template Tasks.

The three dimensions combine freely within those rules. For example, a template-backed Task may be manually created and unlinked, or automation-created and linked to several module-owned records.

## Task relationship architecture

The durable target is one generic relationship system owned by Tasks.

Do not preserve two competing relationship models such as:

```text
tasks.related_type / tasks.related_id
plus
task_links
```

The target is to replace the single nullable `related` morph with zero-to-many polymorphic Task links.

Minimum target structure:

```text
task_links
    id
    task_id
    linkable_type
    linkable_id
    role
    timestamps
```

Initial generic roles:

```text
subject
    What the work is principally about.

context
    An additional record that helps explain why the Task exists or how to understand it.

result
    A record produced, selected, or attached as the outcome of doing the Task.
```

Keep this role vocabulary intentionally small. Do not add Task-owned roles such as `pet`, `borrower`, `appointment`, `loan`, `customer`, or other module-specific concepts.

TaskLink identity should remain minimal and deterministic:

```text
task_id + linkable_type + linkable_id + role
```

The same record should not be duplicated more than once for the same Task and role. A record may appear under different generic roles only when that distinction is truthful and useful. Task-link creation should normalize and de-duplicate equivalent links rather than creating duplicate rows accidentally.

The linked module owns the domain meaning of its records. Tasks owns only the generic connection and generic role.

### Relationship design rules

- A Task may be unlinked.
- A Task may link to one record.
- A Task may link to several records across different modules.
- Links may be added as work progresses. A Task may begin with `subject` and `context` links and later gain a `result` link.
- The same linked record may appear on its own module-owned page, the global Tasks index, and the Task show page without Tasks understanding that module's private semantics.
- Core Contact pages may query Tasks through Tasks-owned public read/provider seams. Core must not import Tasks.
- Other modules may query or create Task links through Tasks-owned public seams rather than writing `task_links` directly when a public action/service exists.
- Do not store canonical Task relationships as unindexed IDs inside `meta`.
- Do not add module-specific nullable foreign keys such as `contact_id`, `pet_id`, `appointment_id`, `document_request_id`, or `mortgage_file_id` to `tasks`.

### Linked-record presentation

Persistence and presentation are separate questions.

A Task may be linked to several records, but the UI should present those links in a way that immediately answers:

```text
Why does this Task exist?
What is this Task about?
What does the user need to do next?
Where should the user go to complete or review it?
```

Tasks should own a resolver/provider seam for presenting linked records generically.

Conceptual direction:

```text
Tasks
    owns TaskLink storage
    owns linked-record presentation contract/registry
    owns fallback presentation

Core
    may provide Contact presentation because Tasks depends on Core

Scheduling
    may contribute Appointment presentation

Documents
    may contribute DocumentRequest presentation

PetServices
    may contribute Pet presentation

Mortgage
    may contribute Mortgage-owned record presentation
```

Tasks must not import Scheduling, Documents, PetServices, Mortgage, FlowRoutes, or other higher-level module models merely to understand their records.

Each linked module owns the label, name, useful details, and destination URL for its own records through Tasks-owned extension seams.

## Ownership and dependencies

Tasks owns:

- live task records;
- task templates;
- task template preset sync;
- task creation from templates;
- ad hoc manual Task creation;
- TaskLink records and generic link roles;
- task lifecycle state;
- task completion, cancellation, reopen, archive, and restore behavior;
- task assignment and responsible-party semantics;
- task due dates and priority/default metadata;
- Task index/show surfaces;
- linked-record presentation seams and fallback behavior;
- task events such as `task.completed` when automation-worthy.

Tasks depends on:

- Core, for generic Contact identity, Contact-linked visibility, and Core-owned extension seams.

Tasks must remain fully useful with only Core enabled.

With only Core and Tasks enabled, the user must still be able to:

```text
create an ad hoc no-template Task
create a template-backed manual Task
create an unlinked Task
create a Contact-linked Task
view the global Task index
view an individual Task
complete, cancel, reopen, archive, and restore Tasks
use TaskTemplate defaults
emit valid neutral Task lifecycle events
```

Tasks does not own:

- Messaging scheduled messages;
- InternalNotifications team members or notification preferences;
- FlowRoutes route progress, plans, points, capabilities, or correlation state;
- Campaign enrollment;
- Workflow status/profile state;
- vertical-specific domain records such as pets, mortgage files, music fans, documents, appointments, or commerce orders.

### Optional integrations

InternalNotifications, Messaging, FlowRoutes, Scheduling, Documents, Mortgage, PetServices, and other modules are optional integrations or consumers. They are not structural prerequisites for Tasks.

InternalNotifications may contribute TeamMember assignment/notification behavior through public seams when enabled.

Messaging may be used indirectly for delivery through InternalNotifications-owned behavior. Tasks core lifecycle and UI must not require Messaging.

FlowRoutes may create template-backed Tasks through Tasks public actions and consume neutral Task automation events. Tasks must not import FlowRoutes internals.

Other modules may request Task creation and TaskLink creation through Tasks public actions/services. They should not directly create `Task` or `TaskLink` rows when a public Tasks seam exists.

Good:

```text
CreateTaskAction
CreateTaskFromTemplateAction
CompleteTaskAction
Tasks-owned link action/service
Tasks-owned linked-record presenter registry
```

Bad:

```text
Another module -> Task::create(...)
Another module -> TaskLink::create(...)
Tasks -> Appointment model
Tasks -> DocumentRequest model
Tasks -> FlowRoute progress model
```

unless there is an intentional, documented exception.

## Task templates and defaults

Task templates are DB-owned reusable task definitions.

A TaskTemplate is optional for manual Tasks and required for automation-created Tasks.

Task template-backed live task creation resolves defaults in this order:

```text
1. Explicit caller value.
2. First-class TaskTemplate field.
3. TaskTemplate.defaults.
4. System fallback.
```

`TaskTemplate.defaults` is real generic fallback data. It is not a dumping ground for values that already have first-class TaskTemplate columns.

### TaskTemplate link defaults

Task relationship defaults should use one explicit TaskTemplate-owned contract rather than the old single `related_subject` shape.

Durable target:

```text
TaskTemplate.link_defaults
    zero or more generic link-default definitions
```

Canonical authoring shape:

```php
'link_defaults' => [
    [
        'role' => 'subject',
        'source' => 'current_contact',
    ],
],
```

Initial Tasks-owned source vocabulary:

```text
current_contact
    The Core Contact supplied in the Task creation context.

current_subject
    The generic model/record supplied as the current domain subject by the caller.
```

Do not add source keys such as `current_pet`, `current_appointment`, `current_loan`, or other module-specific aliases to Tasks. A contributing module supplies its record through the generic `current_subject` creation context or passes explicit live Task links through a Tasks-owned public seam.

Template link defaults are creation contracts, not hidden metadata suggestions. If a selected template requires a link default whose source cannot be resolved from the supplied creation context, the creation path should fail clearly rather than silently creating a less-contextual Task.

Live Task links resolve separately from scalar/default field precedence:

```text
1. Resolve explicit caller-provided live links, if any.
2. Resolve TaskTemplate.link_defaults against the supplied creation context.
3. Merge and de-duplicate links by linkable identity + role.
4. If neither source provides links, create an unlinked Task.
```

`TaskTemplate.defaults` should not become a second relationship-definition system. Do not also store `defaults.links`, arbitrary relationship IDs in `meta`, or a parallel `related_subject` contract.

Task template preset sync should create DB-owned default task templates only. It should not create live tasks.

Normal sync should preserve customized templates unless an explicit force behavior is chosen.

Task preset ownership is contributor-scoped:

```text
TaskTemplate.key
    durable definition identity

meta.preset.contributor
    durable preset source owner

meta.preset.task_template_key
    explicit preset definition identity

meta.preset.source_version
    source revision/provenance

preset group
    composition-only
    never persisted as TaskTemplate ownership
```

Stale cleanup is scoped to selected contributors. A selected contributor may retire its final definition. Unselected contributors are not touched. Customized rows remain preserved unless force is explicitly requested.

Task templates may be contributed by universal or vertical modules, but Tasks must remain generic at the storage/runtime layer.

Examples:

```text
PetServices contributes:
    Call owner after behavior evaluation

Documents contributes:
    Review requested document upload

Scheduling contributes:
    Confirm appointment setup
```

The contributing module owns domain meaning. Tasks stores reusable generic TaskTemplate definitions and creates live Tasks through public actions.

## Tasks setup validation ownership

Tasks contributes Tasks-owned checks through `TasksSetupValidationContributor` to the shared app-level setup validation manager.

Task preset/template validation uses resolved selected Task definitions and DB-owned TaskTemplate/runtime rules as executable truth.

Shared preset-composition validation owns package/group/definition structure, including missing selected groups and duplicate contributed group/definition keys.

Tasks owns semantic validation of:

```text
Task template keys and stable identity
required template fields
due/default shapes
assignment strategy shapes
responsibility shapes
TaskLink role vocabulary
TaskTemplate.link_defaults shape and source vocabulary
link-default resolvability through supplied creation context
supported linked-model presentation/provider declarations
vertical-contributed TaskTemplate genericity
DB/runtime TaskTemplate availability
canonical internal terminology
```

FlowRoutes owns validation of its own `create_task` references.

A missing TaskTemplate referenced by an automation-created Task definition or selected FlowRoute is a hard error.

An unused but valid TaskTemplate may be a warning or omitted from findings depending on whether unused-definition visibility is useful to the operator.

Tasks validation should return shared structured findings and should not persist validation history by default.

Do not make one Tasks validator import every linked module's private models/config internals. Validate cross-module link support through Tasks-owned registries/config contracts/public seams.

## Standalone and multi-link Tasks phase

The current implementation already proves some standalone behavior:

```text
tasks.related is nullable
CreateTaskAction can create unrelated Tasks
TaskFactory supports unrelated Tasks
dashboard Task rendering can show unrelated Tasks
task.completed can emit with nullable contact_id
```

The current implementation does not yet satisfy the full durable target because it still includes:

```text
single related_type / related_id relationship
TaskTemplate.related_subject as the old single-subject default contract
Contact-only allowed related types
Contact-only related-subject presenter registration
no dedicated Task index/show routes
Tasks-owned direct InternalNotifications/TeamMember coupling in core paths
Tasks-owned FlowRoutes-specific provenance columns/model imports
```

The implementation phase should close those gaps without rebuilding already-generic Task lifecycle behavior unnecessarily.

Minimum implementation target:

```text
[ ] Replace single related morph with TaskLink zero-to-many relationships.
[ ] Replace TaskTemplate.related_subject with TaskTemplate.link_defaults.
[ ] Support initial generic roles: subject, context, result.
[ ] Support initial generic link-default sources: current_contact and current_subject.
[ ] Preserve unlinked Tasks.
[ ] Preserve Contact-linked Tasks through TaskLinks.
[ ] Prove one existing non-Contact linked model cleanly.
[ ] Add Tasks-owned linked-record presentation registry/resolver behavior.
[ ] Add dedicated Task index and Task show surfaces.
[ ] Keep Task UI useful when links are absent.
[ ] Make core Task creation/lifecycle/UI work with only Core enabled.
[ ] Move optional TeamMember/InternalNotifications behavior behind optional seams.
[ ] Remove Tasks structural dependency on FlowRoutes internals.
[ ] Preserve automation-created Task correlation through FlowRoutes-owned state/public event seams.
[ ] Protect template/no-template and manual/automation invariants with tests.
```

Do not add speculative module-specific roles, a giant universal subject registry, or a generic graph system.

## Dedicated Task index and show surfaces

Dedicated Task index and show surfaces are part of this phase.

The first implementation may be information-dense and function-first. Final UI polish can happen later.

### Task index

The Task index should show all relevant live Tasks, including:

```text
unlinked Tasks
Contact-linked Tasks
non-Contact-linked Tasks
single-link Tasks
multi-link Tasks
template-backed manual Tasks
no-template manual Tasks
automation-created template-backed Tasks
```

The UI should not present these as separate Task categories. They are one Task record described by independent dimensions.

Initial index information may include:

```text
title
status
due date
priority
assignment
responsible party
template identity when present
manual/automation origin
compact linked-record context grouped by role
```

Filters and visual refinement can be added later when real usage proves the need.

### Task show

A Task show page should work when the Task has zero, one, or many links.

The page should make the following clear without requiring training:

```text
WHY am I seeing this Task?
WHAT needs to be done?
HOW do I finish or advance it quickly?
```

Initial Task show information may include:

```text
title and description
status and lifecycle timestamps
due date and priority
assigned owner when available
responsible party/responsible model when available
template identity/default provenance when present
manual vs automation origin
subject links
context links
result links
actions such as complete, reopen, cancel, archive, restore
```

Raw morph types, IDs, registry keys, or module internals should not be primary UI labels.

## Assignment and responsibility

Task assignment and responsible-party semantics belong to Tasks.

A Task may be:

```text
unassigned
assigned when a supported assignee provider is available
assigned later by an operator
owned/grouped by a module or preset source
```

Tasks should support optional assignment without requiring InternalNotifications.

InternalNotifications may contribute TeamMember assignment notification behavior through public seams, but Tasks must remain usable with Core only.

Current responsible-party concepts remain:

```text
internal
contact
third_party
unknown
```

Meaning:

```text
assigned_to
    who internally owns/tracks the task when a supported assignee model exists

responsible_party
    who or what must actually do the manual action

responsible
    optional concrete responsible model when one exists
```

Responsibility does not imply notification delivery, Messaging consent, login access, or direct task completion ability.

Those behaviors belong to separate capabilities.

## FlowRoutes integration

FlowRoutes may create template-backed Tasks through Tasks public services/actions.

Durable direction:

```text
FlowRoute create_task point
    -> resolves TaskTemplate
    -> calls Tasks public action
    -> Tasks creates live Task and TaskLinks
    -> Tasks owns lifecycle
    -> Tasks emits neutral automation events
    -> FlowRoutes owns route correlation/resume state
```

FlowRoutes should not directly create `Task` or `TaskLink` records.

Tasks should not know FlowRoutes internals.

Automation-created Tasks must be template-backed. FlowRoutes should not create arbitrary no-template Tasks.

Task completion should emit a neutral automation event when automation-worthy:

```text
task.completed
```

FlowRoutes may listen to generic `AutomationEventRecorded` and resume matching event-wait/progress/plan items internally.

### FlowRoutes correlation and provenance

Tasks must not store FlowRoutes-specific foreign keys or import FlowRoutes-owned models merely to preserve route provenance.

FlowRoutes owns:

```text
route progress
route plans
plan items
progress/execution items
created artifact references
explicit correlation state
resume matching
```

When FlowRoutes creates a Task, it should preserve the created Task identity in FlowRoutes-owned state and use neutral Task event identity plus explicit correlation when later resume matching is required.

Conceptual direction:

```text
FlowRoutes progress item
    created_subject_type = Task morph
    created_subject_id = Task id
    correlation = FlowRoutes-owned data when needed

Task
    owns task state
    owns TaskTemplate identity
    owns TaskLinks
    does not own flow_route_* foreign keys
```

A Task may still preserve durable `task_template_id` plus `task_template_key` identity because TaskTemplate is Tasks-owned.

Broad Contact-only task completion matching remains unsafe. FlowRoutes should correlate against its own created-artifact/correlation state and the neutral event payload.

The exact implementation shape should preserve the already-proven event-wait behavior without retaining a structural Tasks -> FlowRoutes dependency.

## Task template UI

A client/operator task template UI is only needed if clients/operators need to manage templates themselves.

Before building a polished template builder, prove the schema, public action/service shape, TaskLink model, and dedicated Task workspace.

The immediate phase needs Task index/show surfaces, not necessarily a polished TaskTemplate builder.

### Task action interaction direction

Task actions should follow the CRM preserve-context pattern where it improves operator flow.

Dashboard task rows, contact task panels, the Task index, and the Task show page may support actions such as:

- Print
- View
- Broadcast
- Complete
- Reopen
- Cancel
- Archive
- Restore
- Reassign

For small row/card actions, the preferred long-term behavior is an inline update that preserves the operator's current dashboard, filter, panel, or modal context instead of forcing a full reload.

Examples:

- completing a task should update the row/card state and counts in place when practical;
- reopening a task should restore it without losing the current task list context;
- broadcasting selected tasks should preserve the current selection or clearly report what happened;
- viewing a task should use the dedicated Task show surface, with linked records available as contextual destinations.

Do not add persisted saved views, selected-task batches, or per-user Task workspace state until real UI usage proves those concepts are necessary.

## Automation opportunity producer direction

Tasks is the first implemented producer for shared Automation Opportunities infrastructure.

Current implemented Task-related action keys:

```text
task.created_manually
    evaluated manual behavior

task.created_after_manual_status_change
    evaluated compound behavior

task.completed_manually
    evidence only

contact.status_changed_after_manual_task_completion
    evaluated by Workflow using recent Task-completion evidence

task.created_after_automation_event
    evaluated compound behavior after selected neutral event evidence
```

### Manual Task creation target

The durable Task-created automation suggestion should focus on repeated similar manual no-template Tasks.

Conceptual rule:

```text
manual + no template + semantically similar repeated work
    -> observe
    -> qualify through shared Automation Opportunities rules
    -> suggest reusable automation/template behavior when truthful
```

Do not record automation-created Tasks as manual behavior occurrences.

Do not put manual behavior recording inside generic `CreateTaskAction` merely because all Task creation passes through it. Record from an unambiguous manual application/UI seam.

The current implementation is more Contact-oriented and should be generalized carefully during the code phase without breaking existing compound behaviors.

### Manual status change -> manual Task

The current compound key is:

```text
task.created_after_manual_status_change
```

It currently requires:

```text
manual Contact status transition provenance
same actor for status change and Task creation
same Contact
Task created after the transition
Task created within 10 minutes
real from/to status change
```

Fingerprint:

```text
from_status_key
to_status_key
task_template_key
normalized_title when no template exists
```

This Contact-specific compound behavior may continue where useful. It should use TaskLinks/public linked-record context rather than requiring a single `related` morph.

### Manual Task completion evidence

The current evidence-only key is:

```text
task.completed_manually
```

It is recorded only for explicit CRM/manual completion provenance and does not create an opportunity by itself.

This evidence supports:

```text
manual Task completion
    -> manual Contact status change within 10 minutes
    -> contact.status_changed_after_manual_task_completion
```

### Selected automation event -> manual Task

Selected neutral automation events are retained by shared opportunity infrastructure as evidence-only `automation_event.recorded` rows.

When a manual Task is created within the supported correlation window after relevant evidence, Tasks may record a compound occurrence when correlation is truthful and unambiguous.

Current selected event evidence keys:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

The evidence allowlist may change as usefulness becomes clearer. It is not a promise that every retained event will produce a suggestion.

Prefer silence or a stricter correlation rule over a wrong suggestion.

### Qualification and validation

The shared generic evaluator currently uses:

```text
minimum occurrences = 3
minimum distinct subjects = 3
observation window = 30 days
```

Shared Automation Opportunities infrastructure owns deterministic normalization/hashing, occurrence persistence, aggregation, lifecycle, and generic qualification.

Tasks owns only Task-specific semantics and explicit producer behavior.

## Notifications and digests

Tasks owns the business trigger and cadence for task assignment notifications and task digests.

Reusable Messaging templates must not own task digest schedules, task-assignment trigger timing, or Task-specific eligibility rules.

InternalNotifications and Messaging are optional capabilities.

Core Task creation, lifecycle, index/show UI, TaskTemplate behavior, and TaskLink behavior must not require them.

When optional notification capabilities are unavailable:

```text
Task creation still works.
Task assignment data may remain null or use another supported provider.
Task completion still works.
Task index/show still work.
Assignment notification and digest delivery no-op or remain unavailable.
```

Task assignment notifications should resolve linked-record presentation generically. Notification copy and CTA behavior must remain valid when a Task has no links.

Digests must not assume a Contact or any specific linked module.

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

A valid Task event may be contactless.

Target event shape should preserve:

```text
event_key
contact_id nullable
subject = Task
Task identity
TaskTemplate identity when present
manual/automation origin
TaskLink context in a neutral form when useful
occurred_at
payload
meta
```

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
linked subject fields when provided by a registered linked-record context
linked context fields when provided by a registered linked-record context
```

Do not expose arbitrary model columns, raw morph data, `meta`, or private module fields as implicit Task template token namespaces.
