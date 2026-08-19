# Development Testing Tools

This document is the registry and design guide for development-only runtime testing tools in Engage Core.

The goal is not to build alternate versions of production behavior. A testing tool should make difficult runtime scenarios fast to exercise while still calling the same actions, gates, persistence, and lifecycle logic used by the real application.

## Tool registry

| Tool | Owner | Environment | Purpose | Status |
| --- | --- | --- | --- | --- |
| Campaign Simulator | Campaigns + Messaging runtime | local only | Enroll a test Contact into a real Campaign, fake the clock, advance MessageChain progression, exercise ScheduledMessage delivery through the local dev sink, inspect results, and reset simulator-owned runtime records. | Reference implementation |
| Webinar dev/smoke controls | Webinars | guarded non-production development/smoke use | Exercise registration messages, joins, attendance/missed state, replay URLs, follow-ups, and reset scenarios. | Existing specialized tooling; keep production guard tests in place |

Add future testing tools to this table when they are introduced.

## Reference shape

The Campaign Simulator is the reference shape for new stateful testing tools.

### 1. Environment guard at more than one layer

A development tool must not rely on a hidden navigation link.

For new tools:

- interactive routes should not be registered in production;
- the controller/service must independently reject an unavailable environment;
- production-inaccessibility must have an automated test;
- `testing` may be accepted only so PHPUnit can exercise the guard and runtime contracts;
- the real interactive tool should be local-only unless a later tool has a documented reason to support another non-production environment.

Shared guard: `App\Support\TestingTools\TestingToolGuard`.

### 2. Scope fake time and always restore it

The application uses Laravel/Carbon `now()` extensively. Do not rewrite a module around a separate clock merely to make a simulator.

`App\Support\TestingTools\TestingToolRuntime` temporarily applies `Carbon::setTestNow()` for one tool operation and restores the previous clock in `finally`.

Rules:

- never leave a process-wide fake clock active after the operation;
- store the simulator's current fake time with the simulator-owned run so every request can reconstruct it;
- show the client timezone in the UI, but persist/compare runtime timestamps consistently in UTC;
- do not assume a fake clock in an HTTP request propagates into Horizon or another process.

### 3. Suppress asynchronous escape, then invoke the same runtime synchronously

Fake time cannot safely travel into a normal queue worker. Stateful fake-time tools therefore suppress queue dispatch inside the testing-tool scope and invoke the same underlying actions/jobs synchronously.

This is not permission to duplicate the runtime algorithm. The testing tool should call production actions/jobs such as the MessageChain processor and ScheduledMessage sender.

Shared runtime scope: `App\Support\TestingTools\TestingToolRuntime`.

### 4. Reserve a testing surface for persistent runtime records

A simulator may need real database rows so the developer can inspect progression between requests. Those rows must not later be picked up by ordinary schedulers or recovery jobs under real time.

Messaging reserves the `testing:` MessageChain enrollment surface prefix for testing tools. Normal background MessageChain due scanning, terminal-event outbox recovery, and stale ScheduledMessage delivery-claim recovery ignore those surfaces.

A testing tool may explicitly process them only while `TestingToolRuntime` is active.

Use a descriptive surface such as:

```text
testing:campaigns
```

Do not use `testing:` on real business activity.

### 5. Provider delivery must remain intercepted

A testing tool must not turn fake-time experimentation into provider traffic.

The Campaign Simulator only permits delivery processing in the local environment. Messaging's local email/SMS services write to `App\Modules\Messaging\Services\DevMessageSink` rather than Resend, Telnyx, or Twilio.

Future tools that can cause outbound delivery must prove an equivalent interception boundary. Do not treat a warning banner as a safety control.

### 6. Use real persistence and mark ownership clearly

When useful, create the same runtime rows production would create, then mark the top-level test run with explicit provenance.

The Campaign Simulator stores:

```text
meta.testing_tool.key
meta.testing_tool.run_id
meta.testing_tool.created_by_user_id
meta.testing_tool.started_at
meta.testing_tool.current_at
```

This gives the tool a durable ownership marker without adding testing-only columns to production tables.

Rules:

- never take over a real open runtime record and relabel it as a test;
- reject collisions with real open activity;
- use a unique run id;
- make reset/delete logic verify the testing marker and reserved surface before deleting anything.

### 7. Reset only simulator-owned data

A reset control is part of the tool, not an afterthought.

For Campaign simulation, reset removes the simulator-owned CampaignEnrollment, MessageChainEnrollment, ScheduledMessages, and dependent delivery/outbox rows. It does not delete the Campaign or Contact.

Future tools should document exactly what reset owns and what it must never delete.

Before Project State export or other durable state-transfer work, reset temporary simulator runs unless the export behavior has explicitly been designed to exclude them.

### 8. Inspect authoritative outcomes; do not invent diagnostics

Prefer persisted runtime authority:

- enrollment state;
- current step and next action;
- ScheduledMessage state;
- immutable terminal result/provider/reason;
- configured step/variant conditions and dependency policy.

If the runtime never materialized a message and did not persist a denial reason, display that honestly as `not materialized` rather than fabricating a skip/block explanation.

A future diagnostic seam can expose richer planning decisions if the product needs them.

## Campaign Simulator

### Entry point

Local CRM Campaigns workspace -> **Open simulator**.

### What it exercises

The simulator deliberately uses the real Campaign/MessageChain ownership path:

1. `EnrollContactInCampaignAction` creates the CampaignEnrollment wrapper and version-pinned MessageChainEnrollment.
2. The simulator changes only the MessageChain enrollment surface to `testing:campaigns` before the outer transaction commits.
3. Fake-time processing calls `ProcessMessageChainEnrollmentAction` synchronously.
4. Due ScheduledMessages are executed through `SendScheduledMessageJob` synchronously.
5. Local email/SMS delivery reaches `DevMessageSink`.
6. Messaging's durable terminal outbox/listener path still advances the MessageChain while the testing runtime scope is active.

### Controls

- choose an active Campaign with a selected MessageChain;
- choose an existing test Contact;
- set the fake start time;
- process work due at the current fake time;
- advance to the next runtime event;
- advance one hour or one day;
- choose a custom future time;
- inspect MessageChain step/variant configuration and actual ScheduledMessages;
- reset the run.

### Intentional limitations

- It does not fake inbound replies or FlowRoute/Task/Mortgage reactions. Those belong to their own modules and should be tested through shared automation seams in later tools/workflows.
- It does not claim that an unmaterialized variant was skipped. It shows the configured conditions/dependencies and the actual materialized result separately.
- A large time jump processes work at the chosen fake time; use **Advance to next event** when exact event-by-event timestamps matter.
- Contact selection currently shows the 250 most recent Contacts; this is a developer utility, not a general audience-selection UI.

## Checklist for a new testing tool

Before merging another development testing tool, answer all of these:

- What production runtime/action is being exercised?
- Can the tool avoid implementing a parallel fake engine?
- Which environment(s) are allowed, and are the routes plus runtime independently guarded?
- Does the tool need fake time? If so, is it scoped and restored?
- Can queued/scheduled/recovery work escape after the request ends?
- Does persistent testing state need a reserved background-inert surface or equivalent marker?
- Can it cause email, SMS, provider API calls, payments, external mutations, or webhooks? What hard interception prevents that?
- How is test-run provenance stored?
- How does the tool prevent collision with real open records?
- What exactly does reset delete?
- Which persisted fields are authoritative for the diagnostics shown?
- Is production inaccessibility covered by a test?
- Are background scheduler/recovery exclusions covered where applicable?
- Has the tool been added to the registry at the top of this document?

## Design rule

Testing tools should make the real system easier to drive and observe. They should not become a second implementation of the system.