

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
    Intentionally recorded evidence that may help prove a repeated, potentially automatable pattern.

AutomationOpportunity
    Repeated evaluated occurrences now form a durable pattern that may justify a suggestion.
```

Most opportunity-producing occurrences represent explicit manual human behavior. Some occurrences are deliberately **evidence only** and do not create or advance an opportunity by themselves.

Current examples:

```text
task.created_manually
    evaluated manual behavior occurrence

task.completed_manually
    evidence only; used to correlate a later manual status change

automation_event.recorded
    evidence only; selected neutral domain events retained briefly enough to correlate a later manual action

task.created_after_automation_event
    evaluated compound occurrence produced only after a supported event and later manual Task are correlated
```

Keep these seams distinct:

```text
AutomationEventRecorded
    neutral domain outcome

AutomationBehaviorOccurrence
    compact behavior/correlation evidence

AutomationOpportunity
    aggregate suggestion lifecycle
```

Do not turn `AutomationEventRecorded` into clickstream tracking, and do not assume that every `AutomationBehaviorOccurrence` should create a user-facing opportunity.

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


## Implemented foundation status

The backend foundation is implemented and manually smoke-tested.

Current shared location:

```text
app/Support/AutomationOpportunities/
```

Current persistence:

```text
automation_behavior_occurrences
automation_opportunities
```

Current generic evaluation defaults:

```text
minimum occurrences = 3
minimum distinct subjects = 3
observation window = 30 days
```

The evaluator remains generic. It does not contain module/event-specific branching. Producer actions own semantic fingerprints and decide whether an occurrence should be evaluated or recorded as evidence only.

Current opportunity lifecycle:

```text
observing
eligible
suggested
dismissed
converted
invalidated
```

The current evaluator moves `observing -> eligible` when count/distinct-subject/window requirements are met and preserves later lifecycle states.

Current implementation does **not** yet make capability availability, equivalent existing automation, dismissal/snooze availability, or UI presentation part of the generic threshold evaluator. Those checks belong in later suggestion/readiness resolution where current runtime truth can be consulted without bloating the evaluator with domain-specific branches.

Manual smoke validation proved:

```text
3 equivalent manual Tasks across 3 Contacts
    -> task.created_manually becomes eligible

manual status change -> same manual Task across 3 Contacts
    -> task.created_after_manual_status_change becomes eligible

manual Task completion -> same manual status change across 3 Contacts
    -> contact.status_changed_after_manual_task_completion becomes eligible

supported automation event -> same manual Task across 3 Contacts
    -> event evidence remains evidence only
    -> task.created_after_automation_event becomes eligible

unsupported automation event
    -> no evidence row

supported event older than 10-minute correlation window
    -> evidence exists
    -> no compound event->Task occurrence

same manual behavior repeated 3 times on one Contact
    -> occurrence_count = 3
    -> distinct_subject_count = 1
    -> remains observing

system-created Task
    -> no manual behavior occurrence
```

Focused and adjacent automated tests are green, and the real CRM/manual smoke paths above passed.

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

Only explicitly participating business actions or deliberately selected structured event evidence should produce behavior occurrences.

Current implemented evaluated patterns:

```text
task.created_manually
task.created_after_manual_status_change
contact.status_changed_after_manual_task_completion
task.created_after_automation_event
```

Current implemented evidence-only patterns:

```text
task.completed_manually
automation_event.recorded
```

The current selected event-evidence allowlist is intentionally small:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

This allowlist is allowed to change as real evidence accumulates about which events produce useful downstream suggestions. Adding an event to evidence collection does **not** mean that event deserves an opportunity or user-facing prompt.

A producer or evidence source should be added only when there is a concrete, truthful reason to believe it can support a useful later suggestion.

Do not let evidence collection silently expand into “record every event just in case.”

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

Current Tasks producer behavior:

```text
Manual Contact-associated Task creation
    evaluated as task.created_manually

Manual Contact status change -> manual Contact-associated Task creation
    evaluated as task.created_after_manual_status_change

Manual Task completion
    recorded as task.completed_manually evidence only

Manual Task completion -> manual Contact status change
    evaluated as contact.status_changed_after_manual_task_completion

Supported AutomationEventRecorded evidence -> manual Contact-associated Task creation
    evaluated as task.created_after_automation_event

Manual standalone Task
    not currently observed

FlowRoute-created Task
    not manual behavior

Module/system-created Task
    not manual behavior
