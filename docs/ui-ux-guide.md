



# Engage Core UI/UX Guide

This guide turns Engage Core's product principles into practical interface rules.

Use this document when designing, reviewing, or refactoring CRM/admin/client-facing screens. Use `product-principles.md` for the product posture, `module-boundaries.md` for ownership and dependency rules, `config-authoring-guide.md` for config/template rules, and `TODO.md` for disposable backlog.

## Core UX standard

Engage Core client-facing UI should let a client do the thing they came to do in roughly 5-10 minutes, with almost no tutorial material.

The client should not need to understand module internals, schema names, event keys, route bindings, dispatch keys, provider vocabulary, or config architecture to complete normal work.

The ideal reaction is:

```text
I have to do X.
I know where to click.
I understand what will happen.
I can finish it in a few minutes.
I did not have to learn a new software category.
```


## No “what did I get myself into?” screens

A client should never open Engage Core and feel like they accidentally became a software administrator.

The first impression should be:

```text
I know what needs my attention.
I can see what is already being handled.
I know the next safe action.
```

Not:

```text
What are workflows, triggers, smart lists, funnels, tags, campaigns, AI agents, execution logs, and settings?
Which one am I supposed to touch?
What did I get myself into?
```

Every client-facing screen should answer, in this order:

```text
Where am I?
What matters right now?
What should I do next?
What is already being handled automatically?
Where can I review details if I need them?
```

Powerful capabilities should default to calm summaries, guided actions, and next-step prompts. The machinery underneath belongs in operator/developer setup, advanced details, or gated debug views.

## Avoid platform-cockpit UI

Do not organize client-facing screens as a cockpit of every feature the platform can technically perform.

A platform-cockpit screen usually exposes too many concepts at once:

```text
workflow builders;
triggers;
smart lists;
funnels;
pipelines;
execution logs;
tag rules;
AI tools;
provider settings;
message queues;
raw automation steps;
admin-only configuration;
empty dashboard widgets;
advanced reporting panels.
```

This makes the client learn the software's internal universe before they can do normal business work.

Engage Core should instead organize screens around business questions:

```text
Who needs attention today?
Which lead should I work next?
What happened with this lead?
What follow-up is already running?
What message is about to be sent?
What decision do I need to make?
```

When a feature is powerful, expose it through presets, summaries, guided choices, consequence previews, and simple runtime actions. Do not make clients assemble the system from primitives unless they explicitly need operator/admin setup access.

## Primary rule

Client-facing UI should describe the business action and expected outcome, not the underlying implementation mechanism.

Good UI asks:

```text
What are you trying to do?
Who is this for?
When should it happen?
What should happen next?
Are you ready to confirm?
```

Bad UI asks the client to reason about:

```text
Which module owns this?
Which trigger binding should be selected?
Which automation event key fired?
Which config path defines this?
Which message type or dispatch key applies?
Which DB-owned runtime definition is active?
```

## Product surface types

### Client-facing runtime surfaces

Client-facing runtime surfaces are for normal business work.

Examples:

```text
Draft a Broadcast.
Select recipients.
Update a lead status.
Create or complete a task.
Send a document request.
Review a submitted form.
Schedule an appointment.
Start or stop a Campaign.
Review webinar registrations and outcomes.
```

These screens should use plain business language, safe defaults, familiar patterns, and minimal required fields.

### Operator/admin setup surfaces

Operator/admin setup surfaces may expose more system structure, but they still should not be careless, raw, or intimidating.

Examples:

```text
Select which follow-up runs for a status.
Choose which message template preset is active.
Assign a webinar reminder schedule profile.
Map imported statuses to Engage Core statuses.
Configure provider/channel visibility.
Review skip/failure reasons.
```

Operator/admin UI may show diagnostic detail, but should still lead with the business meaning.

### Developer/debug surfaces

Developer/debug surfaces may expose raw keys, payloads, event names, and forced sends when explicitly gated to local/staging/dev contexts.

Do not reuse dev-testing UI patterns for client/operator-facing production UI. A dev modal optimized for smoke testing is not automatically a product pattern.

## Language standards

### Use configured business nouns

Client-facing nouns should come from client/config terminology where available.

This is a presentation rule only. Internal/runtime identifiers remain universal and `contact`-based. Do not let a client-facing noun such as Lead, Customer, Fan, Borrower, or Owner leak into generic config keys, preset identifiers, task-template keys, event keys, triggers, FlowRoute identifiers, payload/context fields, or universal service/action names.

Examples:

