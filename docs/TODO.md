# Engage Core TODO

## Config generation lock-in

- [x] Freeze Slam Dunk effective config and representative runtime behavior as golden fixtures.
- [x] Add shared closed config-contract primitives and register foundational, Messaging,
  Campaigns, FlowRoutes, and Webinars contracts.
- [x] Add token source/context contracts based on real columns and explicit computed providers;
  exclude arbitrary metadata and sensitive/raw values.
- [x] Prove the broad `test_everything` package sync and zero-finding setup validation.
- [ ] Run registered closed config contracts from `setup:validate` so structural rules are not
  duplicated across tests and contributor code.
- [ ] Add closed contracts for complete file envelopes, reference keys, conditional objects, and
  producer-owned campaign start payloads.
- [ ] Add strict reference and token closure validation for every exported definition.
- [ ] Generate field/token tables and contract-derived authoring references in CI.
- [ ] Build a minimal deterministic exporter and semantic round trip using Slam Dunk as the first
  full-package fixture.
- [ ] Build preview and authoring UX as consumers of the same registries and strict validator.

Detailed sequencing and open decisions are in
[`config-generation-lock-in-roadmap.md`](config-generation-lock-in-roadmap.md).

This file is intentionally disposable. Add work here when it is real but not yet ready for an implementation slice. Delete items as they are completed. Do not treat this as an architectural reference; long-lived decisions belong in `module-boundaries.md` or a feature-specific doc.

## Run through after completing an item or system update

These are repeatable checklists. Run the relevant checklist after a production slice, config update, client setup change, or staging deployment. Do not leave one-off feature work here; put one-off work in the backlog sections below.

### UI Rules

- [ ] Use `docs/ui-ux-guide.md` when reviewing or refactoring client/operator-facing screens.
- [ ] Apply the “no what did I get myself into?” test to every client-facing screen.
  - The page should make the next action obvious before exposing module detail.
  - The page should avoid platform-cockpit sprawl: too many modules, widgets, logs, builders, raw keys, and settings at once.
  - Powerful features should default to summaries, presets, guided choices, and consequence previews.
- [ ] Continue Routes / FlowRoutes product-completion work from the implemented baseline.
  - Treat contextual Automation Opportunities as the discovery layer and Routes as the control center.
  - Preserve the locked linear Route product boundary; do not add arbitrary branching, joins, nested branch trees, connectors, generic node-editor behavior, or arbitrary jump-back loops.
  - Keep Manage Routes focused on what a Route does and Assignments focused on when it runs.
  - Preserve the current server-authoritative placement policy: Wait cannot be terminal; Change Status must be terminal.
  - Preserve explicit direct-Route Messaging eligibility; do not expose every active Messaging template.
  - Use the existing FlowRoutes-owned `ContactStatusAutomationImpactResolver` as the backend source of truth for manual-status-change consequence previews.
  - Remaining high-value gaps: new Route creation, duplication, activate/deactivate, trigger changes, clone Point from another Route, task assignment/default authoring, business-day/business-hour waits, manual-status warning UX, and contextual suggestion UX.
- [ ] Apply the AJAX/preserve-context UI pattern to other CRM row/panel/modal workflows where page reloads would frustrate operators.
  - Tasks complete/reopen/cancel/archive.
  - Broadcast recipient/detail actions where applicable.
  - Campaign enrollment controls where applicable.
  - FlowRoute selection/testing controls where applicable.

### After each production code slice

- [ ] Run focused tests for the modules touched by the slice.
  - Broadcasts.
  - Messaging.
  - Core contact filters.
  - Campaigns, FlowRoutes, Tasks, Webinars, or Modules when touched.
- [ ] Run broader adjacent-module tests before committing when module boundaries are involved.
- [ ] Confirm production code changes are architectural fixes, not test-shaped legacy preservation.
- [ ] Confirm no new direct cross-module model/table writes were introduced where a public action/service should be used.
- [ ] Confirm migrations are replacements, not modify-table migrations, while this branch remains pre-rollout.
- [ ] Run `php artisan optimize:clear` after config, route, provider, or view changes.
- [ ] Update docs only when the architecture or operator/client behavior changed.

### After each config or client-template update

- [ ] Confirm every config key is supported by the relevant template, guide, or feature doc.
- [ ] Confirm client copy uses documented tokens only.
- [ ] Confirm Messaging copy passes `MessageTemplateTokenValidator` for the exact producer context; do not use a global token allowlist.
- [ ] Confirm runtime-only URLs/tokens are not guessed or hard-coded in static config.
- [ ] Confirm Campaign presets do not own reusable message payload/copy.
- [ ] Confirm campaign variants reference Messaging-owned template presets/assignments when variant architecture is used.
- [ ] Confirm Campaign preset step message references use first-class `channel`, `purpose`, and `scope` keys.
- [ ] Confirm Messaging templates live under the expected channel/purpose/scope path.
- [ ] Confirm Webinar Messaging definition files do not reintroduce per-scope `opt_ins`; consent acknowledgements should resolve through Messaging consent domains.
- [ ] Confirm message scopes map to intentional consent domains and unknown scopes remain narrow.
- [ ] Confirm `next_day_at` schedules use strict `HH:MM` and client timezone rather than embedding timezone.
- [ ] Confirm delayed lifecycle conditions remain available for send-time revalidation.
- [ ] Confirm MessageTemplatePreset sync/assignment rules are preserved when DB-backed templates are involved.
- [ ] Confirm Task presets create DB-owned task template definitions only and do not create live tasks.
- [ ] Confirm FlowRoute presets use public action/service/capability references rather than private module internals.
- [ ] Confirm SMS visibility is controlled by config where the surface exposes channel choices.
- [ ] Confirm missing optional content/style keys do not break public pages.
- [ ] Confirm tests are tolerant of client copy changes unless exact copy is the behavior under test.
- [ ] Confirm unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
- [x] Classify config validation findings as hard errors or warnings.
- [x] Confirm hard errors block staging/client handoff.
- [x] Confirm warnings give useful operator/debug guidance without blocking safe runtime behavior.
- [ ] Confirm client config overrides preserve unspecified nested defaults where fallback is expected.
- [ ] Confirm numeric/list overrides intentionally replace default lists where that is the current merge contract; verify client reminder/profile lists do not append duplicate Core slots.
- [ ] Confirm any client-selected preset package exists in effective merged `presets.packages`; keep rich vertical/client packages in client config rather than Core.

