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
- [ ] Confirm unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
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
- [ ] Confirm prior-Broadcast exclusions prevent duplicate outreach across related single-channel Broadcasts.

## One-off backlog

### Permission invitations

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

- [ ] Add standard SMS Broadcast authoring.
  - Broadcasts should remain single-channel.
  - Regular Broadcast creation/editing should allow `email` or `sms` only when Messaging channel availability exposes that channel for the `broadcasts` surface.
  - Permission invitation Broadcasts remain email-only.
  - Email Broadcast payloads should use `subject` and `body`.
  - SMS Broadcast payloads should use `message`.
  - The UI should cleanly swap email/SMS fields when the selected channel changes.

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
  
- [ ] Wire Messaging channel availability into future channel-choice builder UIs as they are added.
  - Campaign builder.
  - Broadcast builder, if SMS authoring is exposed later.
  - FlowRoute/Route send-message point builder.
  - Internal notification preference UI.
  - Show channel preference controls only when more than one channel is available for that surface.

### Imports and contact onboarding

- [ ] Add import-time status mapping behavior.
  - Imports may contain multiple legacy/client status values from the old system.
  - The import flow should let an operator map each incoming status to an existing Engage Core `ContactStatus`.
  - The import flow should let an operator convert a legacy status to an Engage Core equivalent.
  - The import flow should let an operator flag unmapped/ambiguous statuses for review.
  - Flagged rows should not silently receive the wrong status.
  - Preserve the original imported status in metadata for audit/debugging.

- [ ] Consider a first-class import batch model only if derived import cohorts are not enough.
  - Current imported-contact cohorts are derived from stable Contact-owned facts:
    - `source = import`
    - `meta.imported = true`
    - `meta.imported_at` present
  - Broadcasts can combine imported-contact resolution with Messaging consent state for permission-invitation eligibility.
  - Broadcasts can exclude contacts already scheduled/sent in prior Broadcasts through `recipient_filter.exclude`.
  - “Imported contacts” is still broad.
  - Future UX may need “contacts from this exact import file/run only.”
  - Do not build first-class import batches until derived cohorts and prior-Broadcast exclusions prove insufficient.

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

- [ ] Keep Route builder UX simple.
  - Client should be able to add a Route point and associated tasks quickly.
  - Avoid exposing internal complexity.
  - FlowRoutes should not model every campaign email/text as separate points.
  - This is not beta-blocking unless beta clients need to build or edit Routes themselves.

- [ ] Later: make FlowRoutes respond to task completion through neutral events.
  - FlowRoutes should advance when a related route task is completed.
  - It should ignore unrelated task changes.

### Universal module planning

- [ ] Plan the Scheduling universal module.
  - Keep Scheduling vertical-neutral.
  - Support simple appointment/session/booking behavior.
  - Dog training sessions, consultations, lessons, coaching, and studio bookings should all fit the generic model.
  - Scheduling may use Messaging for reminders, Tasks for appointment-related follow-up, Portal for customer self-booking, and integrations for external calendar sync later.
  - PetServices, Music, Mortgage, or other verticals should own domain-specific meaning around scheduled appointments.

- [ ] Plan the Portal universal module.
  - Keep Portal separate from internal app users.
  - Own external/customer account identity, contact links, account invitations, portal auth, and generic portal dashboard/access behavior.
  - Scheduling, Forms, Documents, Commerce, and vertical modules may contribute portal-facing surfaces later.
  - Do not push portal account state into Core contacts.

- [ ] Plan the Documents universal module.
  - Own document requests, uploaded document records, review events, and generic document lifecycle state.
  - Support dog vaccination records, waivers, mortgage documents, music contracts/assets, and general customer uploads.
  - Vertical modules should own domain-specific document requirements and interpretation rules.

- [ ] Plan the Commerce universal module.
  - Own normalized products, orders, order items, customer/contact links, external IDs, sync metadata, and purchase events.
  - Shopify should be an adapter behind Commerce, not a Music-owned provider dependency.
  - Music can consume Commerce to answer questions like whether a contact bought a specific product.
  - Do not store purchase history directly on Core contacts.

- [ ] Revisit Location as a universal module when location-aware behavior becomes necessary.
  - Own contact locations, city/state/zip/country, latitude/longitude, markets/regions, radius filters, and service-area behavior.
  - Useful for music show-radius targeting, dog trainer service areas, scheduling eligibility, and future local campaigns.
  - Do not push broad address/location behavior into Core by default.

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

- [ ] Add test coverage for client config fallback behavior.
  - Missing optional content/style keys should not break public pages.
  - Client copy changes should not break tests that only need behavioral assertions.