```text
Lead instead of Contact, when the client-facing CRM noun is Lead.
Customer instead of Contact, when the vertical/client expects Customer.
Status instead of Workflow Status.
Follow-up instead of FlowRoute, when describing the outcome.
```

Use `Contact` only where the user genuinely needs the generic platform concept.

### Prefer action and outcome language

Use:

```text
Send a Broadcast.
Schedule this message.
Move lead to Prospect.
Create a follow-up task.
Start attended-webinar follow-up.
Stop missed-webinar follow-up.
When someone attends a webinar...
What should happen next?
```

Avoid in client-facing UI:

```text
Route Trigger Bindings
Workflow status routes
Automation event routes
FlowRouteExternalEvent
webinar.attended
message_type
recipient_filter
dispatch_key
payload_class
preset sync
owner morph
```

Internal names may appear in developer/debug views or compact diagnostic areas, but not as primary labels.

### Translate implementation terms before display

| Internal concept | Client/operator-facing language |
| --- | --- |
| ContactStatus | Status |
| Contact | Configured noun, usually Lead |
| FlowRoute | Follow-up, automation, route, or workflow depending on context |
| FlowRouteTriggerBinding | Selected follow-up |
| Automation event | Activity, event, or “When this happens” |
| `webinar.attended` | Someone attends a webinar |
| `webinar.missed` | Someone misses a webinar |
| Campaign enrollment | Start Campaign |
| Message template preset | Message template |
| MessageTemplateCatalogEntry | Template catalog item |
| MessageTemplatePresetAssignment | Selected template |
| ScheduledMessage | Scheduled message, reminder, or follow-up |
| BroadcastRecipient | Recipient |
| `recipient_filter` | Recipients |
| Skip/failure reason | Why this did not send |

### Keep technical identifiers secondary

When a technical key is useful for operators, show it as secondary metadata, not the primary name.

Good:

```text
Someone attends a webinar
webinar.attended
```

Bad:

```text
webinar.attended
Someone attends a webinar
```

Hide version labels such as `v1` unless the user is in a debug/operator detail mode where versions are meaningful.

## Information architecture

### One primary decision per screen area

Dense admin screens should be broken into focused decisions.

Good:

```text
Choose a status.
Show the selected follow-up.
Preview what it does.
Change it if needed.
Save.
```

Bad:

```text
Show every status, every selected route, every empty state, every event, every available route, and every save button at once.
```

### Prefer progressive disclosure

Start with the concept the user already understands, then reveal deeper detail only when needed.

Examples:

```text
Select status -> show follow-up for that status.
Select module tab -> show activities for that module.
Select message template -> show preview and delivery details.
Click advanced/details -> show keys, conditions, and diagnostics.
```

### Reuse familiar patterns

If the contact show page already uses a status index or status selector pattern, reuse it when selecting status-based behavior elsewhere.

Do not introduce a new layout for the same mental model unless the new screen asks a meaningfully different question.

### Preserve context for inline CRM actions

CRM workflows should avoid unnecessary full-page reloads when an operator is acting inside a focused context such as a dashboard panel, task list, modal, contact panel, or setup checklist.

When an action is small, localized, and reversible or easily confirmed, prefer an inline/AJAX-style interaction that preserves the operator’s current position, filters, selected record, modal state, and visible feedback.

Good candidates include:

- completing or reopening a task;
- cancelling, archiving, restoring, or reassigning a task;
- sending a focused task broadcast;
- loading contextual action options inside a modal;
- marking a setup/readiness item reviewed;
- refreshing a panel after a successful action.

The interaction should provide clear feedback in place, such as a row status update, subtle success message, refreshed count, or inline activity log. It should not make the operator hunt back to where they were.

Full-page submits are still acceptable when the action naturally changes the whole page context, requires a multi-step confirmation flow, creates a new primary record, or is not worth adding client-side complexity yet.

This is a UX pattern first, not a schema requirement. Only add persisted state when the workflow proves that user-specific selections, filters, acknowledgements, dismissals, or saved views need to survive beyond the current page session.

## Visual wayfinding

Module colors are quiet orientation cues, not decoration.

Use muted tints, borders, rings, badges, rails, and background washes to help users recognize which module contributed a card, panel, or section.

Do not use bright module colors as large attention-grabbing blocks. Reserve high-saturation amber/red treatments for true urgency, failed/blocked states, overdue work, or business-critical warnings.

Urgency styling should visually win over module wayfinding.

Examples:

```text
Tasks panel: soft emerald border/background.
Inbound messages panel: soft blue border/background.
Webinars panel: soft stone border/background.
Overdue task: amber urgency badge regardless of Tasks module tone.
```

## Core screen patterns

### Dashboard / today screen pattern

A client dashboard should be decisive, not merely informative.

It should answer:

```text
What needs my attention today?
What is overdue or blocked?
Which lead/task/message should I work next?
What is already being handled automatically?
```

Preferred shape:

```text
Today
3 leads need attention.
1 task is overdue.
2 webinar registrants need follow-up.
No urgent message issues.

Primary action: Work next lead
```

Current implementation direction:

```text
Dashboard layout is config-driven, not DB-owned layout state.
Dashboard panels are contributed by enabled modules through panel providers.
Slots such as immediate work and context are selected by dashboard config and preset overrides.
Actionable empty states may remain visible when they reassure the user.
Passive context panels should hide when empty.
Right-now cards should jump to the panel they summarize.
```

Avoid dashboards filled with empty charts, duplicated widgets, broad module panels, generic counts, or filters that do not lead to a clear next action.

### “When this happens, what should happen next?” pattern

Use for automation, routing, follow-ups, event reactions, and status-triggered behavior.

For current Routes UI, keep the product split explicit:

```text
Manage Routes
    What does this Route do?

Assignments
    When does this automation run?
```

Consequence previews belong near actions that may start automation, such as a manual status change or assignment change.

Do not force every automation surface into the same tabbed builder pattern. Use the current Routes information architecture for Route management, and use focused selectors or confirmation previews where the user's actual task is assignment or consequence review.

### Status selector pattern

Use when behavior depends on the lead/customer status.

The UI should:

```text
show a single status selector;
use configured status labels;
show the selected status description when useful;
show the currently selected follow-up;
show what will happen if the status is applied or selected;
avoid displaying a long list of every status unless the user's task is status management itself.
```

### Module activity tabs

Use when behavior depends on an activity from another module.

Example tabs:

```text
Webinars
Broadcasts
Campaigns
Forms
Documents
Tasks
Scheduling
Commerce
```

Activity labels should be human-readable:

```text
Someone registers for a webinar.
Someone attends a webinar.
Someone misses a webinar.
Someone submits a form.
Someone uploads a document.
A task is completed.
An order is created.
```

The raw event key may be shown as secondary diagnostic text only when useful.

### Consequence preview card

Whenever a client/operator action may trigger automation, show the consequences before confirmation.

A consequence preview should summarize:

```text
messages that will be sent;
SMS/email channel requirements;
Campaigns that will start or stop;
status changes that will occur;
tasks that will be created;
team notifications that will be sent;
conditions that may prevent the action;
why something may be skipped.
```

Good:

```text
If you move this lead to Prospect:
- Stop attended-webinar nurture.
- Create a follow-up task for the sales team.
- No message will be sent immediately.
```

Bad:

```text
Selected route: prospect_cancel_attended_nurture_create_task_v1
```

### Confirmation guardrail pattern

Use a confirmation step when an apparently simple action triggers meaningful automation.

Examples:

```text
Changing a lead status that starts a selected follow-up.
Scheduling a Broadcast to hundreds or thousands of recipients.
Sending imported-contact permission invitations.
Cancelling a Broadcast with pending scheduled messages.
Starting or stopping a Campaign enrollment.
```

The confirmation should say what will happen, not just ask “Are you sure?”

### Empty states

Empty states should explain the business condition and the next action.

Good:

```text
No follow-up is available for this status yet.
Ask your operator to create a follow-up route for this status.
```

Bad:

```text
0 available routes
```

### Save actions

Avoid repeated row-level Save buttons when the screen is not naturally a row editor.

Prefer:

```text
focused edit panel;
one primary Save action;
clear unsaved-change state;
inline success/error feedback;
AJAX/preserve-context behavior where reloads would be frustrating.
```

Row-level save is acceptable only when each row is an independent, compact edit and the user can clearly understand the scope of each save.

## Automation and route UI

### Current Routes information architecture

Client/operator-facing FlowRoutes UI uses:

```text
Routes
    Manage Routes
    Assignments
```

The distinction is:

```text
Manage Routes
    What does this Route do?

Assignments
    When does this automation run?
```

Do not repeat assignment detail such as `Runs when` inside normal Route-detail disclosure on Manage Routes. Trigger selection and runtime assignment belong to Assignments.

### Manage Routes pattern

Manage Routes should feel like reviewing understandable paths, not configuring an automation engine.