### After each permission-invitation update

- [ ] Confirm the invitation remains email-only for the one-time bypass send.
- [ ] Confirm normal Broadcasts do not receive imported-contact bypass behavior.
- [ ] Confirm SMS opt-in remains explicit and requires a phone number when SMS is selected.
- [ ] Confirm already accepted or previously claimed/sent invitations cannot create duplicate consent rows or resend through the bypass.
- [ ] Confirm the public preference URL is injected at runtime before provider send.
- [ ] Confirm accepted consent scopes match `messaging.permission_invitations.consent.scopes`.
- [ ] Confirm client copy can change without breaking behavioral tests.

### After each SMS/channel-visibility update

- [ ] Confirm SMS provider/runtime code remains present.
- [ ] Confirm SMS is hidden from client/admin UI when disabled for that surface.
- [ ] Confirm hiding SMS does not disable backend protections.
  - Consent gates still enforce SMS rules.
  - Suppression/revocation still works.
  - Inbound STOP/HELP handling still works if provider/webhook is active.
- [ ] Confirm SMS appears only on explicitly enabled surfaces.
- [ ] Confirm permission invitation SMS opt-in remains explicit.

### Before committing a feature slice

- [ ] Review changed files for stale terminology. Internal/runtime identifiers should use `contact`; client-facing UI/copy may use the configured business noun. Also check `audience` vs `recipient` and other module-specific naming drift.
- [ ] Confirm newly added public routes/controllers follow module directory conventions.
- [ ] Confirm feature-specific docs or `TODO.md` were updated when useful.
- [ ] Confirm `module-boundaries.md` was updated only for long-lived architectural decisions.
- [ ] Confirm temporary TODO items were deleted or moved into the one-off backlog below.

### Before staging smoke tests

- [ ] Run focused tests and adjacent-module tests locally.
- [ ] Run `php artisan migrate` against staging only after confirming migration shape is final for that branch.
- [ ] Run `php artisan optimize:clear` on staging.
- [ ] Confirm module visibility and navigation match config.
- [ ] Confirm provider credentials/config are present for enabled providers.
- [ ] Confirm queue workers/Horizon are running if scheduled/send behavior is being tested.

### Roadmap tracking

- [ ] Keep `docs/client-readiness-roadmap.md` current as the focused client-readiness implementation roadmap.
  - Use `TODO.md` for disposable backlog/checklists.
  - Use the roadmap for implementation order and session planning.
  - Use the pre-prod schema-discovery ordering lens until schemas are stable enough for production rollout.
  - Prioritize remaining work by DB/schema impact first, code/module-seam impact second, and UI/UX polish third.
  - Do not treat roadmap items as temporary MVP shortcuts.
  - Delete completed roadmap items or move them back to TODO when they are no longer near-term.

## Pre-prod schema-discovery phase tracking

Use this as a disposable checklist mirror of the roadmap sequence. Keep the roadmap as the source of truth for implementation order and update this section as phases are completed, deferred, or split.

- [x] Phase 1 — Webinar schedule profiles.
  - DB-owned profiles/items exist.
  - Series/webinars can select profiles with default fallback.
  - Webinars owns timing/slot identity.
  - Messaging owns reusable copy/templates.
  - Scheduled-message payloads stay compact; schedule/profile/template/debug identity belongs in meta.
- [x] Phase 2 — Campaign channel variants.
  - DB-owned campaign step variants exist.
  - Campaign enrollment is lifecycle.
  - Campaign step is the business moment.
  - Campaign step variant is the channel-specific delivery option.
  - `send_all_eligible` schedules multiple eligible variants.
  - `dependency_aware` is hardened for same-enrollment/same-step sibling variant states.
  - Supported dependency states include scheduled, pending, sent, skipped, failed, terminal, and unavailable.
  - Dependency checks consider same-pass scheduled siblings and persisted ScheduledMessage records.
  - Dependency checks are scoped to the same campaign enrollment, same campaign step, and required variant key.
  - Preset sync creates variants, removes stale non-customized variants, preserves customized stale variants, and protects customized campaigns.
  - Campaign scheduled-message payloads stay compact; campaign/step/variant/template/debug identity belongs in meta.
