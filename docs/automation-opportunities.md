# Automation Opportunities

This document defines Engage Core's durable architecture and product direction for noticing repeated meaningful manual work and suggesting automation without acting autonomously.

Use this document for cross-module ownership, persistence, producer integration, fingerprinting, aggregation, eligibility, and suggestion behavior.

Use `docs/modules/*.md` for module-specific producer rules. Use `docs/ui-ux-guide.md` for how suggestions should appear. Use `docs/module-boundaries.md` for global dependency rules.

## Product goal

Automation Opportunities should make Engage Core feel easier and less intimidating by quietly recognizing repeated manual work and offering one understandable next step.

The intended product behavior is:

```text
Observe repetition.
Explain the pattern.
Suggest one clear next step.
Never act without permission.
```

Good:

```text
You've created this task for 3 contacts in Attempting Contact.
Add it to their Route so it happens automatically next time?
```

Avoid:

```text
Automation opportunity detected with confidence score 0.84.
```

Avoid autonomous behavior such as creating a Route, changing a Contact status, sending a message, or enrolling a Campaign without explicit user approval.

This is deterministic product assistance, not a general AI agent, recommendation engine, or behavioral-surveillance system.

## Core distinction

Engage Core already has a generic automation-event seam:

```text
AutomationEventRecorded
    Something happened in the business/domain that automation may react to.
```

Automation Opportunities are different:

```text
AutomationBehaviorOccurrence
    A human performed a meaningful manual action that may be worth automating.

AutomationOpportunity
    Repeated occurrences now form a durable pattern that may justify a suggestion.
```

Examples:

```text
task.completed
    generic automation event

manually creating the same task for several Contacts in the same status
    automation behavior occurrences that may aggregate into an opportunity
```

Do not overload `AutomationEventRecorded` with manual behavior observation.

## Architectural ownership

Automation Opportunities are shared app-level infrastructure.

Recommended location:

```text
app/Support/AutomationOpportunities/
```

They are not owned by FlowRoutes, Tasks, Core, Workflow, or another feature module.

Responsibility split:

```text
Participating module
    decides which manual actions are meaningful enough to observe
    owns semantic fingerprint inputs for its own action
    records compact normalized context

Automation Opportunities infrastructure
    persists occurrences
    normalizes and hashes fingerprint parts
    aggregates opportunities
    tracks opportunity lifecycle
    evaluates generic thresholds and suppression state
    exposes read seams for suggestion surfaces

Automation capability registry
    describes what the platform can automate

FlowRoutes
    owns how accepted automation is represented and executed
```

The suggestion system should not create or mutate FlowRoutes directly merely because a threshold was reached.

## No clickstream rule

Do not record:

```text
page views
clicks
keystrokes
arbitrary form input
raw HTTP requests
every model save
full Eloquent model arrays
loaded relationship graphs
```

Only explicitly participating manual business actions should produce behavior occurrences.

Good initial examples:

```text
task.created_manually
contact.status_changed_manually
document.requested_manually
appointment.created_manually
campaign.enrolled_manually
```

Possible later examples:

```text
message.sent_manually
broadcast.repeated
document.replacement_requested_manually
```

A producer should be added only when the repeated behavior can produce a useful, truthful suggestion.

## Producer opt-in contract

Do not globally infer manual behavior from Eloquent events.

A participating module should explicitly record an occurrence from a manual application/UI path or another unambiguous human-action seam.

Example:

```text
TaskController::store()
    -> CreateTaskAction
    -> record task.created_manually occurrence
```

Avoid placing manual-behavior recording in a generic domain action when that action is also used by FlowRoutes, system code, imports, provider sync, or other modules.

The first Tasks producer should distinguish:

```text
Manual Contact-associated task
    strong opportunity candidate

Manual standalone task
    may be observable later, but has no Contact-status Route context by default

FlowRoute-created task
    not manual behavior

Module/system-created task
    not manual behavior
```

## Persistence model

The intended foundation uses both durable occurrence history and an aggregate opportunity record:

```text
automation_behavior_occurrences
automation_opportunities
```

This combines durable evidence with fast suggestion decisions.

### Automation behavior occurrence

An occurrence is an append-style record of one intentionally observed manual action.

Conceptual fields:

```text
id
action_key
actor morph nullable
subject morph nullable
capability_key nullable
fingerprint
fingerprint_parts json
context json nullable
meta json nullable
occurred_at
timestamps
```

Use compact context only. Do not persist whole models, arbitrary requests, or raw application state.

### Automation opportunity

An opportunity is the aggregate lifecycle record for one repeated behavior pattern.

Conceptual fields:

```text
id
action_key
fingerprint
capability_key nullable
status
occurrence_count
distinct_subject_count
distinct_actor_count
first_occurred_at
last_occurred_at
eligible_at nullable
suggested_at nullable
dismissed_at nullable
dismissed_until nullable
converted_at nullable
invalidated_at nullable
context json nullable
meta json nullable
timestamps
```

The likely durable identity is:

```text
action_key + fingerprint
```

Exact migration columns and indexes should be finalized during the schema slice, using the current project migration conventions.

## Why both occurrence history and aggregation exist

Occurrence history answers:

```text
Which actions produced this suggestion?
Were distinct Contacts involved?
Was the explanation shown to the user truthful?
Did the pattern happen recently or only long ago?
```

The aggregate opportunity answers:

```text
Has the threshold been met?
Is a suggestion eligible now?
Was it dismissed, snoozed, converted, or invalidated?
```

Do not reduce the foundation to only counters unless a later schema audit proves the occurrence history has no product value.

## Fingerprinting

The participating module owns semantic equivalence.

The shared infrastructure owns deterministic normalization and hashing.