Current preferred structure:

```text
Route name
Assigned / Not assigned state
business-language trigger summary
Point count
Show route flow
Edit Route
Review Assignment / Assign Route
```

When expanded, Route flow should show Points in order using module-tone wayfinding for cross-module actions.

Do not expose raw handler keys, event keys, dispatch keys, capability bindings, plan items, or progress internals as primary content.

One-step automatic behavior may be grouped separately from multi-step Routes. Do not force a single automatic action to look like a large Route when that creates unnecessary visual weight.

Search and assignment filters may stay hidden until the list is large enough to justify them. The current implementation shows them when five or more multi-step Routes exist.

### Route editor pattern

Editing an existing Route should preserve context.

Current implemented pattern:

```text
Manage Routes index
→ Edit Route
→ large Route editor modal
```

Do not send normal editing into a separate full page when a focused modal can preserve the user's place.

The editor should show:

```text
Route flow
ordered Point cards
Edit
Remove
move up/down fallback controls
drag-and-drop when the Point is movable
Save order only after drag order changes
Add a Point
```

Point editing should use a focused modal, not expand a dense form inline inside every Point card.

`Remove` should remain directly visible. Do not hide removal behind Edit.

### Linear Route product rule

Normal Routes are deliberately linear.

Do not expose:

```text
arbitrary branching
true/false path canvases
joins
nested branch trees
connectors
generic node editors
arbitrary jump-back loops
```

Internal runtime support for advanced Point types does not require exposing them in normal client/operator authoring.

The product purpose is:

> Take repetitive coordination work off someone's plate.

A useful test is:

```text
Would a human assistant normally have to remember to do this?
    yes -> likely Route material

Is the behavior inherently part of one module's domain?
    yes -> likely module-owned automation instead
```

### Point labels and Campaign terminology

Point previews should summarize outcomes in plain business language.

Examples:

```text
Wait 3 days
Create task: Call lead
Send message: Webinar replay follow-up
Change status to Prospect
Start Campaign: Attended Webinar Nurture
Stop Campaign: Missed Webinar Nurture
```

Use `Campaign` consistently for Campaign-owned journeys.

Avoid:

```text
follow-up sequence
enroll_campaign
event_wait
branch_evaluate
campaign enrollment
```

as primary client-facing labels.

Avoid redundant title repetition such as:

```text
Task
Create task
Create task: Call lead
```

Prefer one clear type label plus one useful summary.

### Point placement guardrails

The UI should reflect the same placement policy enforced by the backend.

Current rules:

```text
Wait
    cannot be the final Point

Change Status
    must be the final Point

Create Task
Send Message
Start Campaign
Stop Campaign
    may occur anywhere when otherwise available
```

Do not show fake affordances.

If Change Status is terminal and cannot move, it should not show a drag handle.

If removing the Point after a Wait would leave Wait terminal:

```text
disable Remove
gray it out
provide a hover/focus explanation
```

Do not let the user submit first and discover the rule only through a page-level error.

For drag-and-drop, avoid page-level warning banners that change layout and cause a bounce or glitch. Show invalid placement locally at the attempted terminal position, for example by darkening the final slot/card area and displaying:

```text
Wait cannot be the last Point.
```

The backend remains authoritative. Frontend guardrails should mirror domain policy, not replace it.

### Current authorable Point types

The current normal Route editor supports:

```text
Wait
Change contact status
Create task
Send message
Start Campaign
Stop Campaign
```

`Stop Campaign` should be contextually hidden unless the current Route already contains a `Start Campaign` Point.

`Send message` should be hidden when no Messaging template is explicitly eligible for direct Route use.

Do not expose advanced internal Point types merely because runtime handlers exist.

### Direct Route message-template eligibility

The Route editor must not list every active Messaging template.

Direct Route use is explicit opt-in through:

```text
MessageTemplatePreset.meta.route_authoring.eligible = true

or

active MessageTemplateCatalogEntry.meta.route_authoring.eligible = true
```

Internal-purpose templates are never eligible for direct Route authoring.

This prevents lifecycle-owned templates such as webinar confirmations, webinar reminders, Campaign-step messages, permission invitations, and internal notifications from appearing in a generic Route message picker.

### Assignments pattern

Assignments answers when Routes run.

Status and activity triggers should be described in business language.

Examples:

```text
Status: Attempting Contact
When someone attends a webinar
When someone misses a webinar
```

Contact-status triggers normally select one Route per context.

Automation-event triggers may select multiple independent Routes for the same activity.

