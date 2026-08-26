# Engage Core Module UI/UX Review Playbook

This playbook is the repeatable process for taking an Engage Core module from technically complete to client-usable.

Use it alongside:

- `docs/ui-ux-guide.md` — the canonical UI/UX standard;
- `docs/product-principles.md` — the product posture;
- `docs/module-surfaces.md` — loud/silent surface ownership;
- the module's `module_state.md` and `TODO.md` — current module truth and backlog.

This document does **not** define a second UI/UX standard.

```text
UI/UX Guide
    Defines the rules.

Module UI/UX Review Playbook
    Defines the review procedure.
```

When a module review reveals a lesson that applies across Engage Core, add that lesson to `docs/ui-ux-guide.md`.

When a review reveals a better way to perform the review itself, update this playbook.

When a finding is specific to one module, keep it with that module.

---

## 1. Review outcome

A module passes this process when a person with little or no prior training can understand what the module is for, complete its common setup and routine jobs, recover from ordinary mistakes, and tell whether the module is ready.

For common workflows, the target remains roughly **5–10 minutes without documentation or developer assistance**.

Do not evaluate only whether the UI is attractive or technically complete.

The review must answer:

```text
Can a new user orient themselves?
Can they tell what to do first?
Can they complete the common task?
Can they tell what is ready, incomplete, or blocked?
Can they operate the module without learning Engage Core's internal architecture?
```

---

## 2. Required inputs before review

Before redesigning a materially client-facing module:

```text
[ ] Get a fresh dependency cone.
[ ] Review the module's module_state.md.
[ ] Review the module's active TODO.md.
[ ] Review docs/ui-ux-guide.md.
[ ] Review docs/module-surfaces.md.
[ ] Inventory CRM, contextual, public, portal, and other client-facing surfaces.
[ ] Capture screenshots of the empty state.
[ ] Capture screenshots of representative populated states when available.
[ ] Confirm which optional module integrations may be present or absent.
```

Do not start by editing Blade files.

Ground the review in the actual current module.

---

## 3. Build the module job map

List the real jobs the user needs the module to perform.

Record at least:

| Job type | Question |
| --- | --- |
| Setup | What must exist before this module becomes useful? |
| Routine | What will the user do most often? |
| Review | What needs to be inspected, confirmed, or monitored? |
| Exception | What occasionally requires manual intervention? |
| Administration | What needs configuration but is rarely touched? |
| Operator/developer | What should not normally be exposed to the client? |

Example:

```text
Scheduling

Setup
    Define what can be booked, who can handle it, and when.

Routine
    Book, review, reschedule, or cancel appointments.

Review
    See upcoming appointments and items awaiting action.

Exception
    Special hours, blackouts, capacity/resource conflicts.

Administration
    Advanced booking policy.

Operator/developer
    Stable keys, provenance, rule-engine mechanics.
```

Use this job map to judge the information architecture.

Do not assume the existing controllers, models, tables, or configuration pages represent the correct product structure.

---

## 4. Define the core 10-minute scenario

Write one plain-language task that represents the module's core value.

It should sound like something one staff member would ask another staff member to do.

Examples:

```text
Scheduling
    Set up a 30-minute consultation available Monday through Friday from 9–5,
    then book Jane Smith for Tuesday at 2.

Broadcasts
    Send a one-time email update to contacts with a specific tag,
    and confirm who will receive it.

Campaigns
    Create or activate a follow-up Campaign for a group of leads,
    and confirm what will happen next.

Forms
    Publish a simple intake form, submit one test response,
    and find the resulting Contact and submission.
```

The scenario becomes the primary acceptance test for the UX pass.

Record the expected starting state and pass condition.

---

## 5. Review first use separately from routine use

### First-use review

Start from an enabled, correctly installed, but meaningfully empty module.

Record:

```text
[ ] Is the purpose immediately clear?
[ ] Is the first setup action obvious?
[ ] Is setup order obvious?
[ ] Are required and optional steps distinguishable?
[ ] Are blocked/inert actions explained?
[ ] Is partial readiness visible?
[ ] Can the user reach the first useful result without exploring unrelated screens?
[ ] Does the consuming surface contain the setup needed to complete its own job?
[ ] If later maintenance lives elsewhere, does the first successful use name and link that exact location?
```

If several durable prerequisites are required, explicitly assess whether the module needs a guided setup state.

Do not treat the shared Settings & setup directory as the default answer to first use. The cross-platform getting-started area must remain deliberately limited; it should teach a few high-value platform concepts rather than mirror every enabled module as a checklist.

### Routine-use review

Review the same module after realistic configuration exists.

Record:

```text
[ ] Does the landing page emphasize current work?
[ ] Is the common action obvious?
[ ] Is the common action short?
[ ] Are review/exception tasks easy to find?
[ ] Does advanced configuration stay out of the routine path?
[ ] Does the user understand what the system already handled automatically?
```