```

The current correlation window for implemented compound patterns is 10 minutes.

For manual-to-manual compound patterns, provenance must remain explicit enough to support trustworthy attribution. For the current status-change -> Task pattern, the same actor must perform both actions.

For event-evidence -> Task correlation, the current implementation uses the same Contact and the most recent supported evidence occurrence within the window. As more trigger types are added, protect against ambiguous attribution rather than forcing a suggestion when multiple plausible triggers make the cause unclear.

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

Current implemented generic evaluator defaults:

```text
minimum occurrences = 3
minimum distinct subjects = 3
observation window = 30 days
```

The evaluator may accept explicit alternate values from a caller, but it should remain generic and free of domain-specific branching such as:

```text
if task.created_manually -> 3
if document.requested_manually -> 5
if webinar.attended -> special rule
```

Producer-specific meaning belongs in producer actions, fingerprints, or later suggestion/readiness resolvers—not in a central evaluator full of module/event cases.

Current implemented behavior:

```text
observing
    evidence exists but generic count/distinct-subject/window qualification is not met

eligible
    generic qualification is met

later lifecycle states
    preserved rather than reset by new occurrences
```

Additional suggestion-time checks may later include:

```text
required capability available
equivalent automation already present
currently snoozed
already converted
underlying context still valid
attribution still unambiguous
```

Those checks should resolve current runtime truth dynamically where appropriate. Do not persist stale permanent truths such as `already_automated = true`.

Do not add speculative per-action thresholds for modules that have not opted in.

## Direct vs exploratory opportunities

Some repetition directly implies a useful automation.

Example:

```text
The same Task was manually created for several Contacts in the same status.
```

This can support a specific suggestion:

```text
Add this Task to the Route for Attempting Contact?
```

Some compound patterns are even more specific:

```text
Manual status change -> same manual Task
Manual Task completion -> same manual status change
Supported domain event -> same manual Task
```

These may support suggestions such as:

```text
You've created "Call this contact" for 3 Contacts after moving them to Attempting Contact.
Add that Task to the Route so it happens automatically next time?
```

```text
You've moved 3 Contacts to Approved after completing "Review application."
Make that status change happen automatically when the Task is completed?
```

```text
You've created "Follow up with contact" for 3 Contacts after they confirmed their communication preferences.
Add that Task to the Route?
```

A plain repeated manual Contact status change is **not currently an opportunity producer**. The transition alone does not prove the correct trigger and should not create a generic `contact.status_changed_manually` opportunity.

When information is missing, classify the product response conceptually:

```text
direct
    enough information exists to automate the next action

assisted
    the repeated pattern is clear, but required information must be collected first

observe_only
    repetition exists, but no trustworthy next step can be suggested
```

Example assisted flow:

```text
Intake becomes complete
    -> find suitable available appointment times
    -> send booking invitation
    -> wait for customer selection
    -> create appointment
```

Do not rush to persist these modes as columns unless runtime/UI behavior proves they are durable first-class state.

Core rule:

```text
When a repeated workflow cannot be completed automatically because required information is missing,
automate the collection of that information and continue afterward.
```

Do not pretend to know a missing trigger or missing human decision.

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

The backend foundation sequence is complete:

```text
1. Audit current state.                                      COMPLETE
2. Update/add durable docs.                                 COMPLETE
3. Add occurrence/opportunity migrations and models.        COMPLETE
4. Add justified producer integrations.                     COMPLETE for current slice
5. Add focused/adjacent tests and manual CRM smoke tests.    COMPLETE
```

The first Routes management/editor baseline is also complete:

```text
Manage Routes / Assignments information architecture
business-language Route presentation
modal Route editing
modal Point editing
drag-and-drop ordering with explicit Save order
linear authoring boundary
explicit direct-Route Messaging eligibility
Point placement policy and matching UI guardrails
```

Contextual Automation Opportunity suggestion UX remains future work.

Implemented current-slice producers/evidence:

```text
task.created_manually
task.created_after_manual_status_change
task.completed_manually                         evidence only
contact.status_changed_after_manual_task_completion
automation_event.recorded                       evidence only
task.created_after_automation_event
```

Current supported automation-event evidence keys:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

The evidence allowlist may change as actual usefulness becomes clearer. The generic evaluator and opportunity lifecycle should remain protected from event/module-specific bloat.

Do not add another producer merely because a manual action exists. Add one only when the system can explain a useful repeated pattern and offer a trustworthy next step.

The next suggestion work should integrate with the current Routes product rather than create a parallel automation builder or recommendation feed.

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

Do not add pruning merely for symmetry.

The current occurrence table intentionally stores compact evidence and has no speculative archival subsystem.

Evidence collection may evolve:

```text
event repeatedly proves useful for later correlation
    -> keep/add it

event creates noise or never supports useful suggestions
    -> remove it from future evidence collection
```

Removing an event from the allowlist should stop future evidence capture; it does not require rewriting history by default.

The architecture should allow future retention rules such as pruning old, uninteresting occurrences after a defined period while preserving occurrences tied to suggested or converted opportunities.

Add retention/pruning only when real volume or operational needs justify it. Avoid long-term auditing/history storage with little product payoff.
