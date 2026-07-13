# Engage Core Client-Readiness Roadmap

## Config generation lock-in checkpoint

The first hardening vertical slice is complete. Slam Dunk is frozen as a semantic golden fixture;
its effective package syncs into a fresh database and representative registration, waitlist,
post-event, status-change, campaign-enrollment, and message-resolution paths execute in tests.
Closed config contracts now cover module definitions, preset packages, contact statuses, tasks,
email, SMS, permission invitations, Campaign presets, FlowRoute presets, Webinar schedule profiles,
and Webinar post-event configuration. Token sources and producer contexts are registered from real
model columns and explicit computed providers.

The broad `test_everything` package has also completed sync with no unavailable capability
handlers, and `setup:validate` reports no findings. This proves the current checked-in setup is
internally coherent; it does not yet prove arbitrary future UI-generated configurations.

The next work is enforcement and generation: integrate closed contracts into the setup-validation
pipeline, close remaining file-envelope/reference/condition contracts, add strict export
validation and semantic round-trip tests, generate reference documentation from registries, then
build the authoring UX on those same registries. See
[Config Generation Lock-In Roadmap](config-generation-lock-in-roadmap.md).

This roadmap tracks the near-term implementation order for getting Engage Core ready for real client operation without treating the work as a limited or throwaway MVP.

The goal is not to build temporary shortcuts.

The goal is to finish durable, client-ready workflows in the right order while preserving the module boundaries, config shapes, consent rules, and product principles already established.

Use this file for implementation order and session planning.

Use `docs/TODO.md` for broader disposable backlog items, repeatable checklists, and lower-priority future work.

Use `docs/module-boundaries.md` and `docs/modules/*.md` for long-lived architecture and module ownership decisions.

Use `docs/ui-ux-guide.md` for client/operator-facing language, interaction patterns, and UI review rules.

## Guiding standard

Client-facing work should be real product work, not temporary MVP scaffolding.

A roadmap item is ready to implement when it can be built in a way that:

- does not knowingly introduce behavior that will need to be reverted shortly;
- follows the established module ownership boundaries;
- uses public actions, services, contracts, events, or registries instead of direct cross-module internals;
- respects Messaging consent, suppression, SMS opt-in, and permission-invitation rules;
- keeps client-facing workflows simple and action-oriented;
- improves client/operator readiness without expanding architecture for its own sake.

## Current focus

The current focus is the operational client path:

```text
imported/new contact
→ permission/consent state
→ Broadcast, Campaign, Webinar, or Route follow-up
→ visible CRM/operator state
→ safe client/operator action
```

The imported-contact onboarding, Broadcast visibility, dashboard, contact workspace, Webinars schedule/profile setup, and Campaign channel-variant foundations are now in place:

- Core owns first-class import batch records and CRM import-batch visibility.
- Broadcasts can target all imported contacts or selected import batches.
- Permission invitations are email-only one-time imported-contact invitations owned by Messaging.
- Normal Broadcasts are single-channel email or SMS sends and remain consent-gated through Messaging.
- SMS Broadcast authoring is available only when Messaging channel availability exposes SMS for the `broadcasts` surface.
- Broadcast scheduling records recipient outcomes and exposes scheduled/skipped/failed visibility on the Broadcast detail page.
- The CRM dashboard is config-driven by module slots, panel providers, and preset priorities.
- The contact show page is a Core-owned workspace shell with module-contributed panels/sections and muted module wayfinding.
- Webinars owns DB-backed schedule profiles/items for webinar lifecycle message timing.
- Campaigns owns DB-backed step variants for channel-specific delivery options.
- FlowRoutes runtime now uses subject-capable route progress, route instance plans, plan items, progress items, capability/binding schema, structured route-created artifact provenance, and backend guardrails for safe execution.
- The obsolete global FlowRoutes `Point` model/table/template layer has been removed. `FlowRoutePoint` now directly owns concrete point type/configuration, `FlowRoutePointType` owns the shared type vocabulary, and runtime plans/progress preserve `flow_route_point_id`, `flow_route_point_key`, `point_type`, capability identity, and definition/settings snapshots without `point_id`.
- Dev/client smoke testing exposed and the subsequent refactor corrected a cross-module message-behavior ownership flaw: reusable Messaging templates no longer carry lifecycle `timing`, `schedule`, `conditions`, or module-specific skip behavior for module-owned flows. Consuming modules are authoritative for lifecycle behavior, and `ResolvedMessageDispatchBuilder` / `ResolvedMessageDispatch` is the universal runtime assembly seam.

## Messaging, consent, and Rob Webinar production-prep completion

The current production-prep Messaging/Webinar slice is complete and green.

Completed ownership cleanup:

```text
Core Webinar Messaging
    small
    complete enough to work out of the box
    vertical-neutral
    7d / 24h / 30m / live reminder baseline
    one attended nurture step after 7 days
    one missed nurture step after 7 days

Slam Dunk client config
    retains rich branded mortgage copy
    retains richer 10d / 7d / 24h / 30m / 10m / live cadence
    retains richer attended/missed nurture strategy

Rob The Mortgage Coach client config
    client-owned mortgage preset package
    modern Messaging definitions
    10d / 7d / 24h / 1h / 10m / live cadence
    attended/missed transactional follow-ups next morning at 09:00 client local time
```

Durable hardening completed in the same slice:

```text
TokenContractRegistry
    executable token source/context authority

MessageTemplateTokenValidator
    reused by config/setup validation
    reused by MessageTemplatePreset sync
    reused by CRM template create/update validation

ConsentDomainRegistry
    separates message scope from consent identity
    exact mapping wins
    longest matching prefix wins
    ambiguity fails loudly
    unknown scopes remain narrow

ConsentOptInDefinitionResolver
    generic Messaging-owned acknowledgement copy
    human-readable module/domain consent topic
    optional module/client override
    no per-scope Webinar opt_ins definitions

MessageSendTimeResolver
    delay(minutes)
    anchored(minutes)
    next_day_at(time = HH:MM)
    next_day_at uses client timezone

Delayed-message conditions
    checked during planning
    persisted with ScheduledMessage metadata
    rechecked by ScheduledMessageGate before provider delivery
```

