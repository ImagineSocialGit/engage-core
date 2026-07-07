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

The imported-contact onboarding, Broadcast visibility, dashboard, and contact workspace foundations are now in place:

- Core owns first-class import batch records and CRM import-batch visibility.
- Broadcasts can target all imported contacts or selected import batches.
- Permission invitations are email-only one-time imported-contact invitations owned by Messaging.
- Normal Broadcasts are single-channel email or SMS sends and remain consent-gated through Messaging.
- SMS Broadcast authoring is available only when Messaging channel availability exposes SMS for the `broadcasts` surface.
- Broadcast scheduling records recipient outcomes and exposes scheduled/skipped/failed visibility on the Broadcast detail page.
- The CRM dashboard is config-driven by module slots, panel providers, and preset priorities.
- The contact show page is a Core-owned workspace shell with module-contributed panels/sections and muted module wayfinding.

## Architecture runway after staging smoke success

The staging smoke test confirmed the near-term need for runtime-selectable definitions.

The next architecture runway is:

```text
sync available options
select active options in CRM/admin
resolve selected DB-owned options at runtime
```

Near-term candidates:

- Messaging template presets, catalog entries, and assignments.
- Selectable webinar schedule profiles.
- Campaign channel variants.
- Task template/default definition UI, if clients/operators need to manage task templates themselves.
- Guided FlowRoutes route-builder UX later, after selected bindings and safer runtime UI are stable.

Completed runway pieces:

- FlowRoute owner morph and trigger bindings.
- CRM selection for status/event FlowRoutes.
- Messaging template presets, catalog entries, and DB-first assignment resolution foundation.
- Message Templates catalog/copy-editing UI using catalog entry grouping.
- Campaign Message Templates assignment UI and access links for selecting active campaign-step templates.

The remaining runway pieces should continue to be implemented as durable client-readiness work, not as smoke-test shortcuts.

## Near-term sequencing rule

Client-readiness implementation should chase one main item at a time unless two active threads are intentionally isolated by file ownership.

The current sequence is:

```text
1. Webinars message/template/schedule setup.
2. Automatic Follow-ups / FlowRoutes UX improvements.
```

Do not run parallel threads that modify the same controllers, views, routes, services, migrations, or tests unless one thread is paused and rebased onto the other.

## Working roadmap

| # | Planned item | Rough estimate | Notes |
| -: | --- | ---: | --- |
| 1 | Webinar message/template/schedule setup | 1–3 sessions | Build the Webinars-side setup surface for confirmation, reminder, waitlist, and post-event message contexts. Webinars own scheduling/profile decisions; Messaging owns reusable copy, catalog entries, assignments, and delivery infrastructure. |
| 2 | Automatic Follow-ups / FlowRoutes UX improvements | 1–3 sessions for first product pass | Redesign the current Route Binding surface around business outcomes after the Webinars setup slice. Start with selection/preview UX before route-builder expansion. |
| 3 | Permission invitation accepted automation event decision | 0.25–0.5 session | Decide whether accepted invitations should emit a neutral automation event such as `permission_invitation.accepted`. |
| 4 | Permission invitation cancellation behavior | 0.5–1 session | Clarify how cancellation/skip/failure should appear for permission-invitation Broadcast bookkeeping and Messaging scheduled messages. |
| 5 | Config validation guidance | 0.5–1 session | Convert current config-template expectations into practical validation behavior and operator/debug feedback. |
| 6 | Manual status-change automation warning | 0.5–1 session | Warn operators before a manual status change runs a selected status FlowRoute. This is a UI awareness guardrail, not a ContactStatus schema split. |
| 7 | Task template/default definition UI | 1–2 sessions, maybe more if polished | Only needed when clients/operators need to manage task templates themselves. Preset sync already creates DB-owned definitions only. |
| 8 | Campaign channel variants | 2–4 sessions | Add step-group/channel-variant support only after the current single-channel campaign-step template selection path remains stable. |
| 9 | FlowRoutes route-builder UX | 3–6 sessions | Only after the Automatic Follow-ups exploration/product pass. Follow `ui-ux-guide.md`: guided, outcome-oriented, and not a blank-canvas automation builder. |
| 10 | Task-completed FlowRoutes resume behavior | 0.5–1 session | Resume route event-wait points from neutral `task.completed` automation events, not direct Task-specific FlowRoutes listeners. |
| 11 | Client self-serve readiness audit | 0.5–1 session | Separate controlled beta/operator-assisted readiness from true client self-serve readiness. |
| 12 | PetServices vertical planning | 0.5–1 session | Plan vertical-owned pet/service concepts without pushing domain fields into Core. |
| 13 | Music vertical planning | 0.5–1 session | Plan vertical-owned music/fan/product-interest concepts using Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Scheduling, Portal, and Reporting as needed. |
| 14 | Feature-specific docs as modules stabilize | Ongoing | Keep module docs current when architecture/operator behavior changes. Do not turn docs into speculative backlog. |
| 15 | Client config fallback tests | 0.5–1 session | Verify default/client config fallback, numeric-array replacement, optional content/style safety, and copy-tolerant tests. |

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

The next implementation target is the Webinars-side message/template/schedule setup slice.

Build this before Automatic Follow-ups / FlowRoutes UX improvements.

Webinars should provide the owning setup surface for webinar message contexts such as:

```text
registration confirmation
reminders
waitlist availability messages
post-attended transactional follow-up
post-missed transactional follow-up
```

The first durable version should:

- show the current selected Messaging template for each webinar message context;
- allow selecting a compatible `MessageTemplatePreset`;
- save/update `MessageTemplatePresetAssignment`;
- link to Message Templates for copy editing;
- preserve DB-first resolution with config fallback during migration;
- keep Webinars responsible for schedule/profile/timing decisions;
- keep Messaging responsible for reusable copy, catalog entries, assignments, and delivery infrastructure.

The following implementation target is Automatic Follow-ups / FlowRoutes UX improvements.

That next pass should redesign the current Route Binding surface around business outcomes and consequence previews before expanding into route point editing or a fuller route-builder UI.

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
- making a single normal Broadcast fan out to email and SMS without deliberate future channel-strategy work.

## Relationship to TODO.md

`TODO.md` remains the disposable backlog/checklist file.

Keep roadmap-level ordering here.

Keep repeatable checklists and lower-priority backlog in `TODO.md`.

When a roadmap item is completed:

1. Remove it from this file or move it to a completed release note if needed.
2. Delete or update the related TODO item.
3. Update module docs only if architecture or durable behavior changed.