Do not imply that `FlowRoute.is_active` means the Route is currently selected to run. Availability and assignment are different.

### FlowRoute names

FlowRoute DB names may stay operator-oriented, but client-facing cards should prefer understandable names and summaries.

Good:

```text
Attempting Contact Follow-Up
Move contact to Attended Webinar
Start Attended Webinar Campaign
```

Bad:

```text
Webinar Attended Status Transition - v1
webinar_attended_status_transition
```

### Remaining Route UX work

The current editor is not the end of Route product work.

Still deferred:

```text
new Route creation
Route duplication
activate/deactivate
trigger changes
clone Point from another Route
task assignment/default authoring
business-day/business-hour waits
simple future Point eligibility / Route continuation rules
manual status-change consequence confirmation
contextual Automation Opportunity suggestions
```

Do not expand into arbitrary branching while solving those gaps.

## Contextual automation discovery

Engage Core should help users discover automation from work they are already doing instead of requiring them to understand Route Management first.

Route Management is the control center:

```text
What happens automatically, and why?
```

Contextual automation suggestions are the discovery layer:

```text
You've repeated a meaningful pattern that may be worth handling automatically next time.
```

Only show a suggestion when durable occurrence evidence, generic qualification, and current suggestion-time validity support it.

Evidence alone is not a prompt.

Current examples of evidence-only state:

```text
task.completed_manually
automation_event.recorded
```

Current evaluated compound patterns include:

```text
manual status change -> manual Task
manual Task completion -> manual status change
supported automation event -> manual Task
```

Good:

```text
You've created this Task for 3 Contacts in Attempting Contact.
Add it to their Route so it happens automatically next time?
```

```text
You've moved 3 Contacts to Approved after completing "Review application."
Make that status change happen automatically when the Task is completed?
```

```text
You've created "Follow up with contact" for 3 Contacts after they confirmed their communication preferences.
Add that Task to the Route?
```

Avoid:

```text
Would you like to automate this?
```

after the first occurrence or after every manual action.

Suggestions should:

```text
stay silent for one-off behavior
explain the exact repeated pattern
use plain business language
avoid confidence scores or AI language
avoid suggesting automation that already exists
avoid suggesting when attribution is ambiguous
provide one clear next action
allow dismissal or snoozing
never create or change automation without explicit approval
```

When a repeated workflow needs missing information, suggest an assisted next step instead of pretending the system can decide.

Example:

```text
You usually schedule an appointment after intake is completed.
Want to automatically send the customer a booking invitation with available times?
```

Do not turn Engage Core into Clippy. Contextual assistance should be sparse, evidence-based, and useful.

Detailed persistence and producer rules live in `docs/automation-opportunities.md`.

## Messaging, Broadcasts, and Campaigns UI

### Broadcasts

Broadcast UI should remain focused on one-time sends.

The main steps should be:

```text
Choose channel.
Write message.
Choose recipients.
Review who will receive it.
Schedule or send.
```

Broadcast UI should not force the user to understand Messaging scheduled-message internals.

Recipient selection should use recipient language, not audience language.

When recipients are skipped, the UI should explain why in business terms:

```text
No email consent.
No usable phone number.
SMS is not enabled for Broadcasts.
Previously received the related Broadcast.
```

### Campaigns

Campaign UI should use `Campaign` as the primary product term and describe the journey in business language rather than raw scheduled-message machinery.

Good client/operator labels:

```text
Campaign
Message steps
Step
Available channels
When it sends
Start Campaign
Pause Campaign
Stop Campaign
```

Avoid as primary labels:

```text
campaign_step_due
message_type
payload
meta.message
CampaignEnrollment
```

Within Routes, use:

```text
Start Campaign
Stop Campaign
```

Do not relabel Campaigns as follow-up sequences there.

### Messaging templates

Template UI should show what the message says and where it is used.

The Messaging template screen is a copy-review and copy-editing surface. It should not be the primary place where an operator chooses which template a Campaign step, Webinar reminder, or Route Point uses.

Preferred template browsing path:

```text
Channel
Purpose
Area/module
Template group
Message/step within that group
```

Examples:

```text
Email -> Marketing -> Campaigns -> Webinar Attended Nurture -> Step 3 Email
SMS -> Transactional -> Webinars -> Webinar Reminders -> 30-Minute Reminder SMS
```

Recommended preview:

```text
Template title
Channel
Purpose in plain language
Area/module
Used by
Subject/message preview
Tokens used
Last edited/customized state
```