A producer should supply:

```text
action_key
actor nullable
subject nullable
capability_key nullable
fingerprint_parts
context
meta
occurred_at
```

The shared layer should recursively normalize fingerprint parts, sort associative keys, serialize deterministically, and hash consistently.

Do not let the shared infrastructure guess that two module-specific actions are equivalent.

### Tasks example

Preferred fingerprint inputs for a manual Contact-associated Task:

```text
related subject type
task_template_key when available
normalized title when no template exists
Contact status key when available
```

Example:

```text
action_key = task.created_manually
related_subject_type = contact
contact_status_key = attempting_contact
task_template_key = call_contact
```

When no template exists:

```text
action_key = task.created_manually
related_subject_type = contact
contact_status_key = attempting_contact
normalized_title = call this contact
```

Do not include incidental values such as database IDs, exact timestamps, or due dates in a fingerprint unless the owning module deliberately defines them as part of equivalence.

## Capability relationship

Automation Opportunities should reference stable capability identity by key when applicable:

```text
capability_key
```

Do not make shared opportunity infrastructure canonically depend on `flow_route_capability_id`.

The shared automation capability registry is broader than FlowRoutes-owned DB materialization.

The suggestion layer may later ask:

```text
Does the capability still exist?
Is the required module enabled?
Is FlowRoutes enabled?
Is the capability active and usable in this context?
Does equivalent automation already exist?
```

## Eligibility and thresholds

Eligibility should be deterministic and explainable.

Recommended initial default for the first producer:

```text
minimum occurrences = 3
minimum distinct subjects = 3
observation window = 30 days
required capability available = true
equivalent automation already present = false
currently snoozed = false
already converted = false
```

Thresholds should be configurable per action definition rather than permanently hard-coded as one global rule.

Examples:

```text
task.created_manually
    3 distinct Contacts in 30 days

document.requested_manually
    possibly 3 distinct subjects in 60 days

broadcast.repeated
    possibly 2 repetitions
```

Do not add speculative thresholds for modules that have not yet opted in.

## Direct vs exploratory opportunities

Some repetition directly implies a useful automation.

Example:

```text
The same task was manually created for several Contacts in the same status.
```

This can support a specific suggestion:

```text
Add this task to the Route for Attempting Contact?
```

Other repetition proves a pattern but not its trigger.

Example:

```text
Several Contacts were manually moved from New Contact to Attempting Contact.
```

The system can truthfully explain the repetition, but it may not know whether the correct trigger is a form submission, webinar outcome, task completion, elapsed time, document upload, or another event.

Treat these as exploratory opportunities unless enough context exists for a specific automation proposal.

Do not pretend to know a missing trigger.

Whether this distinction becomes a first-class persisted field should be decided during implementation only if runtime/UI behavior requires it.

## Already-automated checks

Do not persist `already_automated = true` as permanent truth.

Routes and bindings may change.

A Route can be:

```text
edited
disabled
rebound
versioned
removed
```

Eligibility should dynamically resolve whether equivalent automation currently exists.

A future eligibility/equivalence resolver may inspect public FlowRoutes seams and durable route/capability data without making producer modules depend on FlowRoutes.

## Opportunity lifecycle

The first durable lifecycle should stay small and explicit.

Likely states:

```text
observing
eligible
suggested
dismissed
converted
invalidated
```

Do not add more statuses merely for symmetry.

Semantics:

```text
observing
    occurrence evidence exists but threshold/eligibility is not met

eligible
    enough evidence exists and the suggestion is currently valid

suggested
    a suggestion has actually been shown

dismissed
    the user intentionally declined or snoozed it

converted
    the user accepted the opportunity and completed the automation setup path

invalidated
    the pattern is no longer actionable or the underlying capability/context is no longer valid
```

Dismissal and snoozing should be explicit product state, not inferred from silence.

## UX rules

Automation suggestions should be:

```text
contextual
sparse
truthful
specific
non-blocking unless a real safety consequence exists
```

The system should stay silent after one-off behavior.

Do not turn every manual action into a prompt.

A suggestion should explain why it appeared using plain business language.

Good:

```text
You've created this task for 3 contacts in Attempting Contact.
Add it to their Route so it happens automatically next time?
```

Avoid:

```text
We noticed a pattern.
Automate with AI.
Confidence: 84%.
```

The suggestion layer teaches users how to get more value from Engage Core based on work they already perform.

Route Management remains the control center for reviewing what happens automatically. Contextual suggestions are the discovery layer.

## First implementation sequence

The agreed sequence is:

```text
1. Audit current state.
2. Update/add docs that maintain the direction and goal.
3. Add migrations and models.
4. Add automation-opportunity producers module by module where justified.
5. Add tests.
6. Continue Route Management and contextual UX work.
```

The first producer should be manual Contact-associated Task creation.

Manual Contact status changes are a good second producer candidate, but repeated status changes are often exploratory because the correct automatic trigger may be unknown.

## Module integration checklist

Before a module opts in, answer:

```text
What exact manual action is being observed?
How do we know it was manual?
What makes two occurrences meaningfully equivalent?
What compact context is needed to explain the suggestion?
What automation capability could satisfy it?
Can we determine whether equivalent automation already exists?
What threshold is appropriate?
Is the resulting suggestion specific and useful?
Does the module record the occurrence through shared infrastructure without depending on FlowRoutes?
```

Do not add a producer when these questions cannot be answered clearly.

## Retention and upkeep

Do not add pruning in the first slice unless real volume requires it.

The architecture should allow future retention rules such as pruning old, uninteresting occurrences after a defined period while preserving occurrences tied to suggested or converted opportunities.

Do not add speculative archival infrastructure before actual data volume proves it necessary.