- [x] Phase 3 — Task templates / task defaults.
  - Audit `task_templates` table/model shape for generated/manual tasks.
  - Confirm FlowRoutes `create_task` points can reference `TaskTemplate` records.
  - Confirm task templates can define title/body/default due offsets/assigned_to/responsible_party and the then-current related-subject rules. Phase 12 supersedes the relationship shape with TaskLinks.
  - Confirm task templates are generic enough for PetServices, Mortgage, Music, Webinars, Documents, Scheduling, etc.
  - Confirm task template preset sync creates DB-owned default task templates only and does not create live tasks.
  - Confirm customized templates are preserved.
  - Decide whether direct template reference is enough or whether template assignment/selection is needed.
  - Build task template UI only if needed.
- [x] Phase 4A — FlowRoutes relationship, capability, and instance-plan audit.
  - Map FlowRoutes against current universal modules: Messaging, InboundMessaging, InternalNotifications, Tasks, Workflow, Campaigns, Broadcasts, Webinars, Reporting, Scheduling, Portal, Forms, Documents, Commerce, Location.
  - Map FlowRoutes against vertical/planned vertical modules: Mortgage, PetServices, Music.
  - Decide which modules produce automation events.
  - Decide which modules expose public actions FlowRoutes may call.
  - Decide which modules contribute point handlers.
  - Decide which modules contribute route presets.
  - Decide which modules contribute task templates.
  - Decide which modules contribute capability metadata/labels.
  - Decide which modules expose records that routes can be scoped to through subject morphs.
  - Decide how FlowRoutes knows which point types/capabilities/labels are available without importing modules directly.
  - Decide whether provider/registry/config is enough or DB-owned capability/binding tables are needed.
  - Decide whether `ContactFlowRouteProgress` needs `subject_type` / `subject_id`.
  - Decide whether reusable FlowRoute templates should seed contact/subject-specific route plans.
  - Decide whether active route plans need plan item snapshots so template edits do not unexpectedly change live instances.
  - Decide whether operators can insert/repeat/skip/cancel route instance plan items for one contact/subject.
  - Decide how event waits, task completion, appointment completion, document completion, etc. resume specific plan items.
  - Audit conclusion at that phase: subject-scoped route instances, route instance plans/items, progress/execution items, capability catalog/bindings, and durable created-artifact tracking/correlation were required before production. Phase 12 revises the Tasks-specific direct provenance coupling.
- [x] Phase 4B — FlowRoutes schema hardening.
  - Added `subject_type` / `subject_id` to `contact_flow_route_progress`.
  - Added `contact_flow_route_plans`.
  - Added `contact_flow_route_plan_items`.
  - Added `contact_flow_route_progress_items`.
  - Added `flow_route_capabilities`.
  - Added `flow_route_capability_bindings`.
  - Added direct FlowRoutes provenance fields to Tasks, ScheduledMessages, and CampaignEnrollments at that phase. Phase 12 intentionally removes the Tasks-specific structural dependency and keeps correlation in FlowRoutes-owned state.
  - Hardened blocked/cancelled/superseded runtime behavior so open plan/progress items do not remain successful-looking or resumable incorrectly.
  - Normalized route wait/resume metadata and automation-event started_at fallback behavior.
  - Added full structured FlowRoutes provenance to task-completed automation/debug paths where applicable.
  - Added producer-level provenance tests and boundary guardrails for FlowRoutes internals.
  - Superseded by the Phase 12 boundary decision: future modules should preserve created-artifact correlation without automatically copying FlowRoutes foreign keys into every artifact-owning module.
  - Kept module-owned business behavior behind public actions/services/contracts.
  - Deferred polished Route Management UX and CRM provenance/debug views at that phase; a first Routes editor baseline has since been implemented.
- [x] Phase 5 — FlowRoutes event-wait / task-completed resume implementation.
  - Resumes from neutral `task.completed` `AutomationEventRecorded` events.
  - Keeps Tasks independent from FlowRoutes.
  - FlowRoutes listens to generic `AutomationEventRecorded` and resumes matching event_wait/progress/plan/progress items internally.
  - Does not rely on contact-only fallback for task-completed waits.
  - Supports unambiguous route-created Task artifact matching.
  - Supports explicit event_wait correlation for routes that may create multiple tasks.
  - Covers the real CompleteTaskAction → TaskCompleted → AutomationEventRecorded → FlowRoutes listener chain.
