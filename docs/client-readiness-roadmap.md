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

## Pre-prod schema-discovery ordering lens

Before production rollout, prioritize the remaining client-readiness work by its likelihood of revealing durable table, migration, or module-boundary changes.

This is not a separate product track and not a reason to build temporary scaffolding. It is a temporary pre-production ordering lens for finishing real client-readiness work while branch migrations can still be replaced safely.

Use this priority order when choosing the next phase:

1. Likelihood of directly impacting DB/schema.
2. Likelihood of impacting codebase architecture, module seams, public actions/services, events, or runtime resolvers.
3. UI/UX polish.

UI/UX polish can still reveal missing persisted concepts, such as acknowledgements, catalog records, saved preferences, or setup-state records. It is simply less likely to reveal schema changes than unresolved runtime-definition, schedule, variant, task, or route-resume behavior.

Current schema-discovery sequence:

| Phase | Item | Primary discovery risk | Notes |
| -: | --- | --- | --- |
| 1 | Webinar schedule profiles | DB/schema | Decide whether webinar-owned message timing needs DB-owned profiles/items and whether profiles attach globally, by series, or by webinar. |
| 2 | Campaign channel variants | DB/schema | Decide whether campaign steps need channel-specific variants and delivery strategies before production. |
| 3 | Task templates / task defaults | DB/schema | Confirm task template fields can support generated/manual task creation from presets, FlowRoutes, and vertical modules. |
| 4 | FlowRoutes event-wait / task-completed resume behavior | DB/schema + architecture | Confirm existing route progress metadata can safely resume from neutral `task.completed` events, or add wait-correlation records. |
| 5 | Config validation / setup validation | Architecture | Add validation/reporting for unsafe config, unsupported keys, invalid tokens, and missing runtime references before client handoff. |
| 6 | Permission invitation accepted automation event | Architecture | Decide whether accepted invitations emit a neutral event such as `permission_invitation.accepted` without Messaging depending on consumers. |
| 7 | Permission invitation cancellation / skip / failure bookkeeping | DB/schema + architecture | Clarify durable lifecycle state across permission invitations, Broadcast bookkeeping, and Messaging scheduled messages. |
| 8 | Webinar message readiness check | Architecture + operator safety | Add computed readiness visibility for webinar message setup without persisting setup state unless a concrete need appears. |
| 9 | Manual status-change automation warning | Operator safety | Warn before manual status changes run selected status-based FlowRoutes; avoid schema unless audit/acknowledgement state is proven necessary. |
| 10 | Automatic Follow-ups / FlowRoutes UX polish | UI/UX + architecture | Redesign Route Binding around business outcomes, consequence previews, and client/operator language after runtime behavior is safer. |
| 11 | Dashboard / contact workspace polish audit | UI/UX + possible schema | Review orientation surfaces after core runtime pieces settle; add persisted preferences/acknowledgements only when needed. |
| 12 | FOSS-informed module schema audit | DB/schema audit | Compare Engage Core modules against mature FOSS patterns to identify likely missing persisted concepts before production. |

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

## Near-term sequencing rule

Client-readiness implementation should chase one main item at a time unless two active threads are intentionally isolated by file ownership.

The current sequence is the pre-prod schema-discovery sequence above.

Start with Webinar schedule profiles, then continue through Campaign channel variants, Task template/default checks, FlowRoutes event-wait/task-completed resume behavior, and the remaining phases in order unless a concrete client need changes the risk ranking.

Do not run parallel threads that modify the same controllers, views, routes, services, migrations, or tests unless one thread is paused and rebased onto the other.

## Working roadmap

Use the pre-prod schema-discovery sequence as the current implementation order.