The "Used by" panel should be read-only from the template editor. It may link to the owning setup screen when the operator needs to change the active selected template.

Good:

```text
Used by
Campaigns -> Webinar Attended Nurture -> Step 3
View campaign
```

Bad:

```text
Use this template for every compatible workflow from the template editor.
```

Template catalog/group labels should be readable business labels, not raw config paths or generated key strings.

Token validation errors should be actionable:

```text
This template uses {application_url}, but this workflow does not provide that link.
Choose a documented token or update the workflow to supply it.
```

## Tasks UI

Task UI should keep the mental model simple:

```text
Assigned to = internal person tracking the task.
Responsible party = who needs to do the thing.
```

Do not expose raw morph fields as labels.

Good:

```text
Assigned to
Responsible party
Related lead
Due date
Priority
```

Bad:

```text
assigned_to_type
assigned_to_id
responsible_type
responsible_id
related_type
related_id
```

## Imports and permission invitations UI

Import review UI should clearly distinguish:

```text
Imported status from the source file.
Engage Core status it maps to.
Rows that need review.
Rows that are safe to import.
```

Permission invitation UI should describe the business action:

```text
Ask imported leads to confirm how they want to hear from you.
```

Do not present permission invitations as a general Broadcast bypass.

The public preference page should make SMS opt-in explicit and should not imply SMS consent from email consent.

## Contact show UI

The contact show page is the main client mental model for a person. It should feel like a lead/customer workspace, not a CRM cockpit.

Current implementation direction:

```text
Core owns the contact show shell and generic contact details.
Modules contribute data, panels, and visibility sections through Core-owned registries.
Module-contributed sections should use muted wayfinding tones and client-facing labels.
The top of the page should lead with the next action before module detail.
```

It should answer:

```text
Who is this person?
What is their current status?
What has happened?
What needs attention?
What can I do next?
What is already being handled automatically?
```

The top of the page should make the next actionable step obvious.

Good:

```text
Next step
Call the lead and record the outcome.
Owner: Taylor
Due: Tomorrow 9:00 AM

Up next
Review application documents.
```

Also good:

```text
No action needed right now.
This lead has no open tasks or blocked follow-ups.
```

Avoid leading with field folders, raw tags, owner/follower mechanics, module tabs, activity rails, internal IDs, or large debug panels before the user can tell what should happen next.

Module panels should contribute useful summaries, not raw module state dumps.

Good panels:

```text
Needs attention
Recent messages
Webinar activity
Automatic follow-ups
Documents requested
Appointments
Orders/purchases
```

Each panel should include an obvious next action when applicable and should make it clear when no action is currently needed.

## Form and document UI

Forms and Documents should avoid blank-canvas client builders by default.

Client-facing actions should be:

```text
Send this form.
Review this submission.
Request these documents.
Review uploaded file.
Approve/reject/request replacement.
```

Developer/operator setup may define form schemas, document requirements, upload rules, and vertical interpretation.

## Visual design and interaction standards

### Page headers

A page header should include:

```text
plain-language title;
one-sentence purpose;
primary action when applicable;
secondary technical detail only when necessary.
```

### Cards and panels

Cards should group one decision or one summary.

Avoid large cards that contain several unrelated configuration concepts.

### Tables

Tables are for scanning many records.

Do not use a table-like row layout when the user is really configuring one thing at a time.

### Forms

Forms should:

```text
minimize required fields;
use sensible defaults;
show helper text only when it reduces ambiguity;
validate inline where possible;
preserve context after save.
```

### Feedback

Success/error feedback should say what happened.

Good:

```text
Follow-up updated for Prospect.
Broadcast scheduled for 842 recipients. 17 were skipped.
Task created for Taylor.
```

Bad:

```text
Saved.
Error.
Action failed.
```

## Known UX issue catalog

Use this section to capture UX problems discovered during review. Move items to `TODO.md` only when they become actionable implementation backlog.

### Platform cockpit screens

Current risk:

```text
The UI exposes every module, builder, setting, log, tag, trigger, dashboard widget, and provider-adjacent tool as if the client should understand all of it.
The user sees many available controls but no clear next action.
The screen teaches the software's architecture instead of the client's business workflow.
Empty charts and broad counts make the product feel large but not helpful.
Automation builders expose raw triggers, waits, branches, tags, execution logs, and publish controls before the guided workflow is clear.
```

Target direction:

