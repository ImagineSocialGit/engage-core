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

### Staging smoke test checklist

- [ ] Create/import at least one test contact.
- [ ] Confirm Core contact lookup/contact picker works where used.
- [ ] Create a regular Broadcast draft and confirm it remains consent-gated.
- [ ] Create an imported-contact opt-in invitation and confirm it is separate from normal Broadcasts.
- [ ] Send/schedule the opt-in invitation to an imported test contact.
- [ ] Confirm the invitation email renders the CTA/link correctly.
- [ ] Open the public preference page from the email link.
- [ ] Confirm email-only opt-in creates the expected consent records.
- [ ] Confirm SMS opt-in requires and stores a phone number when SMS is enabled.
- [ ] Confirm re-opening an accepted invitation shows the accepted state.
- [ ] Confirm repeat invitation attempts are blocked by the one-time invitation record.
- [ ] Confirm cancellation/skip/failure states remain understandable in Broadcast and ScheduledMessage views.
- [ ] Confirm Broadcast detail pages show scheduled/skipped/failed recipient outcome visibility and useful skip/failure reasons.
- [ ] Confirm prior-Broadcast exclusions prevent duplicate outreach across related single-channel Broadcasts.

### Roadmap tracking

- [ ] Keep `docs/client-readiness-roadmap.md` current as the focused client-readiness implementation roadmap.
  - Use `TODO.md` for disposable backlog/checklists.
  - Use the roadmap for implementation order and session planning.
  - Do not treat roadmap items as temporary MVP shortcuts.
  - Delete completed roadmap items or move them back to TODO when they are no longer near-term.

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

- [ ] Refine dashboard and contact show visuals later after more real usage.
  - Keep changes calm and action-oriented.
  - Preserve module tones as wayfinding, not urgency.
  - Avoid turning either surface into a module cockpit.

### Permission invitations

Completed baseline:

- Imported-contact permission invitations can be scheduled from Core import batch detail pages when Messaging is enabled.
- Messaging owns the import-batch scheduling action, eligibility checks, scheduled-message creation, send-time invitation claiming, token injection, public preference handling, and consent creation.
- The one-time invitation send remains email-only.
- Repeat scheduling is blocked when a contact already has a pending/sent imported-contact permission invitation scheduled message or an imported-contact email permission invitation row.
- The import batch detail page shows current-page permission invitation visibility for imported contacts.

- [ ] Add/refine public opt-in tests as needed after UI polish.
  - Invalid token returns 404.
  - Email-only consent creates expected rows.
  - SMS consent requires a phone number.
  - Email + SMS consent creates expected rows.
  - Already accepted invitation cannot create duplicate consent rows.
  - Accepted page is copy-config tolerant.

- [ ] Consider whether accepted invitations should emit a neutral automation event later.
  - Example: `permission_invitation.accepted`.
  - Consumers could update contact status, create a task, enroll a campaign, or notify the team.
  - Do not make Messaging depend on those consumers.

### Broadcasts

Completed baseline:

- Regular Broadcast creation/editing supports email or SMS when Messaging channel availability exposes the channel for the `broadcasts` surface.
- Broadcasts remain single-channel.
- Permission invitation Broadcasts remain email-only.
- Email Broadcast payloads use `subject` and `body`.
- SMS Broadcast payloads use `message`.
- SMS Broadcast scheduling hydrates recipient phone, channel, purpose, scope, and message type through Messaging.
- Broadcast detail pages show recipient outcome counts and per-recipient skip/failure reasons.

- [ ] Revisit Broadcast cancellation/completion lifecycle if operator visibility needs more polish after staging smoke tests.
  - Keep Broadcast recipient state as Broadcast bookkeeping.
  - Use Messaging-owned skip behavior for pending scheduled messages.
  - Do not mutate Messaging scheduled-message internals directly from Broadcasts.

### Internal Messaging
- Get internal messaging working for dashboard broadcasts of task lists

### Core contact filters

- [ ] Decide whether Core contact filter normalization should support additional stable Core-owned filters.
  - Status, tags, source, subsource, last activity/contacted timestamps.
  - Avoid adding feature-specific filters directly to Core.
  - Future module-specific filters should use explicit provider/registry seams.

### Client config and templates

- [ ] Audit all current config/config types that clients can override.
  - Content configs.
  - Styling configs.
  - Token-bearing configs.
  - Messaging configs.
  - Campaign configs.
  - Broadcast-related configs.
  - Permission invitation configs.
  - FlowRoute/Route configs.
  - Task template configs.
  - Contact status configs.
  - Any public-page/view configs.

