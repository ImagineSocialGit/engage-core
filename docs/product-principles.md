# Engage Core Product Principles

## Config authoring and export

Client configuration is an executable product surface, not merely installation documentation.
The authoring UI, exporter, setup validation, sync actions, runtime resolvers, and documentation
must derive from compatible registered contracts.

An export is acceptable only when it is structurally valid, reference-closed, token-valid for its
producer contexts, sync-safe in a fresh database, and semantically reloadable. Friendly aliases
may improve authoring, but they normalize to canonical fields and keys rather than creating new
runtime concepts. Arbitrary metadata and sensitive provider values are not general token sources.

Slam Dunk is the first golden vertical slice and regression target. Exact text snapshots are useful
for deterministic output, but normalized semantic round-trip equality and runtime execution are
the stronger guarantees.

Engage Core is a dev-built operating system for small service businesses.

It is not trying to recreate WordPress, Squarespace, Mailchimp, a generic CRM, a generic form builder, or a pile of disconnected SaaS tools inside one app.

The client is the expert in their own industry.

The developer/operator is responsible for turning repeatable admin, communication, scheduling, follow-up, intake, customer-service, and integration work into simple workflows the client can actually use.

## Product barometer

Use this question when deciding whether a feature, module capability, or UI surface should be client-facing:

```text
Can the client realistically complete this task in Engage Core in 10-15 minutes total?
```

If yes, the task can be client-facing.

If no, the task should usually be developer/operator work, automated, preconfigured, preset-driven, hidden behind a simpler action, or split into a guided workflow that only asks the client for business decisions they are qualified to make.

The question is not:

```text
Can the client technically configure this?
```

The question is:

```text
Should the client be spending their time configuring this?
```

## Client-facing work

Client-facing work should be action-oriented.

Good client-facing workflows are things like:

```text
Draft a Broadcast message.
Select who receives the Broadcast.
Schedule an appointment for a lead or customer.
Send an existing intake form.
Review a submitted form.
Request documents.
Mark a task complete.
Update a lead status.
Start a Campaign.
```

These workflows should normally take seconds to a few minutes.

Examples of acceptable client-facing time targets:

```text
Drafting a Broadcast message: about 5 minutes.
Selecting Broadcast recipients: about 1-2 minutes.
Scheduling an appointment for a person on a known day: about 30 seconds.
Sending an existing form: about 1 minute.
Reviewing a normal form submission: about 2-5 minutes.
Completing a task/status update: seconds to a few minutes.
```

A workflow that repeatedly exceeds 10-15 minutes should be rethought.

## Developer/operator work

Developer/operator work includes tasks that require system design, technical judgment, schema awareness, integration details, or significant setup time.

Examples:

```text
Build a form.
Design a Campaign.
Configure a FlowRoute.
Define document requirements.
Map vertical-specific form answers into domain records.
Build client-specific presets.
Wire integrations.
Configure message templates.
Decide module seams/schema.
Create reusable workflows for a client's specific business process.
```

This is work Engage Core should make efficient for the developer/operator, not work that every client should be expected to learn.

The barometer applies to developer/operator work too, but with a different goal. A task that would take a client hours should become a fast repeatable setup task for the developer/operator whenever it follows an existing pattern. For common cases, Engage Core should make this kind of setup possible in roughly 10-15 minutes by relying on templates, presets, reusable schemas, and clear module seams.

## Builder vs runtime split

Engage Core may include powerful underlying modules.

That does not mean every module needs a client-facing builder.

Preferred shape:

```text
Developer/operator-authored setup.
Client-facing simple runtime actions.
```

Examples:

```text
Forms:
    Developer/operator builds the form.
    Client sends/reviews the form.

Campaigns:
    Developer/operator designs the journey.
    Client starts, pauses, or reviews the journey.

FlowRoutes:
    Developer/operator configures automation/control flow.
    Client benefits from the route or performs a guided action.

Documents:
    Developer/operator defines requirements/checklists.
    Client requests, reviews, or tracks documents.
```