```text
Lead with today's decisions and next actions.
Use small navigation.
Hide builders behind guided setup or operator/developer access.
Expose what is on, what will happen, and what needs attention.
Show raw internals only in details/debug contexts.
Prefer preset-backed choices over blank-canvas configuration.
```

### Route Trigger Bindings screen

The old technical Route Trigger Bindings direction has been replaced by the current Routes information architecture:

```text
Routes
    Manage Routes
    Assignments
```

Assignments should use business-language status/activity labels, configured contact nouns, and clear selected/unselected state.

Keep raw event keys and version internals secondary.

Do not repeat assignment detail inside Manage Routes. Manage Routes explains what a Route does; Assignments explains when it runs.

## UI review checklist

Run this before considering a client/operator-facing screen done.

```text
Can the intended user explain what the page is for in one sentence?
Can the intended user complete the normal task in 5-10 minutes or less?
Does the page lead with the business action/outcome?
Are configured nouns used consistently?
Are implementation terms hidden or translated?
Is the next action obvious?
Is there only one primary action per focused area?
Are consequences shown before automation-triggering actions are confirmed?
Are empty states useful and action-oriented?
Are error states specific enough to fix the problem?
Does the UI reuse an existing pattern where the mental model is the same?
Does the UI avoid raw schema/config/event/provider jargon?
Would a first-time client feel calm rather than overwhelmed?
Does the screen avoid making the client feel like a software administrator?
Does the UI preserve context after save where reloads would be frustrating?
Is this production/client UI, not a reused dev-testing pattern?
```

## Reusable field/token insertion

Many Engage Core screens ask operators to write reusable copy or instructions that can include dynamic values from a contact, webinar, campaign enrollment, task, form submission, document request, route context, or vertical subject.

Do not make users memorize token syntax.

Use client-facing language such as:

```text
Insert field
Add field
Available fields
```

Avoid making `token` the primary UI word unless the surface is explicitly developer/admin-facing.

Preferred interaction:

```text
The user is typing in an input or textarea.
The cursor/focus stays exactly where they are typing.
They click Add field / Insert field.
An autocomplete opens at the current cursor position.
They type a friendly search term such as fir.
The picker suggests First name.
Selecting it inserts the hidden token syntax at the cursor.
```

The UI should show friendly labels, examples, and categories. The stored value should use a stable hidden syntax that is unambiguous and unlikely to conflict with normal user copy.

Default hidden syntax:

```text
{{ first_name }}
{{ webinar_title }}
{{ contact.email }}
```

The runtime may normalize this into the current internal token syntax when needed, but the UI should avoid requiring users to manually type braces or exact keys.

Do not invent available fields in the UI. Available fields should come from a shared provider/registry that knows the current authoring context.

Examples of context-specific field groups:

```text
Contact fields
Webinar fields
Campaign fields
Task fields
Route fields
Form fields
Document fields
Commerce fields
Vertical subject fields
```

When a field is unavailable for the current context, it should not appear as a normal selectable option. If it appears for education/debugging, it must clearly explain why it cannot be used there.

Authoring surfaces that should eventually use this pattern:

```text
Message Templates
Broadcast authoring
Campaign message templates
Webinar message setup/copy links
Task templates
Route send-message points
Form confirmation messages
Document request/reminder messages
Permission invitation copy
```

This is UX first, but it may require a real shared available-field/token registry. Treat that registry and validation as setup/config-validation work before polishing every editor.

## Contextual hints

Use contextual hints to help operators understand what a field, setting, or navigation item does without turning the screen into documentation.

Preferred behavior:

```text
A small hint icon, dotted underline, or subtle help affordance appears near the label.
Hovering or focusing for a brief moment shows one plain-language explanation.
Keyboard focus should reveal the same help as hover.
```

Hints should answer:

```text
What does this do?
When would I use it?
What happens if I change it?
```

Hints should not expose schema names, raw config keys, event keys, class names, or implementation details as the primary explanation.

Good:

```text
Route Management
Choose what automatic actions happen after important contact activity.
```

Bad:

```text
Manage FlowRouteTriggerBinding records for automation_event and contact_status triggers.
```

Hints are not a replacement for consequence previews. If changing a setting triggers messages, tasks, status changes, route execution, campaign enrollment, or cancellation, show a clear preview before save.

## Preserve context for inline CRM actions

CRM workflows should avoid unnecessary full-page reloads when an operator is acting inside a focused context such as a dashboard panel, task list, modal, contact panel, setup checklist, or row action menu.

When an action is small, localized, and reversible or easily confirmed, prefer an inline/AJAX-style interaction that preserves the operator's current position, filters, selected record, modal state, and visible feedback.