- [ ] Create standard templates for every supported client-facing config type.
  - Each template should show every supported key.
  - Each template should include safe defaults.
  - Each template should document allowed tokens.
  - Each template should avoid stale/legacy aliases unless explicitly retained.

- [ ] Build a clear “future client setup” config checklist.
  - Which files are required.
  - Which files are optional.
  - Which files are vertical-specific.
  - Which files are provider-specific.
  - Which files are safe for nontechnical editing.

- [ ] Make token documentation exhaustive and current.
  - Permission invitation tokens.
  - Contact tokens.
  - Webinar tokens.
  - Campaign tokens.
  - Broadcast/message tokens.
  - Any public URL tokens.
  - Clarify which tokens are runtime-only and should not be guessed in static config.

- [ ] Add a config validation command.
  - Suggested command: `php artisan config:validate-engage`.
  - Validate default configs and optional client configs using runtime fallback rules.
  - Report hard errors for unsafe/malformed config.
  - Report warnings for deprecated tokens, review-needed keys, optional omissions, and hidden-but-configured SMS surfaces.
  - Include config path, severity, reason, and suggested fix when possible.
  - Start with Messaging, Permission Invitations, Campaign presets, FlowRoute presets, Task presets, and reference registries.

### Runtime-selectable definitions

Completed FlowRoute baseline:

- DB-backed `FlowRouteTriggerBinding` records select runtime FlowRoute behavior.
- `FlowRoute.is_active` means available/allowed, while trigger bindings own selection.
- FlowRoute owner fields exist: `owner_type`, `owner_id`, and `owner_group`.
- CRM exposes a simple status/event Route Bindings selector.
- Contact-status triggers are single-selection in the current CRM UI.
- Automation-event triggers may select multiple routes for the same event.

Completed Messaging template baseline:

- DB-backed `MessageTemplatePreset` records own synced/editable reusable message copy.
- DB-backed `MessageTemplateCatalogEntry` records organize templates for browsing by channel, purpose, module/area, group, and item.
- DB-backed `MessageTemplatePresetAssignment` records own selected runtime template behavior.
- Sync creates presets, catalog entries, and default assignments from message configs.
- Runtime resolvers prefer selected DB presets before config fallback.
- Normal sync does not overwrite customized DB copy unless explicitly forced.

Completed Messaging/Campaign setup UI baseline:

- Message Templates UI uses catalog entries to filter and browse by channel, purpose, area/module, group, and message/step.
- Message Templates remains copy/review-only and keeps assignment mutation out of the template editor.
- Message Templates shows read-only “Used by” entries.
- Campaign Message Templates lets operators select the active Messaging template for each Campaign step.
- Campaign usage rows can link from Message Templates to the Campaign Message Templates setup surface.
- Campaign Message Templates links back to Message Templates for copy editing.

- [ ] Build Webinars message/template/schedule setup.
  - Show confirmation, reminder, waitlist, post-attended, and post-missed contexts.
  - Show the active selected Messaging template for each context.
  - Let operators choose compatible `MessageTemplatePreset` records from the Webinars-owned setup surface.
  - Save/update `MessageTemplatePresetAssignment`.
  - Link to Message Templates for copy editing.
  - Preserve config fallback while DB-owned assignments become the primary runtime path.
  - Keep Webinars responsible for schedule/profile/timing decisions.

- [ ] Add selectable webinar schedule profiles.
  - Confirmation schedules.
  - Reminder schedules.
  - Post-event transactional follow-up schedules.
  - Assign profiles globally, by client, by webinar series, or by webinar when needed.

- [ ] Add Campaign step variant architecture.
  - Campaign enrollment is the lifecycle.
  - Campaign step is the business moment.
  - Step variant is the channel-specific delivery option.
  - Support `first_available`, `send_all_eligible`, and `dependency_aware`.
  - Variants reference Messaging templates/assignments and do not own copy.

- [ ] Make Route selection/building capability-aware.
  - Hide or disable Campaign point types when Campaigns is not enabled.
  - Hide or disable Messaging send-message point types when Messaging is not enabled.
  - Hide or disable Task point types when Tasks is not enabled.
  - Keep the Route Bindings UI focused on selecting available FlowRoutes, not explaining unavailable module internals.

### SMS toggleability

Completed baseline:

- Messaging owns canonical channel availability rules.
- SMS code/runtime capability can exist while SMS is hidden from client/admin UI surfaces.
- Broadcast builder uses Messaging channel availability for the `broadcasts` surface.
- Permission invitations remain email-only for the one-time bypass send.
- SMS opt-in remains explicit on the public preference page when exposed by config and requires a phone number.
- Hiding SMS from a UI surface does not remove backend protections.

