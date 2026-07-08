# Engage Core Client-Readiness Roadmap

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

## Architecture runway after staging smoke success

The staging smoke test confirmed the need for runtime-selectable definitions.

The architecture runway is:

```text
sync available options
select active options in CRM/admin
resolve selected DB-owned options at runtime
```

Completed runway pieces:

- FlowRoute owner morph and trigger bindings.
- CRM selection for status/event FlowRoutes.
- Messaging template presets, catalog entries, and DB-first assignment resolution foundation.
- Message Templates catalog/copy-editing UI using catalog entry grouping.
- Campaign Message Templates assignment UI and access links for selecting active campaign-step templates.
- Webinars message/template setup surface.
- DB-owned webinar schedule profiles and items.
- DB-owned campaign step variants and strategy-aware scheduling.

Remaining near-term candidates:

- FlowRoutes event-wait/task-completed resume implementation on top of the completed route plan/progress item correlation foundation.
- Config/setup validation across presets, route points, module capabilities, available fields/tokens, and templates.
- Permission invitation accepted/cancelled/skip/failure behavior.
- Webinar message readiness checks.
- Manual status-change automation warnings.
- Automatic Follow-ups / FlowRoutes UX polish later, after the runtime/capability model is settled.

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
| 5 | FlowRoutes event-wait / task-completed resume implementation | Current | Runtime | Build on the completed Phase 4B foundation. Resume from neutral `task.completed` automation events by matching route progress, route plan item, route progress item, task id/template identity, and subject context. Do not resume task waits through contact-only fallback. |
| 6 | Config validation / setup validation | Planned | Architecture + safety | Validate task presets, FlowRoute presets, task-template refs, route point types, module capability references, available field/token references, vertical references, campaign refs, messaging/template refs, unsupported point/module combinations, and route instance/snapshot assumptions. Prefer command/service-based validation first. |
| 7 | Permission invitation accepted automation event | Planned | Architecture | Decide whether accepted invitations emit `permission_invitation.accepted` without Messaging depending on consumers. |
| 8 | Permission invitation cancellation / skip / failure bookkeeping | Planned | DB/schema + architecture | Clarify durable lifecycle visibility across permission invitations, Broadcast bookkeeping, and Messaging scheduled messages. |
| 9 | Webinar message readiness check | Planned | Architecture + operator safety | Add computed readiness visibility for webinar message setup without persisting setup state unless a concrete need appears. |
| 10 | Manual status-change automation warning | Planned | Operator safety | Warn before manual status changes run selected status-based FlowRoutes. Avoid schema unless audit/acknowledgement state is proven necessary. |
| 11 | Automatic Follow-ups / FlowRoutes UX polish | Planned | UI/UX + architecture | Redesign Route Binding around business outcomes, consequence previews, and client/operator language after the FlowRoutes capability/instance-plan/runtime model is settled. |
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


## Phase 4B FlowRoutes schema hardening completion

Phase 4B is complete for backend/schema readiness. Engage Core now has the durable FlowRoutes foundation needed before Phase 5 task-completed/event-wait resume work.

Phase 4B implemented backend schema and runtime foundation, not polished Route Management UX.

Durable implemented concepts:

```text
FlowRoute / FlowRoutePoint / Point
    Reusable route template layer.

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

Continue with Phase 5 FlowRoutes event-wait/task-completed resume implementation now that Phase 4B schema/runtime hardening is complete. Route Management UI, CRM provenance/debug views, and polished Automatic Follow-ups UX remain deferred.

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
| 5 | FlowRoutes event-wait / task-completed resume implementation | Current | Resume from neutral `task.completed` automation events using the Phase 4B route progress/plan/progress-item foundation and created Task identity rather than broad contact-only waits. Do not add Task-specific FlowRoutes listeners. |
| 6 | Config validation / setup validation | 0.5–1.5 sessions | Convert config-template expectations into practical validation behavior and operator/debug feedback. Validate task presets, FlowRoute presets, task-template refs, route point types, module capability refs, available field/token refs, vertical refs, campaign refs, Messaging template refs, unsupported point/module combinations, and route instance/snapshot assumptions. Prefer command/service-based validation first. |
| 7 | Permission invitation accepted automation event decision | 0.25–0.5 session | Decide whether accepted invitations should emit a neutral automation event such as `permission_invitation.accepted`. |
| 8 | Permission invitation cancellation behavior | 0.5–1 session | Clarify how cancellation/skip/failure should appear for permission-invitation Broadcast bookkeeping and Messaging scheduled messages. |
| 9 | Webinar message readiness check | 0.5–1 session | Computed readiness summary for Webinars message setup. Do not persist readiness/acknowledgement state unless the implementation proves a durable concept is missing. |
| 10 | Manual status-change automation warning | 0.5–1 session | Warn operators before a manual status change runs a selected status FlowRoute. This is a UI awareness guardrail, not a ContactStatus schema split. |
| 11 | Automatic Follow-ups / FlowRoutes UX polish | 1–3 sessions for first product pass | Redesign the current Route Binding surface around business outcomes and consequence previews after the capability/instance-plan/runtime model is settled. |
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
- Schedule profile items reference stable runtime dimensions such as channel, purpose, scope, surface, message type, dispatch key, and source config path.
- Multiple reminder slots may share `message_type = reminder`; the schedule profile item key/source config path owns the slot identity.
- Existing scheduled messages are not rewritten retroactively when a profile or template assignment changes.
- Webinar dispatch payloads store compact token/context data. Schedule profile identity belongs in scheduled-message metadata, not as a hydrated profile/items object graph inside `scheduled_messages.payload`.
- Tests include payload-shape checks so full Eloquent relationship graphs do not leak into scheduled-message payloads.

### Webinars message/template setup

Completed functional baseline:

- Webinars has a Webinars-owned message setup surface for registration confirmations, reminders, waitlist availability messages, post-attended transactional follow-ups, and post-missed transactional follow-ups.
- The surface shows the currently selected Messaging template per webinar message context.
- Operators can choose compatible `MessageTemplatePreset` records from the Webinars setup surface.
- Selection saves through `MessageTemplatePresetAssignment` while copy editing remains on the Message Templates page.
- Runtime resolution remains DB-first with config fallback so existing registration confirmation/reminder behavior is preserved during migration.
- This is a functional setup UI, not the final UX-polished communication-plan experience.
- Schedule/profile/timing selection remains a Webinars-owned follow-up item.

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

## Recommended next implementation target

The next implementation target is Phase 5: FlowRoutes event-wait / task-completed resume implementation.

Goals:

- Resume from neutral `task.completed` automation events through the generic AutomationEventRecorded seam.
- Match waits by specific route progress, route plan item, route progress item, created Task identity, template identity, and subject context where applicable.
- Avoid broad contact-only task-completed waits.
- Keep Tasks independent from FlowRoutes; Tasks emits neutral automation events and FlowRoutes resumes internally.
- Preserve Phase 4B provenance and boundary guardrails.

Do not build the polished Route Management builder/editor yet. The UX polish pass should happen after Phase 5 semantics are locked.

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

The following UX notes are intentionally captured now but should not reorder Phase 3 unless implementation reveals schema risk.

Schema/code-sensitive notes:

```text
Shared available-field/token picker
    May require a provider/registry source of truth and validation by message/context.
    Track primarily in Phase 6 config/setup validation.

Route terminology and capability labels
    Route Management / Routes language should be audited during Phase 4 because labels may come from module capability metadata.

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