A good routine screen does not excuse a bad first-use experience.

A good onboarding flow does not excuse a slow routine workspace.

---

## 6. Run the scenario without documentation

Attempt the core scenario as if the tester has never seen the module.

Record every point where the tester:

```text
asks what a term means;
cannot tell what to click next;
must visit multiple screens to infer setup order;
encounters an implementation concept before a business decision;
cannot predict what an action will do;
hits an inert control without understanding why;
must remember information from a previous screen;
must use documentation or developer knowledge;
encounters an error without knowing how to recover.
```

Classify each finding.

Recommended categories:

```text
orientation
information architecture
terminology
progressive disclosure
readiness
empty state
navigation
form design
consequence clarity
error/recovery
optional integration
public/external flow
responsive/mobile
operator/developer leakage
```

Use `docs/ui-ux-guide.md` as the authority for what the correct pattern should be.

---

## 7. Perform the implementation-leak audit

Identify visible concepts that exist because of the backend rather than because the user needs to make a business decision.

Examples worth flagging include:

```text
stable keys
IDs
sort order
source ownership
provider identifiers
dispatch keys
raw runtime states
schema-oriented nouns
rule-engine terminology
provenance
polymorphic/reference concepts
raw capacity mechanics
```

For each finding, choose one disposition:

```text
keep visible
rename/translate
keep and explain visibly below the decision
keep as a secondary help term with accessible hover/focus/tap/click help
derive automatically
generate automatically
move to Advanced
move to operator/developer detail
remove from the client surface
```

Do not solve implementation leakage by weakening a sound backend boundary.

Prefer translating the backend into a simpler surface model.

When a necessary term cannot be replaced, verify that its explanation answers all three questions:

```text
What does this mean?
Why would I use it?
What happens if I change it?
```

If understanding the term matters to the current decision, the explanation must remain visible below the control. Secondary or repeated terms may use a clearly signaled help affordance, but it must work by hover, keyboard focus, and tap/click. A hover-only tooltip fails the review.

---

## 8. Build the readiness model

Define the module's meaningful readiness states.

Do not invent readiness only in Blade.

Use the same runtime/configuration truth that governs whether the feature can actually operate whenever possible.

Typical states:

```text
empty
partially configured
ready for internal use
ready for public/external use
blocked
integration unavailable
```

For each state, record:

| State | What makes it true? | What should the user see? | What is the next action? |
| --- | --- | --- | --- |

The UI should make incomplete readiness explicit rather than leaving unusable controls on screen without explanation.

---

## 9. Decide the target information architecture

Before field-level polishing, decide where work belongs.

For every screen or area, classify it as:

```text
routine work
setup
maintenance
advanced configuration
diagnostics/operator
public/external
```

Then decide:

```text
What belongs on the module landing page?
What belongs in guided first-time setup?
What belongs in long-term maintenance screens?
What belongs behind Advanced?
What belongs outside normal client UI entirely?
```

A loud module should present a coherent business workflow even when it consumes silent/shared capabilities.

Do not force users into a silent module merely because that module owns an underlying concept.

---

## 10. Separate first-time creation from long-term maintenance

Explicitly decide whether the best first-time flow differs from the maintenance workspace.

Example:

```text
First-time setup
    Create service
    Choose who handles it
    Add normal hours
    Review
    Ready

Long-term maintenance
    Services
    Staff/providers
    Hours and special availability
    Advanced capacity/resources
```

Do not force first-time users through maintenance-oriented screens simply because those screens already exist.

For every durable choice created or selected during first use, record:

```text
Where is it first introduced or configured?
Where is its authoritative long-term maintenance screen?
Does Settings & setup link to that screen when the choice is shared or reusable?
After the first successful use, is the later location taught once with an exact path and direct link?
Will that guidance stay out of the way on later routine uses?
```

The first-use surface may link to the same maintenance screen when that is the simplest experience. It must still explain the choice in the context of the task. Do not duplicate durable state or create a second form merely to make the settings directory feel complete.

---

## 11. Audit all important states

Inspect each meaningful surface in the states that apply:

```text
[ ] brand-new / empty
[ ] partially configured
[ ] ready
[ ] populated / active
[ ] no results
[ ] validation failure
[ ] runtime failure
[ ] inactive / archived
[ ] optional dependency present
[ ] optional dependency absent
[ ] public/external state
[ ] mobile/narrow layout
```

Do not sign off based only on a populated desktop screenshot.

---

## 12. Turn findings into implementation batches

Fix findings in this order unless module-specific constraints require otherwise:

```text
1. Information architecture
2. First-use/setup flow
3. Readiness and empty states
4. Terminology and implementation leakage
5. Form simplification/defaulting
6. Progressive disclosure
7. Error/recovery behavior
8. Routine-work efficiency
9. Responsive/mobile behavior
10. Visual polish
```

This ordering prevents cosmetic work from hiding structural problems.

For each implementation batch, record:

```text
problem being solved;
affected jobs;
affected states;
source-of-truth/runtime seam used;
files changed;
tests added or changed;
manual scenario step to re-test.
```

---

## 13. UX test expectations

Feature tests should protect behavior rather than freeze wording or CSS.

Prefer contracts such as:

```text
setup state appears when prerequisites are absent;
primary action targets the correct route;
normal creation does not require advanced fields;
readiness changes when prerequisites are satisfied;
advanced fields remain optional;
unavailable actions are hidden or blocked;
optional integrations degrade cleanly;
public/internal variants receive the correct data.
```

Use exact copy assertions only when wording itself is a legal or functional contract.

The backend remains authoritative for validation and runtime safety.

---

## 14. Verification sequence

After implementation:

```text
[ ] Focused feature tests pass.
[ ] Module feature suite passes.
[ ] Boundary tests pass when relevant.
[ ] setup:validate passes.
[ ] Empty-state manual smoke passes.
[ ] Core 10-minute scenario passes.
[ ] Routine-use smoke passes.
[ ] Public/external smoke passes when applicable.
[ ] Optional-integration-absent smoke passes when applicable.
[ ] Narrow/mobile smoke passes when applicable.
```

Then repeat the scenario from a fresh mental starting point.

Do not count developer familiarity as evidence of learnability.

---

## 15. Review scoring

Use the score as a diagnostic, not a vanity metric.

Score each category:

```text
0 = fails / confusing / requires explanation
1 = usable but friction-heavy
2 = obvious and fast
```

| Category | Score |
| --- | ---: |
| Purpose/orientation is immediately clear | /2 |
| First setup action is obvious | /2 |
| Setup sequence is obvious | /2 |
| Common terminology is understandable | /2 |
| Necessary terms are explained accessibly and in place | /2 |
| Internal concepts are appropriately hidden | /2 |
| Common create/action flow is short | /2 |
| Defaults reduce unnecessary decisions | /2 |
| Advanced options stay out of the common path | /2 |
| Readiness/prerequisites are clear | /2 |
| Empty states teach the next action | /2 |
| Errors explain recovery | /2 |
| Routine work is easier than configuration | /2 |
| Optional dependencies degrade cleanly | /2 |
| Consequences are understandable before action | /2 |
| Core scenario passes the 5–10 minute test | /2 |

Maximum: **32**.

Interpretation:

```text
29–32  strong client-ready UX
25–28  usable; targeted polish remains
20–24  operator-assisted; not convincingly self-serve
0–19   architecture is leaking heavily into the product surface
```

A high total does not excuse a zero in a critical category such as first-use orientation, readiness, or the core scenario.

---

## 16. Required deliverables for each module UX pass

Leave behind:

1. **Job map**
2. **Core 10-minute scenario**
3. **Finding log**
4. **Terminology/implementation-leak decisions**
5. **Information-architecture decision**
6. **Readiness model**
7. **Implementation batches**
8. **Focused UX behavior tests**
9. **Manual smoke result**
10. **Final review score**
11. **Generalized lessons, if any**

Generalized lessons go to `docs/ui-ux-guide.md`.

Module-specific findings stay with the module.

Review-process improvements go to this playbook.

---

## 17. Anti-patterns this process should catch

Flag these when they appear:

```text
A giant create form mirroring model fields.
A blank dashboard with zero cards but no setup direction.
A "Manage configuration" button as the only onboarding instruction.
Configuration organized around database entities instead of client jobs.
Stable keys or sort orders typed manually during normal creation.
Advanced capacity/routing/rule controls shown before basic setup.
An inert control with no explanation of the missing prerequisite.
A silent dependency exposed as a separate client workspace without a real product reason.
A user needing documentation to discover setup order.
Internal algorithms described in normal client copy.
A test suite that freezes awkward copy rather than behavior.
A polished populated screen that is unusable from an empty install.
```

The UI/UX Guide owns the corresponding design rules.

This list exists only to make review failures easier to spot.

---

## 18. Closeout standard

A module's UI/UX pass is complete when:

```text
A new user can orient themselves without explanation.
The setup path leads to a usable result.
The common business task can be completed in roughly 5–10 minutes.
Routine work is faster than setup/configuration work.
Readiness and recovery are understandable.
Advanced capability remains available without dominating normal use.
The user does not need to learn Engage Core's internal architecture to use Engage Core.
```

Record the final scenario result and score before closing the pass.

That result becomes the baseline for later regressions and future module reviews.