The immediate operational priority before importing Rob's 11 real production Webinar contacts is:

```text
1. Re-verify final consent-domain and opt-in acknowledgement behavior.
2. Confirm imported consent normalizes without MessageConsentGranted or opt-in sends.
3. Confirm Rob presets:sync and setup:validate are clean.
4. Review/finalize importer dry-run and --apply behavior.
5. Dry-run the real 11-contact CSV.
6. Apply only after exact output is verified.
7. Verify consent rows, registrations, future reminders, no confirmations/opt-ins, and idempotent rerun.
```

Phase 11 remains the broader product-roadmap phase, but this production migration checkpoint is the immediate client-operational priority.

## Architecture runway after staging smoke success

The staging smoke test confirmed the need for runtime-selectable definitions.

The architecture runway is:

```text
sync available options
select active options in CRM/admin
resolve selected DB-owned options at runtime
```

Completed architecture correction before deeper UX polish:

- Reusable Messaging templates are content/delivery-template definitions only for module-owned flows.
- Webinars, Campaigns, Broadcasts, FlowRoutes, Tasks, InternalNotifications, and future modules remain authoritative for their own timing, conditions, sequencing, dependencies, enablement, and module-specific skip behavior.
- `ResolvedMessageDispatchBuilder` combines selected reusable content with already-resolved module behavior.
- `ResolvedMessageDispatch` carries the exact `send_at` and normalized generic dispatch contract.
- `ScheduledMessage` supports polymorphic `behavior_owner` provenance without importing concrete feature modules into Messaging.
- Missing module-owned behavior does not silently fall back to hidden template timing or implicit immediate delivery. Dispatch requires either an exact caller-owned `sendAt` or explicit caller-owned behavior.
- Resolved lifecycle conditions are persisted into `ScheduledMessage.meta.conditions` and rechecked by `ScheduledMessageGate` immediately before provider delivery.
- Webinar scheduling supports `next_day_at(time = HH:MM)` using client timezone; post-event follow-ups can anchor to `webinar.ends_at` so delayed provider processing does not shift the intended next-morning date.

Completed runway pieces:

- FlowRoute owner morph and trigger bindings.
- CRM selection for status/event FlowRoutes.
- Messaging template presets, catalog entries, and DB-first assignment resolution foundation.
- Message Templates catalog/copy-editing UI using catalog entry grouping.
- Campaign Message Templates assignment UI and access links for selecting active campaign-step templates.
- Webinars message/template setup surface.
- DB-owned webinar schedule profiles and items.
- Core/client Webinar Messaging ownership cleanup with generic Core defaults and authoritative client list overrides.
- Messaging consent domains and generic/module/client consent acknowledgement resolution.
- Shared context-aware MessageTemplate token validation across config/setup, preset sync, and CRM editing.
- DB-owned campaign step variants and strategy-aware scheduling.
- FlowRoutes event-wait/task-completed resume behavior on top of route plan/progress item correlation.
- `task.completed` resume through the generic `AutomationEventRecorded` seam without broad contact-only fallback.

Remaining near-term candidates:

- Routes / FlowRoutes product completion: new Route creation, remaining authoring gaps, consequence previews, and contextual suggestions.
- Dashboard / contact workspace polish audit after the main runtime and route-management work settles.
- FOSS-informed module schema audit before production rollout.

Completed in the current client-readiness sequence:

- Config/setup validation across presets, route points, module capabilities, available fields/tokens, and templates.
- Permission invitation cancellation/skip/failure behavior.
- Webinar message readiness checks.
- Backend manual status-change automation impact preview.

The remaining runway pieces should continue to be implemented as durable client-readiness work, not as smoke-test shortcuts.

## Pre-prod schema-discovery ordering lens

Before production rollout, prioritize remaining client-readiness work by its likelihood of revealing durable table, migration, or module-boundary changes.

This is not a separate product track and not a reason to build temporary scaffolding. It is a temporary pre-production ordering lens for finishing real client-readiness work while branch migrations can still be replaced safely.

Use this priority order when choosing the next phase:

1. Likelihood of directly impacting DB/schema.
2. Likelihood of impacting codebase architecture, module seams, public actions/services, events, registries, or runtime resolvers.
3. UI/UX polish.

UI/UX polish can still reveal missing persisted concepts, such as acknowledgements, catalog records, saved preferences, setup-state records, or route instance adjustments. It is simply less likely to reveal schema changes than unresolved runtime-definition, schedule, variant, task-template, or route-instance behavior.

Current schema-discovery sequence:

| Phase | Item | Status | Primary discovery risk | Notes |
| -: | --- | --- | --- | --- |
| 1 | Webinar schedule profiles | Complete | DB/schema | DB-owned webinar schedule profiles/items exist. Series/webinars can select profiles with fallback. Webinars owns timing/slot identity. Messaging owns reusable copy/templates. Scheduled-message payloads stay compact; schedule/profile/template/debug identity belongs in meta. |
| 2 | Campaign channel variants | Complete | DB/schema | DB-owned campaign step variants exist. Campaign enrollment is lifecycle, campaign step is the business moment, and campaign step variant is the channel-specific delivery option. Variant strategy and dependency-aware scheduling are hardened. Scheduled-message payloads stay compact; campaign/step/variant/template/debug identity belongs in meta. |
| 3 | Task templates / task defaults | Complete | DB/schema | DB-owned reusable `task_templates` exist. FlowRoutes `create_task` points may reference stable task template keys and create live tasks through Tasks public actions. Tasks owns due offsets, assignment strategy, responsibility, and related-subject defaults. |
| 4A | FlowRoutes relationship, capability, and instance-plan audit | Complete | DB/schema + architecture | Audit confirmed that future subject-scoped route instance adjustment and durable capability discovery are guaranteed requirements before production. FlowRoutes should harden schema now instead of relying on meta-heavy execution/correlation. |
| 4B | FlowRoutes schema hardening | Complete | DB/schema + architecture | Subject-scoped route progress, contact route plans, plan items, progress/execution items, capability catalog/bindings, uniform route-created artifact provenance, blocked/cancelled runtime handling, provenance/debug consistency, and boundary guardrails are in place. |
| 5 | FlowRoutes event-wait / task-completed resume implementation | Complete | Runtime | Resume from neutral `task.completed` automation events now uses the Phase 4B route progress/plan/progress-item foundation and created Task identity rather than broad contact-only waits. Direct resume and real CompleteTaskAction → TaskCompleted → AutomationEventRecorded → FlowRoutes listener behavior are covered. |
| 6 | Config validation / setup validation | Complete | Architecture + safety | 6A–6E are complete. Engage Core now has a shared `SetupValidationManager`, structured findings/results, module-owned contributors, app-level dependency and registry-drift contributors, the non-mutating `setup:validate` CLI, focused validation coverage, adjacent regression coverage, and broader client/default-preset fallback coverage. |
| 7 | Permission invitation accepted automation event | Complete | Architecture | Accepted invitations emit neutral `permission_invitation.accepted` events after transactional acceptance, with row locking/idempotency and no Messaging dependency on consumers. |
| 8 | Permission invitation cancellation / skip / failure bookkeeping | Complete | DB/schema + architecture | No schema change was needed. Pre-claim skips/cancellation create no invitation row; post-claim scheduled-message skips reconcile matching claimed invitations to failed; provider failures remain failed across Messaging and invitation state; failed invitations stay one-time-blocking. |
| 9 | Webinar message readiness check | Complete | Architecture + operator safety | Computed Webinars-owned readiness now evaluates runtime Messaging resolution, channel availability, active schedule-profile effects, intentional disablement, registration/waitlist consent acknowledgement readiness, and post-event outcome-message enablement without persisting readiness state. |
| 10 | Manual status-change automation warning foundation | Complete | Operator safety + architecture | Backend-only consequence preview is implemented through a read-only FlowRoutes-owned impact resolver. It reports whether selected status-based FlowRoutes would run and which routes are selected without starting route progress. The actual operator warning UX is deferred to Phase 11. |
| 11 | Automation opportunity foundation + Routes / FlowRoutes UX | In progress; backend, preset architecture, and first Route authoring baseline complete | DB/schema + architecture + UI/UX | Automation Opportunities, global Point collapse, module-first preset contributions, Manage Routes/Assignments IA, modal Route/Point editing, drag ordering, explicit Route message eligibility, linear product boundaries, and Point placement policy are complete. Remaining work is new Route creation and focused authoring/discovery UX gaps. |
| 12 | Dashboard / contact workspace polish audit | Planned | UI/UX + possible schema | Review orientation surfaces after core runtime pieces settle. Add persisted preferences/acknowledgements only when needed. |
| 13 | FOSS-informed module schema audit | Planned | DB/schema audit | Compare Engage Core modules against mature FOSS patterns to identify likely missing persisted concepts before production. Pull FlowRoutes-specific FOSS/OSS pattern review earlier into Phase 4 if helpful. |

Run each phase on its own branch when practical. At the end of each phase, bring this context forward into the next branch:

```text
what changed
schema decision made
files touched
tests added/updated
what was intentionally deferred
new docs/TODO changes
follow-up risks for later phases
```

Deferred launch hardening:

- DB Snapshot / Export Safety Tool.

Do not build DB snapshot/export tooling during active pre-production schema discovery unless real data preservation becomes necessary. While the product is not yet in production, schema-heavy phases may replace branch migrations and reset local/staging data as needed. Add command-line SQL/JSONL snapshot tooling when schemas are stabilizing and production rollout is near.


## Phase 11 automation opportunity foundation and Routes product work

Phase 11 expanded after the Route Management UX audit revealed durable architecture and product questions that needed to be settled before deeper polish.

The Automation Opportunities backend foundation is complete for the current slice.

Implemented architecture:

```text
automation_behavior_occurrences
    append-style compact behavior/correlation evidence

automation_opportunities
    aggregate lifecycle for repeated evaluated patterns and suggestion eligibility

app/Support/AutomationOpportunities
    shared infrastructure; not FlowRoutes-owned

producer module
    owns semantic fingerprint inputs and explicit manual/evidence seams

shared infrastructure
    normalizes/hashes fingerprints
    persists occurrences
    records evidence-only rows without evaluation when requested
    aggregates opportunities
    applies generic count/distinct-subject/window qualification

FlowRoutes
    remains the owner of accepted automation/control-flow execution
```

Current generic evaluator defaults:

```text
minimum occurrences = 3
minimum distinct subjects = 3
observation window = 30 days
```

Current implemented compound correlation window:

```text
10 minutes
```

Current evaluated action keys:

```text
task.created_manually
task.created_after_manual_status_change
contact.status_changed_after_manual_task_completion
task.created_after_automation_event
```

Current evidence-only action keys:

```text
task.completed_manually
automation_event.recorded
```

Current selected neutral event evidence keys:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

The evidence allowlist may change as real usefulness becomes clearer. Adding an event to evidence collection does not mean it deserves a standalone opportunity or user-facing prompt.

A plain repeated manual Contact status change is not currently an opportunity producer.

Manual smoke validation passed for:

```text
3 equivalent manual Tasks across 3 Contacts
    -> eligible

manual status change -> same manual Task across 3 Contacts
    -> eligible

manual Task completion -> same manual status change across 3 Contacts
    -> eligible

supported automation event -> same manual Task across 3 Contacts
    -> evidence remains evidence only
    -> compound opportunity becomes eligible

unsupported automation event
    -> ignored

supported event outside 10-minute window
    -> evidence retained
    -> no compound event->Task occurrence

same manual behavior repeated 3 times on 1 Contact
    -> distinct_subject_count = 1
    -> remains observing

system-created Task
    -> no manual behavior occurrence
```

The generic evaluator should remain protected from module/event-specific branching. Future suggestion-time checks such as capability availability, equivalent existing automation, snooze/dismissal state, conversion state, context validity, or attribution ambiguity should be added only where the first real suggestion surface needs them.

Phase 11 also completed the Route Management architecture simplification:

```text
Removed:
    global Point model/table/template layer
    point_id from FlowRoutePoint, plan items, and progress items
    Point.default_definition/default_settings merge layer

Retained:
    FlowRoute
    FlowRoutePoint as the concrete Route-owned action/wait/condition
    FlowRouteCapability
    FlowRoutePointType
    flow_route_point_id / flow_route_point_key / point_type provenance
```