- [x] Phase 6 — Config validation / setup validation. Complete; 6A–6E are green.
  - [x] Phase 6A — Documentation audit and contract normalization.
    - Docs define authoritative terminology, config ownership, validation ownership, severity direction, extension seams, and source-of-truth rules.
    - Internal/runtime identifiers use `contact`; client-facing UI/copy may use configured business nouns.
  - [x] Phase 6B — Config normalization.
    - Normalized preset groups/definitions, Messaging definitions, Webinars config, reference registries, token contexts, and canonical contact terminology.
    - Removed stale legacy keys and schedule-specific shared message-type drift.
  - [x] Phase 6C — Schema/model audit.
    - Completed ContactStatus customization contract.
    - Completed TaskTemplate defaults/durability and live Task template identity.
    - Completed FlowRoute capability source of truth.
    - Completed FlowRoute current-version/revision contract.
    - Completed live route-instance reconciliation and plan revision history.
    - Completed Campaign schema/variant/enrollment ownership audit.
    - Completed Messaging template/authoring identity and stale config-owned preset reconciliation.
    - Completed Messaging validation seam audit; no additional schema needed for validation.
    - Completed Webinar schedule-profile customization/default uniqueness contract.
    - Completed global preset orchestration reconciliation.
    - Fresh migrations passed.
    - Global `presets:sync` passed.
    - Focused sync/durability tests passed.
    - Adjacent module/runtime boundary tests passed.
    - Broader end-phase test sweep passed.
    - No additional schema additions are recommended before Phase 6D.
  - [x] Phase 6D — Contributor-based validation/runtime code.
    - Add a central `SetupValidationManager` orchestrator.
    - Add registered module/app validation contributors.
    - Reuse/adapt existing validators such as Messaging `MessageConfigValidator`.
    - Return structured findings with severity, code, message, source, path, module, context, and compact diagnostic meta where useful.
    - Make the same seam reusable by CLI now and future authoring/readiness UI later.
    - Validate Task presets and TaskTemplate references.
    - Validate FlowRoute presets, point types, capability references, handler/module availability, route graphs, and route-instance assumptions.
    - Validate Campaign references, variants, strategies, and dependency rules.
    - Validate Messaging/template references and available field/token context.
    - Validate Webinar schedule-profile integrity and conflicting active defaults.
    - Validate vertical references and unsupported point/module combinations.
    - Treat invalid/impossible/unsafe selected runtime behavior as a hard error.
    - Treat safe-but-dormant/unused/surprising behavior as a warning.
    - Make hard errors fail the command and block staging/client handoff; warnings remain non-blocking and actionable.
    - Validate reference registries for drift without treating stale registries as the sole runtime truth.
    - Do not persist validation findings/history unless a real operator workflow requires it.
  - [x] Phase 6E — Validation tests and final handoff coverage.
    - Focused setup-validation suite is green.
    - Adjacent Campaigns, FlowRoutes, Messaging, Tasks, Webinars, Workflow, module-boundary, and client-fallback regression coverage is green.
    - Broader default-preset/client fallback coverage is green.
    - Final docs/handoff reconciliation completed.

- [x] Phase 7 — Permission invitation accepted automation event.
  - Accepted invitations emit neutral `permission_invitation.accepted` events.
  - Acceptance locks/rechecks the invitation row inside a transaction for idempotency.
  - SMS phone updates, consent creation, and invitation accepted state are committed together.
  - The event emits only after the acceptance transaction succeeds.
  - Already accepted invitations do not emit the event again.
  - Messaging remains independent from downstream consumers.
  - No schema change was required.
- [x] Phase 8 — Permission invitation cancellation / skip / failure bookkeeping.
  - Pre-claim skips/cancellations create no invitation row. Post-claim scheduled-message skips reconcile matching claimed invitations to failed. Provider/runtime failures remain failed across delivery and invitation state. No schema change or new invitation statuses were required.
- [x] Phase 9 — Webinar message readiness check.
  - Added computed readiness visibility for registration confirmations, registration opt-ins, reminders, waitlist alerts, waitlist opt-ins, and post-event follow-ups.
  - Readiness uses runtime Messaging resolution, channel availability, active schedule-profile effects, explicit disablement, selected-profile validity, active-default conflicts, and post-event outcome-message enablement.
  - Readiness is not persisted.
- [x] Phase 10 — Manual status-change automation warning foundation.
  - Added plural selected FlowRoute resolution for ContactStatus triggers.
  - Added a read-only `ContactStatusAutomationImpactResolver` reporting whether automation would run and which selected routes are involved.
  - Inactive bindings/routes are ignored.
  - Preview resolution does not start route progress or mutate Workflow/Contact state.
  - No schema, controller, or Blade changes were required.
  - The actual operator warning/confirmation UX remains part of Phase 11.
- [ ] Phase 11 — Automation Opportunities + Routes / FlowRoutes product completion.
  - [x] Automation Opportunities backend foundation complete for the current producer/evidence slice.
  - [x] Route Management product-completeness audit complete.
  - [x] Global `Point` model/table/template layer removed.
  - [x] `FlowRoutePoint` now directly owns concrete action/wait/condition type and configuration.
  - [x] Route authoring direction chosen: Route-centric concrete `FlowRoutePoint` creation with clone-only reuse from another current Route.
  - [x] Module-first preset contribution architecture implemented.
  - [x] `PresetContributionRegistry`, `PresetPackageResolver`, `PresetCompositionResolver`, and `ResolvedPresetDomain` are the shared preset-composition seams.
  - [x] `Routes -> Manage Routes / Assignments` information architecture implemented.
  - [x] Business-language Route cards and one-step Automatic Behavior presentation implemented.
  - [x] Existing Route editing implemented in a modal.
  - [x] Existing Point editing implemented in a modal.
  - [x] Current authorable Point subset implemented: Wait, Change Status, Create Task, Send Message, Start Campaign, Stop Campaign.
  - [x] Drag-and-drop ordering with explicit `Save order` implemented.
  - [x] Move up/down fallback controls retained.
  - [x] Direct Remove actions retained.
  - [x] Stop Campaign is contextually hidden until the Route already contains Start Campaign.
  - [x] Direct Route message-template eligibility is explicit opt-in through `meta.route_authoring.eligible = true`; internal-purpose templates remain ineligible.
  - [x] Linear Route product boundary locked: no arbitrary branching canvas, joins, nested branch trees, connectors, generic node editor, or arbitrary jump-back loops.
  - [x] Point placement policy implemented and enforced server-side:
    - Wait cannot be terminal.
    - Change Status must be terminal.
    - Add/remove/move/reorder validate the proposed resulting sequence.
  - [x] Placement UX guardrails implemented:
    - Terminal Change Status has no drag handle.
    - Invalid move controls are disabled.
    - Removing the Point after a Wait is disabled when it would leave Wait terminal, with explanatory hover/focus text.
    - Invalid terminal Wait drag feedback is local to the terminal position rather than a page-level warning.
  - [ ] Continue focused Routes product work.
    - New Route creation.
    - Route duplication.
    - Activate/deactivate.
    - Trigger changes.
    - Clone Point from another current Route without shared linkage.
    - Task assignment/default authoring inside create-task Point UX.
    - Business-day/business-hour waits.
    - Manual status-change consequence warning UX.
    - Contextual Automation Opportunity suggestion UX.
    - Simple future Point eligibility / Route continuation rules only if they remain linear and understandable.
