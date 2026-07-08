# Engage Core TODO

This file is intentionally disposable. Add work here when it is real but not yet ready for an implementation slice. Delete items as they are completed. Do not treat this as an architectural reference; long-lived decisions belong in `module-boundaries.md` or a feature-specific doc.

## Run through after completing an item or system update

These are repeatable checklists. Run the relevant checklist after a production slice, config update, client setup change, or staging deployment. Do not leave one-off feature work here; put one-off work in the backlog sections below.

### UI Rules

- [ ] Use `docs/ui-ux-guide.md` when reviewing or refactoring client/operator-facing screens.
- [ ] Apply the “no what did I get myself into?” test to every client-facing screen.
  - The page should make the next action obvious before exposing module detail.
  - The page should avoid platform-cockpit sprawl: too many modules, widgets, logs, builders, raw keys, and settings at once.
  - Powerful features should default to summaries, presets, guided choices, and consequence previews.
- [ ] Run an Automatic Follow-ups / FlowRoute binding UX exploration before implementation.
  - Do not start UX polish until the FlowRoutes capability/instance-plan/runtime model is settled.
  - Decide whether the first version selects prebuilt routes only, edits route points, or only previews selected behavior.
  - Decide intended user type: client, operator, developer, or split surfaces.
  - Use configured lead/contact/customer nouns.
  - Replace raw status/event terminology with Status and Activity language.
  - Use a status selector for status-triggered follow-ups.
  - Use module tabs and human-readable activity names for automation-event follow-ups.
  - Define consequence-preview requirements before save and before manual status changes.
  - Decide which point types are client-safe, operator-only, or developer-only.
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
- [ ] Confirm runtime-only URLs/tokens are not guessed or hard-coded in static config.
- [ ] Confirm Campaign presets do not own reusable message payload/copy.
- [ ] Confirm campaign variants reference Messaging-owned template presets/assignments when variant architecture is used.
- [ ] Confirm Campaign preset step message references use first-class `channel`, `purpose`, and `scope` keys.
- [ ] Confirm Messaging templates live under the expected channel/purpose/scope path.
- [ ] Confirm MessageTemplatePreset sync/assignment rules are preserved when DB-backed templates are involved.
- [ ] Confirm Task presets create DB-owned task template definitions only and do not create live tasks.
- [ ] Confirm FlowRoute presets use public action/service/capability references rather than private module internals.
- [ ] Confirm SMS visibility is controlled by config where the surface exposes channel choices.
- [ ] Confirm missing optional content/style keys do not break public pages.
- [ ] Confirm tests are tolerant of client copy changes unless exact copy is the behavior under test.
- [ ] Confirm unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
- [ ] Classify config validation findings as hard errors or warnings.
- [ ] Confirm hard errors block staging/client handoff.
- [ ] Confirm warnings give useful operator/debug guidance without blocking safe runtime behavior.
- [ ] Confirm client config overrides preserve unspecified nested defaults where fallback is expected.

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

- [ ] Review changed files for stale terminology, especially `audience` vs `recipient` and borrower/borrowers vs lead/leads.
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
  - Supported dependency states include scheduled, pending, sent, skipped, failed, and terminal.
  - Dependency checks consider same-pass scheduled siblings and persisted ScheduledMessage records.
  - Dependency checks are scoped to the same campaign enrollment, same campaign step, and required variant key.
  - Preset sync creates variants, removes stale non-customized variants, preserves customized stale variants, and protects customized campaigns.
  - Campaign scheduled-message payloads stay compact; campaign/step/variant/template/debug identity belongs in meta.
- [x] Phase 3 — Task templates / task defaults.
  - Audit `task_templates` table/model shape for generated/manual tasks.
  - Confirm FlowRoutes `create_task` points can reference `TaskTemplate` records.
  - Confirm task templates can define title/body/default due offsets/assigned_to/responsible_party/related-subject rules.
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
  - Audit conclusion: subject-scoped route instances, route instance plans/items, progress/execution items, capability catalog/bindings, and uniform artifact provenance are required before production.
- [x] Phase 4B — FlowRoutes schema hardening.
  - Added `subject_type` / `subject_id` to `contact_flow_route_progress`.
  - Added `contact_flow_route_plans`.
  - Added `contact_flow_route_plan_items`.
  - Added `contact_flow_route_progress_items`.
  - Added `flow_route_capabilities`.
  - Added `flow_route_capability_bindings`.
  - Added uniform FlowRoutes provenance fields to route-created artifacts: Tasks, ScheduledMessages, and CampaignEnrollments.
  - Hardened blocked/cancelled/superseded runtime behavior so open plan/progress items do not remain successful-looking or resumable incorrectly.
  - Normalized route wait/resume metadata and automation-event started_at fallback behavior.
  - Added full structured FlowRoutes provenance to task-completed automation/debug paths where applicable.
  - Added producer-level provenance tests and boundary guardrails for FlowRoutes internals.
  - Confirmed future modules should use the same provenance pattern when FlowRoutes creates Scheduling appointments, Document requests, Form requests/submissions, Portal invitations/access grants, Commerce records, or vertical-owned artifacts.
  - Kept module-owned business behavior behind public actions/services/contracts.
  - Deferred polished Route Management UX, CRM provenance/debug views, and Automatic Follow-ups UX polish.