The module-first preset contribution architecture is implemented:

```text
PresetContributionRegistry
PresetPackageResolver
PresetCompositionResolver
ResolvedPresetDomain
explicit module preset contributors
module-first preset files
```

The selected architecture keeps separate:

```text
module availability
preset contribution availability
client package selection
runtime activation/binding
```

The current Routes product direction is now locked:

```text
Routes
    Manage Routes
    Assignments

Routes are linear.
No arbitrary branching canvas.
No joins.
No nested branch trees.
No generic node editor.
No arbitrary jump-back loops.
```

Implemented Manage Routes baseline:

```text
business-language Route cards
assigned/not-assigned state
expandable Route flow
one-step Automatic Behavior separated from multi-step Routes
Route editing in a modal
Point editing in a modal
visible Remove action
drag-and-drop ordering with explicit Save order
move up/down fallback controls
module-tone wayfinding
contextual Stop Campaign availability
explicit direct-Route Messaging template eligibility
```

Current authorable Point types:

```text
Wait
Change contact status
Create task
Send message
Start Campaign
Stop Campaign
```

Current placement policy:

```text
Wait cannot be terminal.
Change Status must be terminal.
The backend validates the proposed resulting sequence on add, remove, move, and reorder.
The UI mirrors those rules with disabled controls, missing fake drag affordances, tooltips, and local invalid-drop feedback.
```

The direct Route message picker is explicit opt-in only:

```text
MessageTemplatePreset.meta.route_authoring.eligible = true

or

active MessageTemplateCatalogEntry.meta.route_authoring.eligible = true
```

Internal-purpose templates remain ineligible even with opt-in.

Remaining Phase 11 work:

```text
new Route creation
Route duplication
activate/deactivate
trigger changes
clone Point from another Route
task assignment/default authoring in Route UX
business-day/business-hour wait authoring
simple future Point eligibility / Route continuation rules
manual status-change consequence warning UX
contextual Automation Opportunity suggestion UX
```

Do not build clickstream tracking, generic AI recommendations, confidence scores, autonomous Route creation, a recommendation feed, or arbitrary branching.

Detailed Automation Opportunities contract: `docs/automation-opportunities.md`.

Detailed FlowRoutes contract: `docs/modules/flow-routes.md`.

## Phase 4B FlowRoutes schema hardening completion

Phase 4B is complete for backend/schema readiness. Engage Core now has the durable FlowRoutes foundation needed before Phase 5 task-completed/event-wait resume work.

Phase 4B implemented backend schema and runtime foundation, not polished Route Management UX.

Durable implemented concepts:

```text
FlowRoute / FlowRoutePoint
    Reusable Route revision plus concrete Route-owned action/wait/condition definitions.

ContactFlowRouteProgress
    Live route instance for one contact and optional subject.

ContactFlowRoutePlan
    Instance-specific route plan seeded from the reusable route template.

ContactFlowRoutePlanItem
    Ordered plan item for that route instance. May originate from template, manual insertion, automation, vertical preset, or later operator adjustment.

ContactFlowRouteProgressItem
    Execution attempt/result for a plan item. Owns waiting/resume/correlation/result state.

FlowRouteCapability / FlowRouteCapabilityBinding
    Durable catalog and client/context availability records for actions, waits, conditions, events, labels, input schema, output context, and supported subject types.
```