- [ ] Phase 12 — Standalone and multi-link Tasks.
  - [x] Audit current Task schema, models, creation paths, UI assumptions, notifications/digests, automation events, FlowRoutes integration, tests, and docs.
  - [x] Lock the Task mental model as independent dimensions rather than mutually exclusive Task categories.
    - Template-backed vs no-template.
    - Unlinked vs linked to zero/one/many module-owned records.
    - Manual vs automation-created.
  - [x] Lock invariant: no-template Tasks are manual only; automation-created Tasks must be template-backed.
  - [x] Lock generic relationship target: replace the single `related` morph with zero-to-many `task_links`.
  - [x] Lock initial TaskLink roles: `subject`, `context`, `result`.
  - [x] Lock one canonical relationship system; do not retain both `related` and `task_links`.
  - [x] Lock UX barometer: Task surfaces must quickly explain WHY the Task exists, WHAT to do, and HOW to complete/advance it.
  - [x] Include dedicated Task index and Task show surfaces in the phase; first pass may be information-dense and function-first.
  - [x] Lock Core-only operational contract: Tasks creation/lifecycle/templates/links/index/show/events must work with only Core enabled.
  - [x] Lock module boundary: Tasks must not structurally depend on FlowRoutes internals; FlowRoutes owns route correlation/resume state.
  - [ ] Replace `tasks.related_type / related_id` with `task_links` in branch schema/model/runtime code.
  - [ ] Preserve unlinked Tasks.
  - [ ] Preserve Contact-linked Tasks through TaskLinks.
  - [ ] Prove one existing non-Contact linked model cleanly.
  - [ ] Support Task links that grow over time, including `result` links added after work creates/selects a record.
  - [ ] Add Tasks-owned linked-record presentation resolver/provider seams and safe fallback behavior.
  - [ ] Ensure linked modules own presentation of their own records without Tasks importing those module models.
  - [ ] Add dedicated Task index route/controller/view.
  - [ ] Add dedicated Task show route/controller/view.
  - [ ] Keep Task show valid with zero links and readable with multiple links.
  - [ ] Generalize Contact show Task queries to the TaskLink model without including unrelated Tasks accidentally.
  - [ ] Keep dashboard Task rendering valid for unlinked and multi-link Tasks.
  - [ ] Move direct TeamMember/InternalNotifications coupling out of Tasks core creation/request/provider paths behind optional public seams.
  - [ ] Keep notification copy/CTA behavior valid when a Task has no links.
  - [ ] Keep digests independent of Contact or any specific linked module.
  - [ ] Remove Tasks-owned FlowRoutes-specific foreign keys/model imports.
  - [ ] Preserve FlowRoutes-created Task correlation through FlowRoutes-owned created-artifact/correlation state and neutral Task events.
  - [ ] Update FlowRoutes `create_task` behavior so automation-created Tasks are template-backed.
  - [ ] Update Task completion automation payload/context for TaskLinks while preserving valid contactless events.
  - [ ] Update Automation Opportunity Task producer behavior so repeated similar manual no-template Tasks are the primary Task-created suggestion signal.
  - [ ] Preserve useful existing Contact-specific compound opportunity behavior through TaskLinks/public linked-record context.
  - [ ] Update Task setup validation/config contracts for TaskLink roles/defaults/supported link types without importing module internals.
  - [ ] Add tests for unlinked, Contact-linked, non-Contact-linked, and multi-link Tasks.
  - [ ] Add tests for template/no-template and manual/automation invariants.
  - [ ] Add tests for dedicated index/show surfaces.
  - [ ] Add tests proving Tasks core operation without InternalNotifications, Messaging, or FlowRoutes enabled.
  - [ ] Run focused Tasks, Dashboard, Contact show, FlowRoutes, module-boundary, setup-validation, and automation-opportunity tests.
  - [ ] Run final docs audit after code work and remove/update stale implementation-gap notes.
- [ ] Phase 13 — Dashboard / contact workspace polish audit.
  - Review orientation surfaces after core runtime pieces settle.
  - Add persisted preferences/acknowledgements only if proven necessary.
- [ ] Phase 14 — FOSS-informed module schema audit.
  - Compare module schemas against mature FOSS patterns to catch likely missing persisted concepts before production.
  - Pull FlowRoutes-specific FOSS/OSS pattern review earlier into Phase 4 if useful.
  - Split by module group rather than one monster branch.