| # | Planned item | Rough estimate | Notes |
| -: | --- | ---: | --- |
| 1 | Webinar schedule profiles | 1–3 sessions | Highest remaining Webinars-side schema question. Decide DB-owned profiles/items vs config-only, and whether selection attaches globally, by webinar series, or by webinar. |
| 2 | Campaign channel variants | 2–4 sessions | Decide whether Campaign steps need channel-specific variants and strategy fields before production. Variants must reference Messaging-owned templates/assignments and must not own copy. |
| 3 | Task templates / task defaults | 1–2 sessions | Audit whether `task_templates` supports generated/manual tasks well enough for FlowRoutes and vertical modules. Build UI only if needed. |
| 4 | FlowRoutes event-wait / task-completed resume behavior | 0.5–1.5 sessions | Resume route event-wait points from neutral `task.completed` automation events, not direct Task-specific FlowRoutes listeners. Confirm whether existing progress metadata is enough. |
| 5 | Config validation / setup validation | 0.5–1.5 sessions | Convert config-template expectations into practical validation behavior and operator/debug feedback. Prefer command/service-based validation first. |
| 6 | Permission invitation accepted automation event decision | 0.25–0.5 session | Decide whether accepted invitations should emit a neutral automation event such as `permission_invitation.accepted`. |
| 7 | Permission invitation cancellation behavior | 0.5–1 session | Clarify how cancellation/skip/failure should appear for permission-invitation Broadcast bookkeeping and Messaging scheduled messages. |
| 8 | Webinar message readiness check | 0.5–1 session | Computed readiness summary for Webinars message setup. Do not persist readiness/acknowledgement state unless the implementation proves a durable concept is missing. |
| 9 | Manual status-change automation warning | 0.5–1 session | Warn operators before a manual status change runs a selected status FlowRoute. This is a UI awareness guardrail, not a ContactStatus schema split. |
| 10 | Automatic Follow-ups / FlowRoutes UX polish | 1–3 sessions for first product pass | Redesign the current Route Binding surface around business outcomes and consequence previews before route-builder expansion. |
| 11 | Dashboard / contact workspace polish audit | 1–2 sessions | Review shared orientation surfaces after runtime behavior settles. Add persisted state only for proven needs such as acknowledgements or preferences. |
| 12 | FOSS-informed module schema audit | 2–6 sessions, split by module group | Compare Engage Core module tables against mature FOSS patterns to catch likely missing persisted concepts before production. |
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


### Webinars message/template setup

Completed functional baseline:

- Webinars has a Webinars-owned message setup surface for registration confirmations, reminders, waitlist availability messages, post-attended transactional follow-ups, and post-missed transactional follow-ups.
- The surface shows the currently selected Messaging template per webinar message context.
- Operators can choose compatible `MessageTemplatePreset` records from the Webinars setup surface.
- Selection saves through `MessageTemplatePresetAssignment` while copy editing remains on the Message Templates page.
- Runtime resolution remains DB-first with config fallback so existing registration confirmation/reminder behavior is preserved during migration.
- This is a functional setup UI, not the final UX-polished communication-plan experience.
- Schedule/profile/timing selection remains a Webinars-owned follow-up item.

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

The next implementation target is Webinar schedule profiles.

The purpose of this phase is to decide whether webinar-owned message timing needs DB-owned schedule profile records before production, or whether config-only timing remains sufficient for the first rollout.

Questions to settle:

```text
Are schedule profiles DB-owned or config-only for now?
Do profiles attach globally, by client, by webinar series, or by webinar?
Do confirmations, reminders, waitlist availability messages, and post-event transactional follow-ups share one profile model or separate profile categories?
Do schedule profile items reference message_type, template assignment context, channel/purpose/scope, or a normalized schedule item key?
Can existing scheduled messages remain stable once created while future registrations use the newly selected profile?
```

Likely schema candidates:

```text
webinar_schedule_profiles
webinar_schedule_profile_items
webinar_series.schedule_profile_id
webinars.schedule_profile_id
```

Prefer a small durable decision over a polished UI. If a schema change is needed, make it before production. If config-only is enough for first rollout, document that decision and move to Campaign channel variants.

Automatic Follow-ups / FlowRoutes UX polish remains later in the schema-discovery sequence. Do not start it until the higher-risk schedule, variant, task-template, route-resume, config-validation, and permission-invitation pieces are settled or deliberately deferred.

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