Uniform route-created artifact provenance should be first-class on module-owned artifacts when FlowRoutes creates them:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
```

This shape applies to Tasks, ScheduledMessages, and CampaignEnrollments. Future modules such as Scheduling, Documents, Forms, Portal, Commerce, Mortgage, PetServices, and Music should follow the same integration process rather than adding bespoke FlowRoutes correlation metadata.

Phase 5 task-completed resume should depend on this foundation and resume specific route progress/plan/progress items from neutral `task.completed` automation events.

## Near-term sequencing rule

Client-readiness implementation should chase one main item at a time unless two active threads are intentionally isolated by file ownership.

The current sequence is the pre-prod schema-discovery sequence above.

Phases 7 and 8 permission-invitation hardening are complete. Accepted invitations emit a neutral automation event exactly once, and cancellation/skip/failure bookkeeping now has explicit lifecycle semantics across Messaging invitations, scheduled messages, and Broadcast bookkeeping. The first Route Management editor baseline is now implemented; CRM provenance/debug views and contextual suggestion UX remain deferred.

Do not run parallel threads that modify the same controllers, views, routes, services, migrations, or tests unless one thread is paused and rebased onto the other.

## Working roadmap

Use the pre-prod schema-discovery sequence as the current implementation order.

| # | Planned item | Rough estimate | Notes |
| -: | --- | ---: | --- |
| 1 | Webinar schedule profiles | Complete | DB-owned `webinar_schedule_profiles` and `webinar_schedule_profile_items` are the durable schedule-selection path. Series and webinars may select profiles; existing scheduled messages remain stable. |
| 2 | Campaign channel variants | Complete | DB-owned `campaign_step_variants` are the durable channel-coordination path. Steps own business moment and strategy; variants own channel/purpose/scope/dispatch references and do not own copy. |
| 3 | Task templates / task defaults | Complete | DB-owned task templates support generated/manual task defaults. FlowRoutes can reference task template keys and must create live tasks through Tasks public actions. Task template UI remains deferred until needed. |
| 4A | FlowRoutes relationship, capability, and instance-plan audit | Complete | Audit confirmed schema should support subject-scoped route instances, instance plans, plan items, progress/execution items, capability catalog/bindings, and uniform route-created artifact provenance before production. |
| 4B | FlowRoutes schema hardening | Complete | FlowRoutes now has subject-capable progress, route instance plans, plan items, progress/execution items, capability/binding schema, uniform provenance, blocked/cancelled handling, and backend guardrails. Polished Route Management UX remains deferred. |
| 5 | FlowRoutes event-wait / task-completed resume implementation | Complete | Resume from neutral `task.completed` automation events now uses the Phase 4B route progress/plan/progress-item foundation and created Task identity rather than broad contact-only waits. Direct resume and real CompleteTaskAction → TaskCompleted → AutomationEventRecorded → FlowRoutes listener behavior are covered. |
| 6 | Config validation / setup validation | Complete | Shared contributor-based validation is implemented across Core, Tasks, Messaging, Webinars, Campaigns, FlowRoutes, module dependencies, and reference-registry drift. `setup:validate` fails on errors, succeeds on warnings-only/clean results, and does not mutate state. Focused and broader regression coverage are green. |
| 7 | Permission invitation accepted automation event | Complete | Accepted invitations emit one neutral `permission_invitation.accepted` event after transactional acceptance. The invitation row is locked/rechecked for idempotency, submitted SMS phone updates occur inside the same transaction, and Messaging remains independent from consumers. |
| 8 | Permission invitation cancellation behavior | Complete | Pre-claim skips/cancellations create no invitation row; post-claim skips reconcile claimed invitations to failed; provider failures remain failed; no new invitation statuses or schema changes were required. |
| 9 | Webinar message readiness check | Complete | Computed readiness is available for registration confirmations, registration consent acknowledgement readiness, reminders, waitlist alerts, waitlist consent acknowledgement readiness, and post-event follow-ups. Readiness is derived from runtime resolution, channel availability, active schedule-profile effects, and post-event enablement; no readiness state is persisted. |
| 10 | Manual status-change automation warning foundation | Complete | FlowRoutes exposes a read-only `ContactStatusAutomationImpactResolver` and plural status-trigger route resolution. No schema, controller, or Blade changes were required. The actual warning interaction belongs to Phase 11 UX work. |
| 11 | Automation opportunity foundation + Routes / FlowRoutes UX | In progress; first authoring baseline complete | Shared opportunity persistence/evaluation and the current Routes management/editor baseline are complete. Next: new Route creation and the remaining focused authoring/discovery UX gaps. |
| 12 | Dashboard / contact workspace polish audit | 1–2 sessions | Review shared orientation surfaces after runtime behavior settles. Add persisted state only for proven needs such as acknowledgements or preferences. |
| 13 | FOSS-informed module schema audit | 2–6 sessions, split by module group | Compare Engage Core module tables against mature FOSS patterns to catch likely missing persisted concepts before production. |
| Deferred | DB Snapshot / Export Safety Tool | 0.5–1.5 sessions near launch | Command-line SQL/JSONL snapshot tooling for production/launch hardening. Do not build during active pre-prod schema discovery unless real data preservation becomes necessary. |
| Ongoing | Feature-specific docs as modules stabilize | Ongoing | Keep module docs current when architecture/operator behavior changes. Do not turn docs into speculative backlog. |
| Later | Client self-serve readiness audit | 0.5–1 session | Separate controlled beta/operator-assisted readiness from true client self-serve readiness. |
| Later | PetServices vertical planning | 0.5–1 session | Plan vertical-owned pet/service concepts without pushing domain fields into Core. |
| Later | Music vertical planning | 0.5–1 session | Plan vertical-owned music/fan/product-interest concepts using universal modules as needed. |

## Recently completed client-readiness items

These items are no longer the recommended next implementation target, but they explain the current baseline.

### CRM dashboard and contact workspace orientation

Completed baseline:

- Dashboard panel selection is config-driven through slots and preset overrides.
- Enabled modules contribute dashboard panels through provider seams.
- Disabled modules do not appear just because their tables, providers, or config entries exist.
- Immediate work panels can show calm caught-up empty states.
- Passive context panels hide when empty.
- Module tones provide muted wayfinding for panels, cards, badges, and jumps.
- Contact show remains a Core-owned shell.
- Modules contribute contact show data/panels through Core registries.
- Contact show leads with the next action and uses module labels/tones without turning into a cockpit.

### Import-time status mapping

Completed baseline:

- Operators can map imported legacy/client status values to active Core `ContactStatus` records during import.
- Original imported status values are preserved in contact metadata.
- Unmapped statuses are flagged for review instead of silently assigning the wrong Engage Core status.
- Missing status values are tracked separately from unmapped values.

### Import batch permission invitation scheduling

Completed baseline:

- Import batch detail pages expose a Messaging-owned permission invitation action when Messaging is enabled.
- Messaging schedules eligible imported-contact permission invitation messages for the selected batch.
- Repeat scheduling is prevented when a pending/sent invitation message or existing imported-contact invitation row already exists.
- The page shows current-page permission invitation status visibility for imported contacts.

### Import batch management visibility

Completed baseline:

- Core owns `contact_import_batches` as first-class import bookkeeping.
- CRM has simple import batch list/detail visibility.
- Broadcasts can link selected import-batch recipient filters back to Core import-batch detail pages.
- Broadcasts consume Core import-batch records but do not own import-batch management.

### SMS Broadcast authoring and channel availability

Completed baseline:

- Messaging owns channel availability.
- SMS capability can exist in code while SMS remains hidden from client/admin UI surfaces.
- Broadcast authoring asks Messaging channel availability which regular Broadcast channels are visible for the `broadcasts` surface.
- Regular Broadcasts are single-channel sends.
- Email Broadcasts use `payload.subject` and `payload.body`.
- SMS Broadcasts use `payload.message`.
- Permission invitations remain email-only even if SMS is visible elsewhere.
- SMS Broadcasts schedule through Messaging public actions and hydrate scheduled payloads with recipient phone, channel, purpose, scope, and message type.
- Contacts without a usable SMS destination are skipped by Messaging scheduling rather than crashing delivery.
- Broadcast recipient outcome visibility shows scheduled/skipped/failed counts and recipient skip/failure reasons.

### Messaging template presets, catalog entries, and assignments

Completed baseline:

- Messaging owns DB-backed reusable `MessageTemplatePreset` records for synced/editable message copy.
- Messaging owns `MessageTemplateCatalogEntry` records for template browser organization by channel, purpose, module/area, group, and item.
- Messaging owns `MessageTemplatePresetAssignment` records for selected runtime template behavior.
- Template sync creates presets, catalog entries, and default assignments from message configs.
- Normal sync preserves customized template copy and selected assignments unless forced.
- Runtime resolution can prefer selected DB templates before config fallback.
- The Message Templates page edits/reviews copy, groups catalog entries by channel/purpose/module/group/message, and shows read-only usage.
- Campaign Message Templates is the Campaign-side setup surface for selecting the active template for each campaign step.
- Message Templates “Used by” campaign rows may link to Campaign Message Templates, and Campaign Message Templates may link back to Message Templates for copy editing.
- Selecting which template a Webinar or Automatic Follow-up uses still belongs on that consuming module's setup screen.

### Webinar schedule profiles

Completed baseline:

- Webinars owns DB-backed `WebinarScheduleProfile` and `WebinarScheduleProfileItem` records.
- Schedule profiles decide when webinar lifecycle messages are sent; Messaging template presets decide what those messages say.
- Profiles can be selected at the webinar series or webinar level, with an active default fallback.
- Generic schedule resolution supports `delay(minutes)`, `anchored(minutes)`, and `next_day_at(time = HH:MM)`.
- `next_day_at` uses client timezone; Webinar post-event follow-ups may anchor to `webinar.ends_at`.
- Resolved lifecycle conditions persist into ScheduledMessage metadata and are rechecked immediately before provider delivery.
- Schedule profile items reference stable runtime dimensions such as channel, purpose, scope, surface, message type, dispatch key, and `message_template_key`. `source_config_path` is provenance/debug location only.
- Multiple reminder slots may share `message_type = reminder`; the schedule profile item key owns the Webinar lifecycle slot, while `message_template_key` identifies the reusable Messaging template. `source_config_path` is provenance only.
- Existing scheduled messages are not rewritten retroactively when a profile or template assignment changes.
- Webinar dispatch payloads store compact token/context data. Schedule profile identity belongs in scheduled-message metadata, not as a hydrated profile/items object graph inside `scheduled_messages.payload`.
- Tests include payload-shape checks so full Eloquent relationship graphs do not leak into scheduled-message payloads.

### Webinars message/template setup and readiness

Completed functional baseline:

- Webinars has a Webinars-owned message setup surface for registration confirmations, registration consent acknowledgement/readiness, reminders, waitlist availability messages, waitlist consent acknowledgement/readiness, post-attended transactional follow-ups, and post-missed transactional follow-ups.
- The surface shows the currently selected Messaging template per webinar message context.
- Operators can choose compatible `MessageTemplatePreset` records from the Webinars setup surface.
- Selection saves through `MessageTemplatePresetAssignment` while copy editing remains on the Message Templates page.
- Runtime resolution remains DB-first with config fallback.
- Computed readiness exposes `Ready`, `Needs attention`, and `Optional / disabled` states without persisting readiness records.
- Readiness considers Messaging runtime definition resolution, channel visibility, active schedule profiles actually in use, explicit profile disablement, missing/inactive selected profile references, conflicting active defaults, and post-event outcome-message enablement.
- Registration and waitlist consent acknowledgement readiness is included when the corresponding messaging surface has an available channel.
- Consent acknowledgement contexts are Messaging-owned consent-domain messages resolved through `ConsentOptInDefinitionResolver`; they are not per-scope Webinar `opt_ins` templates and are not forced into Webinar schedule-profile items.
- This is a functional setup/readiness UI, not the final UX-polished communication-plan experience.

### Campaign channel variants

Completed baseline:

- Campaigns owns DB-backed `CampaignStepVariant` records.
- Campaign enrollment remains the lifecycle.
- Campaign step is the business moment.
- Campaign step variant is the channel-specific delivery option for that moment.
- `campaign_steps.variant_strategy` controls `first_available`, `send_all_eligible`, or `dependency_aware` behavior.
- `send_all_eligible` schedules multiple eligible variants.
- `dependency_aware` can require sibling variants from the same campaign enrollment and same campaign step to reach explicit states before scheduling.
- Supported dependency states include scheduled, pending, sent, skipped, failed, and terminal.
- Dependency checks consider both same-pass scheduled siblings and persisted ScheduledMessage records.
- Dependency checks are scoped to the same campaign enrollment, same campaign step, and required variant key.
- Preset sync creates variants, removes stale non-customized variants, preserves customized stale variants, and protects customized campaigns.
- Variants reference Messaging-owned templates/assignments through channel, purpose, scope, campaign key, step number, and optional variant context.
- Variants do not own reusable subject/body/message copy.
- Existing single-channel campaign step configs sync into a default variant for compatibility while the DB-owned variant model becomes the durable runtime shape.
- Campaign-generated scheduled-message payloads remain compact; campaign/step/variant/template/debug identity belongs in `scheduled_messages.meta`.

### FlowRoute trigger bindings and CRM selection UI

Completed baseline:

- FlowRoute owner fields exist for operational ownership and grouping.
- `FlowRoute.is_active` means available/allowed, not selected by itself.
- Runtime route selection is owned by DB-backed `FlowRouteTriggerBinding` records.
- Preset sync creates default selected bindings for route definitions that should be active by default.
- CRM exposes a simple Route Bindings page for selecting status/event route behavior.
- Contact-status triggers are treated as one selected route per status in the current CRM UI.
- Automation-event triggers may select multiple routes per event so one producer event can run independent selected routes.
- Webinar outcome events such as `webinar.attended` and `webinar.missed` can separately change contact status and enroll Campaigns without Webinars importing FlowRoutes or Campaigns.
- Manual contact-status changes should receive an operator-facing warning when they will run selected status-based automation. That warning is a UI/awareness guardrail, not a new ContactStatus manual/automation-only schema split.

### Webinar dev/staging testing tools

Completed baseline:

- Webinars has a local/staging-only dev controller for testing confirmations, reminders, join-click behavior, attendance outcomes, replay URLs, and post-event follow-ups.
- CRM exposes a reusable dev-testing modal pattern.
- Dev modal actions are AJAX-driven so operators/developers do not lose modal state, selected registrations, loaded message options, or activity logs between actions.
- Dev message sends go through Messaging public actions instead of directly creating ScheduledMessage rows.
- Simulated join clicks use the normal Webinars join resolver and skip already-queued live reminders when configured.
- Manual dev sends remain forced sends for payload testing.

## Phase 6C schema/model audit completion

Phase 6C is complete.

The final audit established these durable contracts:

```text
ContactStatus
    DB-owned customization fields and force semantics.

