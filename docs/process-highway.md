# Process Highway

Process Highway is a Core-owned, read-only composition surface for understanding configured business processes.

## Core contract

Process Highway is not a source of truth.

Owning features remain authoritative for their own process definitions and runtime state. Highway only composes module-owned read descriptions through:

```text
App\Support\ProcessHighway\Contracts\ProcessHighwayContributor
```

Contributors are registered under:

```text
process_highway.contributors
```

The shared `ProcessHighwayReadService` does not query Campaign, FlowRoute, Workflow, Relationship, Messaging, Task, Webinar, Scheduling, or other feature tables directly.

It:

1. resolves registered contributors;
2. collects their read-only process descriptions;
3. normalizes the shared presentation shape;
4. groups and sorts the descriptions;
5. passes the composed read model to the CRM surface.

A feature may be disabled without making Process Highway unavailable.

## Flow Routes contributor

FlowRoutes owns its Highway projection.

`FlowRoutesProcessHighwayContributor` reads current active Routes and presents:

- route identity;
- category;
- trigger summary;
- ordered active points;
- durable visible outcomes;
- authoritative Route editor link.

The presentation remains compatible with the original v1 questions:

```text
Starts when
Then
Can lead to
```

The important architectural change is that Core no longer knows the `flow_routes`, `flow_route_points`, or FlowRoute-specific presentation rules.

## Campaigns contributor

Campaigns owns its Highway projection.

`CampaignsProcessHighwayContributor` presents every non-archived Campaign with:

- active/off state;
- eligibility criteria;
- manual vs automatic enrollment;
- re-entry policy;
- behavior when eligibility becomes false;
- active enrollment count;
- message-journey summary;
- authoritative Campaign editor link.

Automatic Campaign entry is shown as the eligibility transition:

```text
not eligible -> eligible
```

Manual Campaigns are different. Their saved eligibility may describe the intended audience, but the Highway does not claim eligibility itself starts the Campaign. Their entry remains an explicit Campaign enrollment action.

This preserves the distinction between:

```text
Campaign eligibility
    who belongs in the Campaign

Campaign enrollment mode
    whether eligibility automatically starts the journey
```

## Campaign lifecycle presentation

For automatic Campaigns, Highway may summarize outcomes such as:

```text
Enroll when eligible
Pause if eligibility ends
Stop if eligibility ends
Keep running if eligibility ends
May re-enter after a new eligible cycle
```

Those phrases describe Campaign-owned lifecycle policy already enforced by the Campaign eligibility runtime. Highway does not execute any of those actions.

The message journey is summarized from Campaigns' existing workspace projection. Messaging remains authoritative for actual message content, consent, suppression, provider availability, destination checks, and delivery.

## Contributor boundary

Good:

```text
Campaigns -> shared ProcessHighwayContributor contract
FlowRoutes -> shared ProcessHighwayContributor contract
Process Highway -> compose contributor output
```

Bad:

```text
Core ProcessHighwayReadService -> campaigns table
Core ProcessHighwayReadService -> flow_routes table
Campaigns -> FlowRoutes
FlowRoutes -> Campaigns solely for Highway presentation
Process Highway -> mutate Campaign or Route state
```

This seam is intentionally reusable. Scheduling, Webinars, Tasks, Forms, Reporting, or future modules may contribute business-process descriptions later without changing the shared Highway reader.

## Presentation

Each contributed process may provide:

- source label;
- state;
- name and description;
- category/group;
- start condition;
- ordered steps;
- outcomes;
- compact details/metrics;
- authoritative edit link;
- machine-readable attributes for tests and future composed views.

Process Highway remains a practical business map rather than a second automation authoring system or a large graph editor.

## Not changed in Batch 4

- No Campaign or FlowRoute runtime behavior changes.
- No Slam Dunk client definitions change.
- No redundant Slam Dunk Flow Routes are removed yet.
- No Messaging consent/scope behavior changes.
- No migration is required.
- No new queue/job behavior is introduced.

## Remaining refactor roadmap

1. Slam Dunk migration: enable automatic Campaign eligibility, add durable webinar outcome eligibility, remove redundant Campaign-routing/cleanup Flow Routes, and retain true orchestration routes.
2. Messaging scope/consent cleanup: channel + purpose becomes the hard marketing permission boundary.
3. Preset/bootstrap hardening and portable stable-key Campaign JSON.
4. Final acceptance and Slam Dunk go-live.