Good candidates include:

```text
completing or reopening a task
cancelling, archiving, restoring, or reassigning a task
sending a focused task broadcast
loading contextual action options inside a modal
marking a setup/readiness item reviewed
refreshing a panel after a successful action
simulating/testing module behavior in local/staging tools
```

The interaction should provide clear feedback in place, such as:

```text
row/card state update
subtle success message
refreshed count
inline activity log
visible validation error near the field/action
```

It should not make the operator hunt back to where they were.

Full-page submits are still acceptable when the action naturally changes the whole page context, requires a multi-step confirmation flow, creates a new primary record, or is not worth adding client-side complexity yet.

This is a UX pattern first, not a schema requirement. Only add persisted state when the workflow proves that user-specific selections, filters, acknowledgements, dismissals, saved views, or selected batches need to survive beyond the current page session.

## Route Management language

`FlowRoutes` is the internal module/domain name.

Client/operator-facing UI should use:

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

Avoid making `FlowRoutes`, `FlowRouteTriggerBinding`, `automation_event`, `event_wait`, raw event keys, or handler configuration the primary client-facing language.

Use `Campaign` consistently for Campaign-owned journeys. Do not relabel Campaigns as follow-up sequences inside Routes.

The current navigation concept is `Routes`, with `Manage Routes` and `Assignments` as the two main surfaces.

Manage Routes answers what a Route does.

Assignments answers when it runs.

## Broadcast authoring direction

Broadcasts should stay simpler than Campaigns.

The Broadcast authoring UI should lead operators through one clear one-time send, not a platform cockpit.

Recommended direction:

```text
1. Choose channel.
2. Write the channel-specific message payload.
3. Choose recipients.
4. Review duplicate-send protection and schedule/send.
```

Channel choice should shape the payload form:

```text
Email -> subject + body
SMS -> message
```

Imported-contact opt-in invitations should not receive equal weight with normal Broadcast authoring. They are a distinct Messaging-owned one-time permission flow and should be exposed as a secondary action such as:

```text
Send opt-in invitation to imported contacts
```

When no contacts are eligible for the opt-in invitation, hide or disable the import-batch/options area and explain that there are no eligible contacts to invite.

Duplicate-send protection is useful but secondary. Prefer a collapsed `Avoid duplicate sends` section with a short summary of what is currently selected.

A future `Make a new broadcast from this` action is useful for repeating a prior Broadcast with a new channel or audience. Add lineage fields such as `cloned_from_broadcast_id` only if audit/debug/product needs prove that lineage should be persisted.

## Campaign message-step presentation

Campaign UI should describe business meaning before technical machinery.

Prefer:

```text
Message steps
Step 1
Step 2
Available channels
When it sends
```

Avoid making `delivery options`, `variant strategy`, `dispatch key`, `message_type`, `purpose`, `scope`, or config paths primary labels.

A campaign list/card should make the basic shape easy to understand:

```text
5 message steps
Email + SMS available
Starts after webinar attendance
```

Each campaign step should be collapsible. The collapsed state should show only the most useful information:

```text
step number
title/business moment
available channel badges
human-readable timing summary
current selected template health/readiness
```

Technical details belong behind a details popover, disclosure, or developer/debug mode.

Timing must be translated from internal schedule values into user expectations.

Good:

```text
Sends 10 days after the webinar.
Sends 2 weeks after the previous message.
Sends immediately after someone attends.
Sends when this route reaches the step.
```

Bad:

```text
Delay 10 minutes
schedule.type = delay
criteria.timing.days = 3
```

Dropdown labels should avoid repeated machine context.

Bad:

```text
Step 1 Email — Webinar Attended Nurture — Step 1 Email
```

Better:

```text
Email follow-up
SMS follow-up
Attended thank-you email
Missed webinar replay email
```

Keep raw campaign/template/config identity available only where it helps diagnostics.

## Configured Contact nouns and canonical fields

Client/operator UI should use the business noun that makes the product feel native to the client, such as Lead, Fan, Customer, Borrower, Owner, Member, or Contact.

That presentation choice should not create new internal domain concepts.

Authoring UI may expose field aliases such as:

```text
lead_first_name
fan_first_name
customer_first_name
```

when those names make the authoring experience clearer for the current client. The UI should resolve those aliases to one canonical internal Contact field such as `contact.first_name`.

The product should optimize for the user's vocabulary without duplicating runtime concepts, token sources, schema, or validation logic.
