# Engage Core TODO

This file is intentionally disposable. Add work here when it is real but not yet ready for an implementation slice. Delete items as they are completed. Do not treat this as an architectural reference; long-lived decisions belong in `module-boundaries.md` or a feature-specific doc.

## Run through after completing an item or system update

These are repeatable checklists. Run the relevant checklist after a production slice, config update, client setup change, or staging deployment. Do not leave one-off feature work here; put one-off work in the backlog sections below.

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
- [ ] Confirm Campaign preset step message references use first-class `channel`, `purpose`, and `scope` keys.
- [ ] Confirm Messaging templates live under the expected channel/purpose/scope path.
- [ ] Confirm SMS visibility is controlled by config where the surface exposes channel choices.
- [ ] Confirm missing optional content/style keys do not break public pages.
- [ ] Confirm tests are tolerant of client copy changes unless exact copy is the behavior under test.

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

## One-off backlog

### Permission invitations

- [ ] Finish SMS visibility/config cleanup before expanding channel UI.
  - SMS should always exist in code.
  - SMS should only appear in Broadcasts, Campaigns, permission invitation pages, and other client/admin surfaces when enabled by config.
  - Permission invitation page should hide the SMS option when SMS is disabled for that client.

- [ ] Verify the permission invitation email rendering end-to-end.
  - Confirm the CTA button/link renders cleanly through `EmailPayload` and `resources/views/messaging/email.blade.php`.
  - Confirm the runtime URL is injected into the email payload before provider send.
  - Confirm client copy can reference the opt-in URL without hard-coding it.

- [ ] Add/refine public opt-in tests as needed after UI polish.
  - Invalid token returns 404.
  - Email-only consent creates expected rows.
  - SMS consent requires a phone number.
  - Email + SMS consent creates expected rows.
  - Already accepted invitation cannot create duplicate consent rows.
  - Accepted page is copy-config tolerant.

- [ ] Decide whether permission invitation accepted consent should apply to only `broadcast` and `campaign`, or whether additional scopes are needed before launch.

- [ ] Consider whether accepted invitations should emit a neutral automation event later.
  - Example: `permission_invitation.accepted`.
  - Consumers could update contact status, create a task, enroll a campaign, or notify the team.
  - Do not make Messaging depend on those consumers.

### Broadcasts

- [ ] Polish Broadcast index UI after SMS toggle/config cleanup.
  - Keep regular broadcasts separate from imported-contact opt-in invitations.
  - Avoid making opt-in invitations look like a normal marketing broadcast.
  - Make status/type labels simple for client users.

- [ ] Consider adding a clearer imported-contact count/preview before scheduling an opt-in invitation.
  - Should be Core contact-filter backed.
  - Should not duplicate contact query logic in Broadcasts.

- [ ] Confirm cancellation behavior for permission invitations.
  - Cancelling pending scheduled messages should not create or mark accepted invitation rows.
  - Already sent invitation rows remain historical and still block repeat bypass sends.

### Core contact filters

- [ ] Expand Core contact filter tests for the `imported` filter type.
  - `source = import`.
  - `meta.imported = true`.
  - `meta.imported_at` present.
  - Non-imported contacts excluded.

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

- [ ] Add config validation guidance.
  - Required keys.
  - Unsupported keys.
  - Token validation.
  - SMS visibility/channel availability.
  - Safe defaults when a client config omits optional values.

### SMS toggleability

- [ ] Add config-driven channel availability rules.
  - Global SMS enabled/disabled.
  - Surface-specific SMS visibility, such as Broadcasts, Campaigns, permission invitations, internal notifications.
  - Distinguish “code supported” from “client UI option visible.”

- [ ] Ensure SMS hiding does not remove backend protections.
  - Consent gates still enforce SMS rules.
  - Suppression/revocation still works.
  - Inbound STOP/HELP handling still works if provider/webhook is active.

- [ ] Confirm permission invitation SMS opt-in remains explicit.
  - No automatic SMS consent from email invitation open/click.
  - SMS checkbox requires a phone number.
  - SMS option can be hidden by config.

### Imports and contact onboarding

- [ ] Define the import flow that marks contacts as imported.
  - `source = import` and/or `meta.imported = true` / `meta.imported_at`.
  - Confirm whether import batches need a first-class model later.
  - Confirm whether imported contacts should receive a default tag.

- [ ] Consider an import batch filter later.
  - “Imported contacts” is now broad.
  - Future UX may need “contacts from this import only.”
  - Do not build this until import batch modeling exists.

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

- [ ] Keep Tasks standalone-capable.
  - Tasks can be related to contacts but must not require contacts.
  - `responsible_party` remains action owner; `assigned_to` remains internal follow-up owner/tracker.

### FlowRoutes / Routes

- [ ] Keep Route builder UX simple.
  - Client should be able to add a Route point and associated tasks quickly.
  - Avoid exposing internal complexity.
  - FlowRoutes should not model every campaign email/text as separate points.

- [ ] Later: make FlowRoutes respond to task completion through neutral events.
  - FlowRoutes should advance when a related route task is completed.
  - It should ignore unrelated task changes.

### Documentation maintenance

- [ ] Regenerate `core-project-tree.txt` from the repo after this branch settles.
  - Do not hand-maintain it.
  - Include new Messaging permission invitation controller/request/model/service/views/tests/migrations.

- [ ] Keep `module-boundaries.md` architectural, not a backlog.
  - Move actionable backlog items into this TODO file.
  - Delete completed TODOs instead of accumulating historical notes.

- [ ] Add/update feature-specific docs when a feature crosses module boundaries.
  - Permission invitations already has a dedicated doc.
  - Similar docs may be useful for Broadcasts, Campaigns, FlowRoutes, Tasks, and Imports once each stabilizes.

### Testing backlog

- [ ] Add test coverage for SMS visibility config once implemented.
  - SMS option hidden when disabled.
  - SMS option visible when enabled.
  - Backend gate behavior unchanged regardless of UI visibility.

- [ ] Add test coverage for client config fallback behavior.
  - Missing optional content/style keys should not break public pages.
  - Client copy changes should not break tests that only need behavioral assertions.