The same split applies to cross-module message delivery:

```text
Messaging template
    owns reusable copy and delivery-template metadata

Owning module
    owns whether, why, and when the message should exist

ResolvedMessageDispatchBuilder
    assembles the already-resolved module behavior with the reusable template

Messaging runtime
    owns generic gating, persistence, queueing, and provider delivery
```

Do not hide business workflow behavior inside reusable content templates merely because Messaging is the delivery capability. A universal builder may normalize a final runtime contract, but the module that owns the lifecycle must remain authoritative for its timing, conditions, sequencing, dependencies, enablement, and module-specific skip behavior.

## Subscription and service sprawl principle

Engage Core should reduce the number of external services a small business must pay for, integrate, and remember how to use.

It should especially avoid making clients pay monthly shelfware prices for capabilities they use occasionally.

Preferred product direction:

```text
Own stable contact/customer data inside Engage Core.
Use pay-per-send or usage-based provider costs where practical.
Avoid paying a marketing platform just to hold dormant contacts.
Avoid forcing the client to maintain 10 disconnected services.
Avoid making the client relearn complex SaaS tools for occasional workflows.
```

## Best-of-breed provider and middleware principle

Engage Core should not replace specialized external platforms merely because it can technically reproduce part of their behavior.

Prefer this split:

```text
Engage Core
    owns the client/customer experience, CRM-aware presentation, cross-module meaning,
    provider-neutral orchestration, and direct integration glue where custom behavior
    creates unique value

specialized external provider
    keeps the capabilities it performs better or more securely, such as payment
    processing, deep ecommerce/store operations, warehouse/shipping fulfillment,
    point-of-sale execution, or another provider-owned operational domain
```

A client may use several providers at once. Provider roles must be explicit and composable rather than assuming one vendor owns the whole business ecosystem.

Avoid introducing a separate middleware/integration SaaS account merely to synchronize providers when Engage Core can perform that bounded orchestration directly through provider-neutral contracts and installed provider packages.

Provider names used in implementation examples are illustrative. They must not become universal module dependencies, canonical schema vocabulary, or assumptions that every client uses the same provider combination.

## Module capability standard

A module capability is a good fit for Engage Core when it supports one of these outcomes:

```text
The client can perform a common business action quickly.
The developer/operator can encode a repeatable business process cleanly.
The system can automate or simplify work that would otherwise consume admin time.
The module reduces dependency on another expensive or disconnected service.
The capability composes cleanly with other modules through public seams.
```

A module capability is suspect when it primarily creates a new thing for the client to design, configure, maintain, or relearn.

## Module product surface standard

Feature modules have a product-surface mode independent from architectural ownership:

```text
loud
    owns a recognizable client/operator/public workflow

silent
    supplies supporting capability through another workflow, embedded context, or shared settings
```

A silent module may own substantial data and behavior. It should remain invisible as a standalone product unless a real operator workflow proves otherwise.

The consuming loud module owns the user experience. For example, Scheduling owns the address-collection, availability, and travel-result experience while a silent Location capability may normalize the address or supply geographic facts.

Explicit module enablement and dependency provider loading do not automatically create navigation. Top-level product surfaces must be deliberate.

See [`module-surfaces.md`](module-surfaces.md) for the current classification registry and module-definition template.

## Research findings are not product requirements

FOSS reviews, competitive audits, provider capability lists, and roomy foundation schemas are possibility inventories.

They may help identify durable shapes, but a feature becomes a requirement only when a concrete Engage Core workflow needs it, the owning module is clear, and the smallest useful contract passes the product barometer.

Do not build a client-facing builder, CRUD workspace, provider feature, or schema expansion merely because another product has one or an existing table could support it.

## Universal internal terminology

The universal internal person concept is `Contact`.

Internal keys, presets, events, triggers, task-template identifiers, route identifiers, config paths, registries, payload/context fields, and generic code concepts should use `contact`, not `lead`.