TaskTemplate / Task
    first-class defaults and precedence;
    nullable task_template_id foreign key with null-on-delete;
    durable task_template_key identity;
    template-backed task creation preserves FlowRoutes provenance.

FlowRoutes
    capability contributor/catalog source of truth;
    durable logical route key + version revisions;
    is_current_version semantics;
    live active/waiting instance reconciliation by durable point key;
    route-plan revision history and hard reconciliation conflicts.

Campaigns
    CampaignEnrollment is lifecycle, not delivery identity;
    CampaignStepVariant owns authoritative delivery/template context;
    customized structures are preserved;
    no force mode.

Messaging
    reusable template, assignment, and catalog ownership remain separate;
    list-based definitions require stable explicit keys;
    stale config-owned non-customized presets are removed;
    customized/manual presets are preserved.

Webinars
    schedule profiles/items are DB-owned and customizable;
    force overwrite is supported;
    stale non-customized items deactivate;
    stale customized items are preserved;
    only one active default is valid.
```

Final verification order completed successfully:

```text
fresh migration/schema checks
focused sync/durability tests
adjacent module/runtime boundary tests
broader end-phase sweep
```

No additional schema additions were required for Phase 6D or 6E.

## Phase 6 validation completion

Phase 6 is complete.

Implemented architecture:

```text
SetupValidationManager
    -> tagged setup.validation_contributors
        -> app/module dependency validation
        -> reference-registry drift validation
        -> Core / ContactStatus validation
        -> Tasks / TaskTemplate validation
        -> Messaging config + DB-owned template/assignment validation
        -> Webinars schedule-profile validation
        -> Campaign preset/variant/dependency/runtime validation
        -> FlowRoutes preset/capability/handler/runtime validation
    -> SetupValidationResult
        -> errors
        -> warnings
        -> deterministic ordering
