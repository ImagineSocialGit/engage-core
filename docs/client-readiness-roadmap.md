# Engage Core Client-Readiness Roadmap

This roadmap tracks the near-term implementation order for getting Engage Core ready for real client operation without treating the work as a limited or throwaway MVP.

The goal is not to build temporary shortcuts.

The goal is to finish durable, client-ready workflows in the right order while preserving the module boundaries, config shapes, consent rules, and product principles already established.

Use this file for implementation order and session planning.

Use `docs/TODO.md` for broader disposable backlog items, repeatable checklists, and lower-priority future work.

Use `docs/module-boundaries.md` and `docs/modules/*.md` for long-lived architecture and module ownership decisions.

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

The imported-contact onboarding and Broadcast visibility foundation is now in place:

- Core owns first-class import batch records and CRM import-batch visibility.
- Broadcasts can target all imported contacts or selected import batches.
- Permission invitations are email-only one-time imported-contact invitations owned by Messaging.
- Normal Broadcasts are single-channel email or SMS sends and remain consent-gated through Messaging.
- SMS Broadcast authoring is available only when Messaging channel availability exposes SMS for the `broadcasts` surface.
- Broadcast scheduling records recipient outcomes and exposes scheduled/skipped/failed visibility on the Broadcast detail page.

## Working roadmap

| # | Planned item | Rough estimate | Notes |
| -: | --- | ---: | --- |
| 1 | Import-time status mapping | 1–2 sessions | Let operators map legacy/imported statuses to Engage Core ContactStatus records without silently assigning wrong statuses. Preserve original imported status metadata for audit/debugging. |
| 2 | Permission invitation accepted automation event decision | 0.25–0.5 session | Decide whether accepted invitations should emit a neutral automation event such as `permission_invitation.accepted`. |
| 3 | Permission invitation cancellation behavior | 0.5–1 session | Clarify how cancellation/skip/failure should appear for permission-invitation Broadcast bookkeeping and Messaging scheduled messages. |
| 4 | Config validation guidance | 0.5–1 session | Convert current config-template expectations into practical validation behavior and operator/debug feedback. |
| 5 | Task template/default definition UI | 1–2 sessions, maybe more if polished | Only needed when clients/operators need to manage task templates themselves. Preset sync already creates DB-owned definitions only. |
| 6 | FlowRoutes route-builder UX | 3–6 sessions | Keep Route builder simple, guided, and client-appropriate. Do not expose raw automation internals as a blank-canvas builder. |
| 7 | Task-completed FlowRoutes resume behavior | 0.5–1 session | Resume route event-wait points from neutral `task.completed` automation events, not direct Task-specific FlowRoutes listeners. |
| 8 | Client self-serve readiness audit | 0.5–1 session | Separate controlled beta/operator-assisted readiness from true client self-serve readiness. |
| 9 | PetServices vertical planning | 0.5–1 session | Plan vertical-owned pet/service concepts without pushing domain fields into Core. |
| 10 | Music vertical planning | 0.5–1 session | Plan vertical-owned music/fan/product-interest concepts using Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Scheduling, Portal, and Reporting as needed. |
| 11 | Feature-specific docs as modules stabilize | Ongoing | Keep module docs current when architecture/operator behavior changes. Do not turn docs into speculative backlog. |
| 12 | Client config fallback tests | 0.5–1 session | Verify default/client config fallback, numeric-array replacement, optional content/style safety, and copy-tolerant tests. |

## Recently completed client-readiness items

These items are no longer the recommended next implementation target, but they explain the current baseline.

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

## Recommended next implementation target

The next implementation target should be:

```text
Import-time status mapping
```

Reason:

- Import batches are now visible and usable.
- Imported-contact permission invitations and SMS Broadcasts now have enough operator visibility to be usable.
- The next onboarding risk is silently assigning imported leads the wrong Engage Core status.
- Mapping imported status values to Core-owned `ContactStatus` records keeps Core ownership clear and avoids pushing workflow or vertical-specific state into contacts.

After that, the next likely implementation target is:

```text
Permission invitation accepted automation event decision
```

## What this roadmap intentionally avoids

This roadmap should not be used to justify temporary shortcuts.

Avoid:

- fake MVP code paths that will be reverted soon;
- compatibility layers for old shapes unless explicitly chosen;
- adding module-specific behavior into Core for speed;
- building blank-canvas client builders before the guided workflow is clear;
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