Deferred launch hardening:

- [ ] DB Snapshot / Export Safety Tool.
  - Do not build during active pre-prod schema discovery unless real data preservation becomes necessary.
  - Build when schemas are stabilizing and production rollout is near.
  - Keep command-line only; no CRM UI.
  - SQL dump should be the primary restore safety net.
  - JSONL table exports should be for inspection, diffing, selective recovery, and debugging.

### Automation opportunity foundation

- [x] Add `docs/automation-opportunities.md` and keep architecture/product rules current.
- [x] Add durable `automation_behavior_occurrences` and `automation_opportunities` schema using current migration conventions.
- [x] Add models and shared `app/Support/AutomationOpportunities` infrastructure.
- [x] Keep `AutomationEventRecorded`, behavior/correlation evidence, and aggregate opportunities distinct.
- [x] Require explicit producer/evidence opt-in; do not add clickstream/global Eloquent observation.
- [x] Keep fingerprint semantics producer-owned and hashing/normalization shared.
- [x] Keep the generic evaluator free of domain/event-specific branching.
- [x] Use stable capability keys where applicable instead of canonically depending on FlowRoutes DB IDs.
- [x] Add manual Contact-associated Task creation as the first evaluated producer.
- [x] Add manual status change -> manual Task creation compound detection.
- [x] Add manual Task completion evidence and manual Task completion -> manual status change compound detection.
- [x] Do not create a generic standalone `contact.status_changed_manually` opportunity.
- [x] Add selected `AutomationEventRecorded` evidence retention and supported event -> manual Task correlation.
- [x] Add `inbound_message.normal_reply` as a neutral automation event only for known-Contact normal replies; keep HELP/STOP outside opportunity evidence.
- [x] Use current generic defaults of 3 occurrences, 3 distinct subjects, and a 30-day observation window.
- [x] Use the current 10-minute window for implemented compound correlations.
- [x] Add focused tests, adjacent module tests, boundary protection, and real CRM/manual smoke validation.
- [x] Verify negative cases: unsupported events ignored, old evidence does not correlate, same-Contact repetition stays observing, system-created Tasks do not count as manual behavior.
- [ ] Add dynamic suggestion-time checks for current capability availability, equivalent existing automation, snooze/dismissal availability, conversion state, context validity, and attribution ambiguity when the first user-facing suggestion surface needs them.
- [ ] Continue contextual suggestion UX against the implemented Routes baseline; do not create a parallel automation builder or recommendation feed.

## Error Tracking

These are open code/runtime investigations surfaced by the first production Webinar run and the follow-up audit. Operational incidents that were setup/deployment issues belong in the staging/production checklist and troubleshooting docs rather than being mislabeled as product bugs.

### Messaging, scheduling, and queue diagnostics

- [ ] Verify `ScheduledMessage.send_at` persists the same absolute instant as the timezone-aware queued delay.
  - A production case persisted the wrong UTC value while the actual queued delay retained the correct execution instant.
  - Treat this as a persistence/runtime consistency issue; do not infer the Redis delay is wrong from the database timestamp alone.
- [ ] Make queued send-time diagnostics timezone-explicit.
  - Current Horizon/debug `send_at` metadata that uses `toDateTimeString()` drops timezone information and can make a correct instant look ambiguous.
- [X] Normalize multi-CTA support across config validation and runtime unresolved-token validation.
  - Config validation accepted `ctas` with `{cta}` while runtime unresolved-token validation did not consistently accept the same shape.
- [ ] Add a safer first-class operational recovery mechanism for exact skipped/failed `ScheduledMessage` rows.
  - Current recovery still depends on surgical Tinker commands for narrow incidents.
  - Preserve the current safety principle: identify exact rows, exact channel, exact status, and exact reason; never broaden recovery into indiscriminate retries or queue resets.
- [ ] Improve first-class queued-job diagnostics.
  - Surface effective queue connection, queue name, Redis prefix, delayed/reserved key identity, Horizon metadata, and delayed-until information so operators do not need manual Redis spelunking.
  - Deployment docs already require the restart; the remaining question is whether tooling should reduce the chance of human omission.

### Setup validation

- [ ] Verify the production preset/module false positive is resolved.
  - A production setup using the selected `mortgage` preset incorrectly reported the required Mortgage module as unavailable.
  - Reproduce with current preset composition and enabled-module configuration before changing code.
  - If current `setup:validate` accepts the valid configuration, mark this resolved rather than creating a new fix.

### Webinar join-signal integrity

- [ ] Separate raw join-link resolver hits from trusted human interaction.
  - A resolver `GET` can be triggered by scanners or prefetchers and currently may set `join_clicked_at`, increment `join_click_count`, and suppress a live reminder.
  - Preserve raw resolver evidence separately, for example `join_resolved_at` / `join_resolve_count` or equivalent.
  - Set trusted click evidence only after a stronger browser-side confirmation such as a signed `POST` or comparable interaction signal.
- [ ] Preserve enough join-link history to distinguish scanner/prefetch hits from later genuine interaction.
  - The current latest timestamp plus cumulative count overwrites earlier event detail.
  - Do not add event-history schema until the minimum useful operational/debug requirement is clear.

### Webinar duplicate registration and conflicting outcome safety