- [ ] Phase 5 — FlowRoutes event-wait / task-completed resume implementation.
  - Implement after Phase 4B schema hardening because resume behavior must target route progress/plan/progress items.
  - Resume from neutral `task.completed` `AutomationEventRecorded` events.
  - Do not add Task-specific FlowRoutes listeners.
  - FlowRoutes listens to generic `AutomationEventRecorded` and resumes matching event_wait/progress/plan/progress items internally.
  - Do not rely on contact-only fallback for task-completed waits; require task/progress/plan/progress-item correlation.
- [ ] Phase 6 — Config validation / setup validation.
  - Validate Task presets.
  - Validate FlowRoute presets.
  - Validate task template refs.
  - Validate route point types.
  - Validate module capability references.
  - Validate available field/token references by authoring context.
  - Validate vertical references.
  - Validate campaign refs.
  - Validate messaging/template refs.
  - Validate unsupported point/module combinations.
  - Validate route instance/snapshot assumptions if applicable.
  - Prefer command/service-based validation first unless persistent validation records are proven necessary.
- [ ] Phase 7 — Permission invitation accepted automation event.
  - Decide whether accepted invitations should emit `permission_invitation.accepted`.
  - Keep Messaging independent from consumers.
- [ ] Phase 8 — Permission invitation cancellation / skip / failure bookkeeping.
  - Clarify durable lifecycle visibility across permission invitations, Broadcast bookkeeping, and Messaging scheduled messages.
- [ ] Phase 9 — Webinar message readiness check.
  - Add computed readiness visibility for webinar message setup without persisting setup state unless a concrete need appears.
- [ ] Phase 10 — Manual status-change automation warning.
  - Warn operators before manual status changes run selected status FlowRoutes.
  - Keep this as a UI/awareness guardrail, not a ContactStatus schema split.
- [ ] Phase 11 — Automatic Follow-ups / FlowRoutes UX polish.
  - Rename side-panel to something that doesn't imply messaging, using the term "Routes" to help tie together the concepts of "Routes have points along them"
  - Do not start until the FlowRoutes capability/instance-plan/runtime model is settled.
  - Redesign Route Binding / Automatic Follow-ups around business outcomes, consequence previews, and client/operator language.
  - Let the UX be informed by actual route capabilities and instance behavior.
- [ ] Phase 12 — Dashboard / contact workspace polish audit.
  - Review orientation surfaces after core runtime pieces settle.
  - Add persisted preferences/acknowledgements only if proven necessary.
- [ ] Phase 13 — FOSS-informed module schema audit.
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

## One-off backlog

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

### Testing backlog

- [ ] Add test coverage for client config fallback behavior.
  - Missing optional content/style keys should not break public pages.
  - Client copy changes should not break tests that only need behavioral assertions.

## Captured UX polish backlog

These notes are intentionally retained while the schema-discovery phases continue. Do not pull them ahead of higher schema-risk work unless implementation proves a missing table, registry, provider, or runtime service is needed.

### Shared field/token insertion

- [ ] Add a shared available-field/token picker pattern for message/template/config authoring surfaces.
  - Use client-facing language such as `Insert field` or `Add field`, not `token`, on normal operator screens.
  - Preserve cursor/focus in the input/textarea when the picker opens.
  - Provide autocomplete search from available fields for the current context.
  - Insert stable hidden syntax such as `{{ first_name }}` or normalize to the current runtime token format.
  - Do not let users select fields that the current message/context cannot resolve.
  - Treat the available-field source of truth as Phase 6 validation/registry work.

### Contextual hints

- [ ] Add a reusable hover/focus hint pattern for confusing fields, settings, and navigation items.
  - Hints should explain what the setting does in plain language.
  - Hints should not expose schema/config/event keys as the main explanation.
  - Hints do not replace consequence previews for automation-triggering changes.

### Route Management / Routes language

- [ ] During Phase 4, decide public/client-facing FlowRoutes terminology.
  - Candidate navigation label: `Route Management`.
  - Candidate plain-language hint: `Choose what automatic actions happen after important contact activity.`
  - Keep `FlowRoutes` in developer/module docs where precision matters.

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