```

The CLI entry point is:

```bash
php artisan setup:validate
```

Behavior:

- errors return failure and block staging/client handoff;
- warnings remain non-blocking and actionable;
- clean results return success;
- validation does not mutate DB state or auto-fix configuration;
- findings are not persisted by default.

Phase 6 also established these durable rules:

- Owning modules validate their own private config/runtime truth.
- App-level contributors validate cross-cutting module dependency and reference-registry drift concerns.
- Selected/active unsafe behavior is a hard error.
- Safe dormant/stale/unused behavior may be a warning.
- Reference registries are validated for drift but are not treated as sole executable truth.
- Explicit module visibility remains distinct from dependency-loaded provider availability.
- Future vertical modules should register their own contributors when they own real selected/executable reference contracts.
- Future authoring/readiness UI should reuse the same registries, resolvers, availability checks, and validation seams so impossible choices are prevented before save; server-side validation and setup validation remain backstops.

Verification completed successfully:

```text
focused setup-validation tests
adjacent module/runtime regression tests
broader client/default-preset fallback coverage
```

The polished available-field/token picker remains future authoring UX work, but the executable source-of-truth/validation seam is now implemented through `TokenContractRegistry` and `MessageTemplateTokenValidator`. Config/setup validation, MessageTemplatePreset sync, and CRM template editing reuse that context-aware validator. Future picker UX should consume the same registry rather than invent a parallel field list.

## Phase 7 permission invitation accepted automation event completion

Phase 7 is complete.

Durable behavior:

```text
Contact accepts imported-contact permission invitation
→ Messaging locks/rechecks the invitation inside a transaction
→ optional submitted SMS phone update is saved in the same transaction
→ configured MessageConsent rows are created/updated
→ invitation is marked accepted
→ transaction succeeds
→ Messaging emits permission_invitation.accepted once
```

The event is contact-scoped, uses `ContactPermissionInvitation` as subject, includes accepted channels and consent scopes, and keeps Messaging independent from downstream consumers.

No schema change was required.

## Phase 9 Webinar message readiness completion

Phase 9 is complete.

Webinars now owns a computed readiness layer for these message areas:

```text
registration confirmations
registration consent acknowledgement/readiness
reminders
waitlist availability messages
waitlist consent acknowledgement/readiness
post-attended transactional follow-up
post-missed transactional follow-up
```

Readiness is computed from current runtime truth rather than persisted as setup state.

The readiness service considers:

```text
Messaging DB-first definition resolution with config fallback
channel availability for the owning surface/purpose/scope
active Webinar schedule profiles actually in use
explicit schedule-profile disablement
missing or inactive selected profile references
conflicting active default schedule profiles
post-event outcome-message enablement
```

The operator-facing states are:

```text
Ready
Needs attention
Optional / disabled
```

No schema change was required.

## Phase 10 manual status-change automation impact completion

Phase 10 is complete for the backend foundation.

FlowRoutes now exposes:

```text
FlowRouteTriggerBindingResolver::selectedFlowRoutesForContactStatus(...)
ContactStatusAutomationImpactResolver::forContactStatus(...)
```

The impact resolver is read-only. It reports:

```text
has_automation
status_id
status_key
status_name
route_count
routes[] with id/key/name
```

It ignores inactive bindings and inactive routes and does not start `ContactFlowRouteProgress`.

No schema, `ContactController`, Workflow runtime, or Blade changes were required.

The actual operator warning/confirmation UX before a manual status change remains intentionally deferred to Phase 11.

## Cross-module resolved message dispatch architecture correction

Status: **Complete.**

This correction was discovered through real dev/client configuration and smoke testing, which showed that Webinar lifecycle timing could be duplicated between reusable Messaging templates and Webinar schedule-profile items. The duplicate source of truth allowed stale template behavior to survive and silently override intended client behavior.

The completed durable architecture is:

```text
Reusable Messaging template
    owns copy and delivery-template metadata only

Owning module
    owns whether/why/when the message exists and its lifecycle behavior

ResolvedMessageDispatchBuilder
    combines selected content-only template data with caller-resolved behavior

ResolvedMessageDispatch
    carries exact send_at, optional behavior-owner provenance, and stable occurrence identity

Messaging
    owns delivery safety, ScheduledMessage persistence, queueing, and providers