- [ ] Add a safe first-class duplicate-outcome suppression mechanism before contradictory attended/missed follow-ups are created.
  - Duplicate registrations for the same likely person and Webinar can independently generate conflicting automation, follow-up, status, and Campaign paths.
  - Do not solve this by globally merging contacts solely because phone numbers match.
- [ ] Define an explicit Webinar-scoped precedence rule for likely duplicate conflicting outcomes.
  - Candidate safe rule: attended wins over missed for likely duplicates within the same Webinar.
  - Identity matching must remain narrow, auditable, and separate from global Contact merge semantics.

### Webinar attendance and post-event provider reliability

- [ ] Review `RecordWebinarProviderAttendanceAction` behavior when Zoom still returns zero attendance records after the retry window expires.
  - Confirm whether zero attendance should permanently set `attendance_ready = true` and `attendance_recorded_at` or remain unresolved with an actionable state.
- [ ] Distinguish legitimate empty attendance from provider-readiness and authorization failures.
  - Empty provider results and access/scope failures need clearly different operational signals and retry behavior.
- [ ] Review empty attendance caching semantics around `Cache::remember()`.
  - A legitimate or premature empty result may be cached and reused on retries; verify whether empty responses should be cached, for how long, and under which provider states.
- [ ] Make post-event sequencing and recovery intent easier to inspect.
  - `webinar.ended` handles attendance while recording completion resolves playback and dispatches follow-ups.
  - Runtime behavior is valid, but current operational sequencing is easy to misread during recovery; improve first-class status/introspection before considering orchestration changes.

## One-off backlog

### Rob production Webinar contact migration

Immediate production-prep checkpoint:

- [ ] Re-verify `ConsentDomainRegistry` behavior before touching real contacts.
  - Exact mapping wins.
  - Longest prefix wins.
  - Equal-specificity ambiguity fails loudly.
  - Unknown unmapped scopes remain narrow.
- [ ] Re-verify Webinar consent domain behavior.
  - `webinar`, `webinar_waitlist`, and `webinar_nurture` resolve to the intended `webinar` consent domain.
  - No per-scope Webinar `opt_ins` definitions are required.
  - Generic/module/client acknowledgement copy resolves correctly.
- [ ] Re-verify normal consent grant behavior.
  - Correct domain row is created/updated.
  - Duplicate related-scope grants do not create duplicate consent identities.
  - Appropriate acknowledgement behavior is resolved.
- [ ] Re-verify imported consent behavior.
  - `ImportMessageConsentAction` normalizes to the consent domain.
  - No `MessageConsentGranted` event.
  - No opt-in acknowledgement send.
- [ ] Confirm Rob runtime is clean.
  - `php artisan presets:sync`
  - `php artisan setup:validate`
- [ ] Review/finalize import command dry-run-by-default and explicit `--apply`.
- [ ] Confirm malformed phone + SMS-consent rows produce actionable row-level output.
- [ ] Prepare/verify the exact 11-row CSV.
- [ ] Dry-run and inspect exact output before apply.
- [ ] After apply, verify:
  - 11 contacts.
  - expected consent rows/domains.
  - 11 Webinar registrations.
  - no opt-in acknowledgement sends.
  - no registration confirmations.
  - only future-valid reminders.
  - no duplicates after idempotent rerun.

### CRM dashboard and contact workspace

Completed baseline:

- Dashboard is config-driven by module slots and preset priorities.
- Modules contribute dashboard panels through provider seams.
- Empty actionable panels can show calm caught-up states.
- Empty passive context panels hide.
- Module tones provide muted dashboard wayfinding.
- Contact show is a Core-owned shell with module-provided panels and sections.
- Contact show uses module wayfinding and improved client-facing section labels.

Remaining polish audit:

- [ ] Review shared orientation surfaces after runtime behavior settles.
- [ ] Add persisted preferences/acknowledgements only when real workflows require them.
- [ ] Avoid turning dashboard/contact workspace into a platform cockpit.

### Client self-serve readiness

- [ ] Do not treat controlled beta readiness as full client self-serve readiness.
  - Controlled beta can assume operator-assisted setup/configuration.
  - Full self-serve requires more polished builders, safer config validation, clearer import review tools, and stronger client-facing admin UX.
- [ ] Identify which admin surfaces must exist before clients can operate without developer/operator help.
  - Route builder/editor.
  - Campaign/template management.
  - Task template management.
  - Import mapping/review.
  - Broadcast/permission invitation setup.
  - Provider/channel settings.

### Vertical module planning

- [ ] Plan the PetServices vertical module.
  - Own pets/dogs, pet profiles, training programs, training goals, dog behavior notes, trainer-specific domain rules, and pet-service-specific workflows.
  - Consume Scheduling for dog training appointments/sessions.
  - Consume Portal for customer access.
  - Consume Forms for dog intake forms.
  - Consume Documents for vaccination records, waivers, and other uploads.
  - Contribute vertical-specific route presets/task templates/capability labels through public seams.
  - Keep pet-specific fields out of Core contacts.
- [ ] Plan the Music vertical module.
  - Own music-specific fan/customer meaning, release/fan campaign strategy, music product interest categories, and music-specific segmentation rules.
  - Consume Commerce for Shopify/purchase facts.
  - Consume Location later for show-radius targeting if needed.
  - Consume Campaigns, Broadcasts, Messaging, and FlowRoutes for fan communication/automation.
  - Contribute vertical-specific route presets/task templates/capability labels through public seams.
  - Keep music-specific purchase/interest state out of Core contacts unless represented through a proper universal module relation.