- [ ] Wire Messaging channel availability into future channel-choice builder UIs as they are added.
  - Campaign builder.
  - FlowRoute/Route send-message point builder.
  - Internal notification preference UI.
  - Show channel preference controls only when more than one channel is available for that surface.

- [ ] Add operator/debug validation around unsupported channel/surface combinations as config validation matures.

### Imports and contact onboarding

Completed import-time status mapping baseline:

- Import preview can map incoming legacy/client status values to active Core `ContactStatus` records.
- Original imported status values are preserved in contact metadata.
- Unmapped statuses are flagged for review instead of silently assigning the wrong status.
- Missing status values are tracked separately from unmapped values.
- Current contact status assignment still flows through the Workflow-owned status profile seam when Workflow is enabled.

Completed import-batch visibility baseline:

- `contact_import_batches` exists as a first-class Core model/table.
- Core provides simple CRM list/detail visibility for import batches.
- Broadcasts can target selected import batches through `recipient_filter.type = import_batch`.
- Broadcast recipient-filter display can link to Core-owned import-batch detail pages.
- Broadcasts consume import batches but do not own import-batch management.

### Automation/event seams

- [ ] Revisit neutral automation events after permission invitations are stable.
  - Accepted invitation could emit a generic event.
  - Task completion could advance FlowRoutes.
  - Webinar ended could trigger admin/team tasks.
  - Keep the middle seam neutral; avoid making producer modules aware of consumers.

### Tasks

- [ ] Build or refine UI for task templates/default definitions if needed.
  - Preset sync creates DB-owned/default task template definitions only.
  - No live tasks are created by preset sync.
  - This is not beta-blocking unless clients need to manage task templates themselves.

- [ ] Keep Tasks standalone-capable.
  - Tasks can be related to contacts but must not require contacts.
  - `responsible_party` remains action owner; `assigned_to` remains internal follow-up owner/tracker.

### FlowRoutes / Routes

Current sequence note: handle the Webinars message/template/schedule setup first, then make Automatic Follow-ups / FlowRoutes UX improvements the next implementation focus.

- [ ] Explore Automatic Follow-ups / Route Binding UX before implementation.
  - Audit current binding UI against `docs/ui-ux-guide.md`.
  - Decide whether the page is selection-only, edit-capable, or split into simple/advanced modes.
  - Define how route point consequences are summarized.
  - Define what raw keys remain visible as secondary diagnostics.
  - Define capability-aware behavior when Campaigns, Messaging, Tasks, or Webinars are disabled.

- [ ] Add CRM manual status-change confirmation when the selected status has automation attached.
  - Show the selected Route name.
  - Summarize major route actions where feasible.
  - Let the operator cancel or proceed intentionally.
  - Do not add ContactStatus manual-only or automation-only schema yet.

- [ ] Keep Route builder UX simple.
  - Client should be able to add a Route point and associated tasks quickly.
  - Avoid exposing internal complexity.
  - FlowRoutes should not model every campaign email/text as separate points.
  - This is not beta-blocking unless beta clients need to build or edit Routes themselves.

- [ ] Later: make FlowRoutes respond to task completion through neutral events.
  - FlowRoutes should advance when a related route task is completed.
  - It should ignore unrelated task changes.

### Universal module foundations

- [ ] Add public seams for Scheduling, Portal, Forms, Documents, Commerce, or Location only when a concrete consumer/workflow needs them.
- [ ] Add Commerce/Location contact filter providers only when Broadcasts, Campaigns, Reporting, or another consuming surface actually needs purchase/location targeting.
- [ ] Keep newly founded universal modules out of Core; use module-owned tables and future public seams instead.



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
  - Keep pet-specific fields out of Core contacts.

- [ ] Plan the Music vertical module.
  - Own music-specific fan/customer meaning, release/fan campaign strategy, music product interest categories, and music-specific segmentation rules.
  - Consume Commerce for Shopify/purchase facts.
  - Consume Location later for show-radius targeting if needed.
  - Consume Campaigns, Broadcasts, Messaging, and FlowRoutes for fan communication/automation.
  - Keep music-specific purchase/interest state out of Core contacts unless represented through a proper universal module relation.

### Documentation maintenance

- [ ] Regenerate `core-project-tree.txt` from the repo after structural module/file changes.
  - Do not hand-maintain it.
  - Include new Messaging permission invitation controller/request/model/service/views/tests/migrations.

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