```

Completed behavior:

- `ResolvedMessageDispatch` and `ResolvedMessageDispatchBuilder` are implemented and are the generic runtime assembly seam.
- Reusable Messaging templates are rejected when they attempt to own `timing`, `schedule`, `conditions`, or module-specific skip behavior.
- Every resolved dispatch must provide either an exact caller-owned `sendAt` or explicit caller-owned `behavior`; there is no implicit immediate fallback.
- `ScheduledMessage` persists optional polymorphic `behavior_owner_type` / `behavior_owner_id` provenance without Messaging importing concrete feature-module models.
- Stable module-owned `occurrenceKey` identity is carried through resolved dispatch and dedupe so retries of the same logical occurrence remain the same occurrence even if `send_at` changes.
- Webinars resolves behavior from `WebinarScheduleProfileItem`. Schedule-profile items match reusable Messaging templates through stable `message_template_key`; `source_config_path` remains provenance only.
- Webinar schedule-profile resolution contributes transient `resolved_behavior` and `behavior_owner` data that `DispatchMessageAction` consumes before building the final content-plus-behavior dispatch contract.
- Campaigns supplies Campaign-owned step/variant timing and behavior.
- Broadcasts supplies exact `sendAt` and uses the `Broadcast` as behavior-owner provenance rather than manufacturing a fake zero-delay Messaging schedule.
- FlowRoute `send_message` behavior does not inherit hidden timing from reusable Messaging templates.
- Imported-contact permission invitations and consent acknowledgements explicitly request immediate behavior and use stable logical occurrence identity.
- Setup validation rejects reusable Messaging templates that carry competing lifecycle behavior.
- Obsolete `timing`, `schedule`, and `conditions` columns are absent from `message_template_presets`.
- Webinar dev tooling now resolves Webinar-owned behavior through the same schedule-profile seam and uses explicit caller-owned immediate behavior only when deliberately forcing a dev send.
- The focused and full automated test suites are green after the refactor.

This is durable schema/architecture work completed before production rollout, not a compatibility-preservation layer.

## Recommended next implementation target

The immediate client-operational target is the Rob production Webinar contact migration checkpoint described above: first re-verify final consent-domain/opt-in behavior, then finalize dry-run/apply import execution.

Phase 11 remains the current broader product-roadmap phase.

Completed within Phase 11:

```text
Automation Opportunities backend foundation
manual/compound producer and evidence slice
real CRM/manual smoke validation
global Point collapse
FlowRoutePoint direct ownership model
module-first preset contribution architecture
business-language Routes information architecture
Manage Routes and Assignments split
existing Route editing modal
existing Point editing modal
drag-and-drop ordering with explicit Save order
current linear Point authoring subset
direct Route message-template eligibility seam
contextual Stop Campaign availability
Point placement policy and matching UX guardrails
```

The next Route work should continue from this real baseline rather than reopening already-settled product questions.

Highest-value remaining candidates:

```text
new Route creation
Route duplication
activate/deactivate
trigger changes
clone Point from another Route
task assignment/default authoring inside create-task Point UX
business-day/business-hour wait authoring
manual status-change consequence warning UX
contextual Automation Opportunity suggestion UX
```

Keep the locked product boundary:

```text
Routes are linear.
Do not introduce arbitrary branching, joins, nested branch trees, connectors, generic node-editor behavior, or arbitrary jump-back loops.
```

Do not expand the Automation Opportunities backend merely because more actions or events exist. Add producers/evidence only when a useful, truthful suggestion can be described.

## What this roadmap intentionally avoids

This roadmap should not be used to justify temporary shortcuts.

Avoid:

- fake MVP code paths that will be reverted soon;
- compatibility layers for old shapes unless explicitly chosen;
- adding module-specific behavior into Core for speed;
- building blank-canvas client builders before the guided workflow is clear; follow `ui-ux-guide.md` for client-facing patterns;
- building platform-cockpit screens that expose every module, builder, dashboard widget, log, setting, or automation primitive before the next client action is clear;
- expanding universal modules without a concrete workflow consumer;
- treating SMS visibility as a provider toggle instead of a Messaging channel-availability decision;
- making normal Broadcasts a consent bypass;
- making permission invitations a general Broadcast feature instead of a Messaging-owned one-time consent flow;
- making a single normal Broadcast fan out to email and SMS without deliberate future channel-strategy work;
- adding a vertical/point reconciliation table before proving registry/config/provider seams are insufficient.

## Relationship to TODO.md

`TODO.md` remains the disposable backlog/checklist file.

Keep roadmap-level ordering here.

Keep repeatable checklists and lower-priority backlog in `TODO.md`.

When a roadmap item is completed:

1. Remove it from this file or move it to a completed release note if needed.
2. Delete or update the related TODO item.
3. Update module docs only if architecture or durable behavior changed.

## UX notes captured before polish

The following UX notes are intentionally captured now but should not reorder the current schema-discovery sequence unless implementation reveals schema risk.

Schema/code-sensitive notes:

```text
Shared available-field/token picker
    TokenContractRegistry and MessageTemplateTokenValidator now provide the reusable source-of-truth/validation seam.
    Remaining work is polished `Insert field` / `Add field` UX consuming the same exact producer contexts.

Route terminology and capability labels
    Route Management / Routes language should use FlowRouteCapability metadata, labels, hints, supported subjects, required modules, and available fields so client-facing screens can explain available actions without importing module internals.

Human-readable schedule summaries
    Campaigns, Webinars, and FlowRoutes need schedule summary helpers that translate internal timing into user expectations.
    This is likely code/service work, not table design, unless persisted schedule-summary metadata is proven necessary.
```

Later UX polish notes:

```text
Broadcasts
    Make opt-in invitations secondary to normal Broadcast authoring.
    Hide opt-in options/import batches when eligible count is zero.
    Collapse Avoid Duplicate Sends.
    Consider a channel -> payload -> recipients/review authoring flow.
    Add Make a new broadcast from this when useful.

Campaigns
    Replace delivery-options language with message-step/channel language.
    Collapse campaign steps by default.
    Hide technical specs behind details/debug affordances.
    Use human-readable timing such as Sends 10 days after webinar.
    Clean up repeated dropdown labels.

Routes
    Use Route Management / Routes in client-facing navigation.
    Use contextual hints to explain automatic actions in plain language.
```