### Documentation maintenance

- [ ] Regenerate `core-project-tree.txt` from the repo after structural module/file changes.
  - Do not hand-maintain it.
- [ ] Keep `module-boundaries.md` architectural, not a backlog.
  - Move actionable backlog items into this TODO file.
  - Delete completed TODOs instead of accumulating historical notes.
- [ ] Add/update feature-specific docs when a feature crosses module boundaries.
  - Permission invitations already has a dedicated doc.
  - Similar docs may be useful for Broadcasts, Campaigns, FlowRoutes, Tasks, and Imports once each stabilizes.
- [ ] Remove the hand-maintained full module-registry copy from `docs/config-templates/modules-template.php`.
  - Keep `config/modules.php` as the one external registry of installed module existence.
  - The template should document registry shape and selected-client runtime module configuration without requiring every module key, provider, and dependency to be copied manually.
- [ ] Avoid parallel documentation inventories of executable module and preset registry facts.
  - Keep examples that explain architecture and config shape.
  - Do not create secondary authoritative lists that must be updated every time a module is added.
  - Prefer deriving future generated reference documentation from executable registries/contracts where appropriate.
- [ ] Reconcile the canonical executable queue inventory across client-environment-reference.md and client-staging-production-setup-checklist.md.
  - Verify current runtime truth against config/horizon.php, config/reference/keys.php, and executable Messaging/Webinar definitions.
  - Remove contradictory claims about emails, campaigns, and waitlist.

### Testing backlog

- [x] Add test coverage for client config fallback behavior.
  - Missing optional content/style keys should not break public pages.
  - Client copy changes should not break tests that only need behavioral assertions.
- [ ] Refactor the hard-coded installed-module inventory assertion in `tests/Feature/Modules/ModuleDependencyBoundaryTest.php`.
  - Validate registered module definitions generically instead of enumerating every installed module key in a second hard-coded list.
  - Adding a new module should not require updating another module-existence inventory outside `config/modules.php`.

## Captured UX polish backlog

These notes are intentionally retained while the schema-discovery phases continue. Do not pull them ahead of higher schema-risk work unless implementation proves a missing table, registry, provider, or runtime service is needed.

### Shared field/token insertion

- [ ] Add a shared available-field/token picker pattern for message/template/config authoring surfaces.
  - Use client-facing language such as `Insert field` or `Add field`, not `token`, on normal operator screens.
  - Preserve cursor/focus in the input/textarea when the picker opens.
  - Provide autocomplete search from available fields for the current context.
  - Insert the current runtime syntax such as `{first_name}` without requiring operators to type braces or exact keys manually.
  - Do not let users select fields that the current message/context cannot resolve.
  - Consume `TokenContractRegistry` and `MessageTemplateTokenValidator`; do not create a second UI-only field list or validator.

### Contextual hints

- [ ] Add a reusable hover/focus hint pattern for confusing fields, settings, and navigation items.
  - Hints should explain what the setting does in plain language.
  - Hints should not expose schema/config/event keys as the main explanation.
  - Hints do not replace consequence previews for automation-triggering changes.

### Routes product completion

- [ ] Continue from the current `Routes -> Manage Routes / Assignments` information architecture.
  - Keep `FlowRoutes` in developer/module docs where precision matters.
  - Keep `Runs when` and trigger-selection detail in Assignments rather than repeating it in Route details.
  - Preserve modal Route editing and modal Point editing.
  - Preserve drag-and-drop with explicit `Save order` only after the order changes.
  - Preserve direct visible Remove actions and contextual disabled-state explanations.
  - Preserve `Campaign` terminology in Route UI; do not relabel Campaigns as follow-up sequences.
  - Add new Route creation before treating the current authoring surface as product-complete.
  - Consider duplication, activate/deactivate, trigger changes, and clone-from-another-Route as focused follow-up slices.
  - Keep advanced internal Point types out of normal authoring unless a later product decision explicitly introduces a simple non-canvas rule.

### Broadcasts UX polish

- [ ] Make imported-contact opt-in invitations secondary to normal Broadcast authoring.
  - Consider a button such as `Send opt-in invitation to imported contacts`.
  - Do not show opt-in options/import batches when eligible invitation count is zero.
- [ ] Collapse `Avoid Duplicate Sends` by default with a clear summary.
- [ ] Explore a guided Broadcast authoring flow:
  - channel;
  - channel-specific payload;
  - recipients;
  - duplicate protection/review.
- [ ] Add `Make a new broadcast from this` when useful for repeating a Broadcast to a new channel/audience.
  - Add lineage schema such as `cloned_from_broadcast_id` only if audit/debug/product needs prove it.

### Campaigns UX polish

- [ ] Replace `delivery options` wording with clearer `message steps` and channel wording.
- [ ] Collapse campaign steps by default.
- [ ] Show only the most useful collapsed step information:
  - step number/title;
  - available channel badges;
  - human-readable timing;
  - selected template readiness.
- [ ] Hide technical specs behind details/debug affordances.
- [ ] Replace raw timing such as `Delay 10 minutes` with human-readable schedule summaries.
- [ ] Clean up repeated dropdown labels such as `Step 1 Email — Webinar Attended Nurture — Step 1 Email`.


