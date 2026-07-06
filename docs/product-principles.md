# Engage Core Product Principles

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
Start a follow-up sequence.
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

## UX standard

Client-facing UI should follow `ui-ux-guide.md`.

The product-level standard is simple:

```text
The client knows what they need to do.
The interface makes the next step obvious.
The task can be completed quickly.
The client does not have to learn a new software category.
```

Detailed language rules, reusable UI patterns, automation-warning patterns, and UI review checklists belong in `ui-ux-guide.md`.