Client-facing UI and copy may use the configured business noun that fits the client or vertical, such as Lead, Customer, Fan, Borrower, Owner, or Member. That display language must not redefine the universal internal concept.

This split lets Engage Core feel natural in different industries without creating different internal standards for the same person record.

## Helpful automation discovery, not autonomous AI

Engage Core may quietly notice repeated meaningful manual work and suggest a simpler automated path.

The product rule is:

```text
Observe repetition.
Explain the pattern.
Suggest one clear next step.
Never act without permission.
```

Good:

```text
You've created this Task for 3 Contacts in Attempting Contact.
Add it to their Route so it happens automatically next time?
```

Avoid:

```text
Automation opportunity detected with confidence score 0.84.
```

The system should stay silent after one-off actions, avoid constant prompting, explain why a suggestion appeared, avoid suggesting behavior that is already automated, and never create Routes or change business behavior without explicit approval.

Evidence collection and suggestion eligibility are different concerns:

```text
Evidence
    may evolve as the product learns which explicit manual actions or selected domain events are useful to retain

Opportunity qualification
    should stay deterministic, generic, conservative, and protected from module/event-specific bloat

Suggestion presentation
    should remain sparse, specific, and current-context aware
```

An evidence-only occurrence does not deserve a prompt by itself.

The current generic qualification defaults are 3 occurrences across 3 distinct subjects within 30 days. Current compound correlations use a 10-minute causal window. These defaults are implementation facts, not permission to add every event/action as evidence.

When a repeated workflow cannot be completed automatically because required information is missing, prefer automating the collection of that information and continuing afterward.

Examples:

```text
missing appointment choice
    -> send booking invitation and wait

missing document
    -> send document request and wait

missing consent
    -> send opt-in invitation and wait

missing approval
    -> create an approval Task and wait
```

This is deterministic assistance intended to make the product feel easier and less intimidating. It is not a general AI agent, recommendation engine, confidence-scoring system, or behavioral-surveillance product.

Detailed architecture lives in `automation-opportunities.md`.

## UX standard

Client-facing UI should follow `ui-ux-guide.md`.

The product-level standard is simple:

```text
The client knows what they need to do.
The interface makes the next step obvious.
The task can be completed quickly.
The client does not have to learn a new software category.
The client never opens the CRM and thinks, “What did I get myself into?”
```

Client-facing UI should not make the client feel like they accidentally became a software administrator. Powerful modules should appear as simple summaries, guided actions, presets, and next-step prompts unless the user is intentionally in an operator/developer setup surface.


## CRM orientation surfaces

The dashboard and contact show page are orientation surfaces, not module inventories.

The dashboard should help the client decide where to start today. It should be config-driven by enabled modules and preset priorities, but the client should experience it as a calm summary of immediate work, recent movement, and safe next action.

The contact show page should feel like a person/customer workspace using the configured client-facing noun. Core owns the shell and generic contact details. Modules may contribute summaries, panels, tasks, messages, webinar history, campaigns, or automatic follow-up visibility, but those contributions should answer business questions instead of exposing module internals.

## Exploration before builders

For powerful setup surfaces, do not jump straight from architecture to a blank-canvas builder.

First decide:

```text
Which decisions belong to the client?
Which decisions belong to the operator/developer?
What should be selected from presets?
What consequences must be previewed before saving?
Which raw keys or route details are useful only as diagnostics?
```

Routes is the current example of this process producing a deliberate product boundary.

The selected direction is:

```text
Routes are linear.
Manage Routes explains what a Route does.
Assignments explains when it runs.
Normal authoring does not expose arbitrary branching, joins, nested branch trees, connectors, generic node-editor behavior, or arbitrary jump-back loops.
```

A blank-canvas builder is not client-ready merely because the underlying runtime supports many Point types.

The product direction is guided, preset-backed setup with focused editing and clear consequence/placement guardrails. Advanced runtime capability should remain internal unless a concrete product workflow proves it belongs in normal authoring.

Detailed language rules, reusable UI patterns, automation-warning patterns, and UI review checklists live in `docs/ui-ux-guide.md`.