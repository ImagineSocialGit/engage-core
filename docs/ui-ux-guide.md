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
Start or stop a follow-up sequence.
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
| Campaign enrollment | Start follow-up sequence |
| Message template preset | Message template |
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

## Core screen patterns

### “When this happens, what should happen next?” pattern

Use for automation, routing, follow-ups, event reactions, and status-triggered behavior.

Recommended structure:

```text
Page title: Automatic Follow-ups
Intro: Choose what should happen automatically when a lead changes status or takes an action.

Tabs:
- By Status
- By Activity

By Status:
- Select a status
- Show current follow-up
- Show consequence preview
- Let user change selected follow-up

By Activity:
- Select module tab
- Select activity
- Show selected follow-ups
- Show consequence preview
- Let user enable/disable/change follow-ups
```

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
campaigns or follow-up sequences that will start or stop;
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

### Route Binding page replacement direction

The current Route Trigger Bindings screen should be redesigned around business outcomes.

Replace:

```text
Route Trigger Bindings
Contact status routes
Automation event routes
Workflow status
webinar.attended
0 available routes
One route per status
Multiple routes allowed
```

With:

```text
Automatic Follow-ups
By Status
By Activity
Status
When someone attends a webinar
When someone misses a webinar
No follow-up is available yet
One follow-up can run for this status
More than one follow-up can run for this activity
```

The first implementation target should be:

```text
Automatic Follow-ups
Choose what should happen automatically when a lead changes status or takes an action.

Tabs:
1. By Status
2. By Activity
```

#### By Status

```text
Select a status: [New / Registered / Engaged / Prospect / ...]

Current follow-up:
Prospect follow-up

What it does:
- Stops attended-webinar nurture if it is running.
- Creates a sales follow-up task.

Change follow-up: [select]
Save changes
```

#### By Activity

```text
Module tabs: Webinars / Tasks / Forms / Documents / Commerce / ...

Webinars:
- When someone attends a webinar
- When someone misses a webinar

Selected activity:
When someone attends a webinar

Current automatic follow-ups:
- Move lead to Attended Webinar
- Start attended-webinar follow-up sequence
```

### FlowRoute names

FlowRoute DB names may stay operator-oriented, but client-facing cards should prefer a display summary.

Good display:

```text
Start attended-webinar follow-up
Move lead to Attended Webinar
Create sales follow-up task
```

Bad display:

```text
Webinar Attended Status Transition - v1
webinar_attended_status_transition
```

### Route points

Route point previews should summarize outcomes in plain language.

Examples:

```text
Send email: Webinar replay follow-up
Send SMS: Reminder text
Create task: Call lead within 1 day
Change status: Prospect
Start campaign: Attended webinar nurture
Stop campaign: Missed webinar nurture
Wait: 3 days
Wait until: Task is completed
```

Do not expose handler names such as `enroll_campaign`, `event_wait`, or `branch_evaluate` as primary labels.

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

Campaign UI should present campaigns as follow-up sequences or journeys, not as raw scheduled message machinery.

Good client/operator labels:

```text
Follow-up sequence
Step
Message
Delay
Exit condition
Start sequence
Pause sequence
Stop sequence
```

Avoid as primary labels:

```text
campaign_step_due
message_type
payload
meta.message
```

### Messaging templates

Template UI should show what the message says and where it is used.

Recommended preview:

```text
Template name
Channel
Purpose in plain language
Used by
Subject/message preview
Tokens used
Last edited/customized state
```

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

The contact show page is the main client mental model for a person.

It should answer:

```text
Who is this person?
What is their current status?
What has happened?
What needs attention?
What can I do next?
```

Module panels should contribute useful summaries, not raw module state dumps.

Good panels:

```text
Tasks needing attention
Recent messages
Webinar activity
Campaign/follow-up status
Documents requested
Appointments
Orders/purchases
```

Each panel should include an obvious next action when applicable.

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

### Route Trigger Bindings screen

Current issues:

```text
The page title exposes implementation language.
“Workflow status” is unclear to clients.
The screen says Contact where the client-facing noun should be Lead or the configured noun.
Two large panels expose too much information at once.
Every status row is shown even when most have no available routes.
“0 available routes” is a system state, not a useful client explanation.
“Automation event routes” exposes architecture instead of business activity.
Raw event keys such as webinar.attended are primary labels.
Route names and version labels are too internal.
Repeated Save buttons make the page feel like a technical matrix.
The page does not preview what the selected route actually does.
```

Target direction:

```text
Rename the surface to Automatic Follow-ups.
Use tabs: By Status and By Activity.
Use configured lead/contact/customer nouns.
Use a status dropdown for status-triggered behavior.
Use module tabs and human-readable activity labels for event-triggered behavior.
Show consequence previews for selected follow-ups.
Hide raw keys and versions unless in details/debug mode.
Use one focused save action per selected status or activity.
```

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
Does the UI preserve context after save where reloads would be frustrating?
Is this production/client UI, not a reused dev-testing pattern?
```
