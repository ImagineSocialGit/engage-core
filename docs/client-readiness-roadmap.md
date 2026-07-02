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

## Working roadmap

| # | Planned item | Rough estimate | Notes |
| -: | --- | ---: | --- |
| 1 | Import batch management visibility | 0.5–1 session | Add a simple CRM list/detail surface for first-class import batches so operators can see source, filename, status, imported date, and counts. Broadcasts can already target selected import batches. |
| 2 | Import-time status mapping | 1–2 sessions | Let operators map legacy/imported statuses to Engage Core ContactStatus records without silently assigning wrong statuses. |
| 3 | Permission invitation accepted automation event decision | 0.25–0.5 session | Decide whether accepted invitations should emit a neutral automation event such as `permission_invitation.accepted`. |
| 4 | Permission invitation cancellation behavior | 0.5–1 session | Clarify how cancellation/skip/failure should appear for permission-invitation Broadcast bookkeeping and Messaging scheduled messages. |
| 5 | Config validation guidance | 0.5–1 session | Convert current config-template expectations into practical validation behavior and operator/debug feedback. |
| 6 | SMS Broadcast authoring + channel availability wiring | 1–2 sessions | Regular Broadcasts remain single-channel; email uses subject/body, SMS uses message; channel choices come from Messaging channel availability. Permission invitations remain email-only for the bypass send. |
| 7 | Task template/default definition UI | 1–2 sessions, maybe more if polished | Only needed when clients/operators need to manage task templates themselves. Preset sync already creates DB-owned definitions only. |
| 8 | FlowRoutes route-builder UX | 3–6 sessions | Keep Route builder simple, guided, and client-appropriate. Do not expose raw automation internals as a blank-canvas builder. |
| 9 | Task-completed FlowRoutes resume behavior | 0.5–1 session | Resume route event-wait points from neutral `task.completed` automation events, not direct Task-specific FlowRoutes listeners. |
| 10 | Client self-serve readiness audit | 0.5–1 session | Separate controlled beta/operator-assisted readiness from true client self-serve readiness. |
| 11 | PetServices vertical planning | 0.5–1 session | Plan vertical-owned pet/service concepts without pushing domain fields into Core. |
| 12 | Music vertical planning | 0.5–1 session | Plan vertical-owned music/fan/product-interest concepts using Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Scheduling, Portal, and Reporting as needed. |
| 13 | Feature-specific docs as modules stabilize | Ongoing | Keep module docs current when architecture/operator behavior changes. Do not turn docs into speculative backlog. |
| 14 | Client config fallback tests | 0.5–1 session | Verify default/client config fallback, numeric-array replacement, optional content/style safety, and copy-tolerant tests. |

## Recommended next implementation target

The next implementation target should be:

```text
Import batch management visibility
```

Reason:

- Broadcasts can now target all imported contacts or selected import batches.
- Operators need a simple way to inspect import batches before using them for opt-in invitations.
- It improves the imported-contact onboarding path without adding import-processing complexity yet.
- It keeps Core owning import-batch records while Broadcasts only consumes them for recipient targeting and display.
- It sets up better links from Broadcast recipient filters to import-batch detail pages later.

After that, the next likely implementation target is:

```text
SMS Broadcast authoring + channel availability wiring
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
- making permission invitations a general Broadcast feature instead of a Messaging-owned one-time consent flow.

## Relationship to TODO.md

`TODO.md` remains the disposable backlog/checklist file.

Keep roadmap-level ordering here.

Keep repeatable checklists and lower-priority backlog in `TODO.md`.

When a roadmap item is completed:

1. Remove it from this file or move it to a completed release note if needed.
2. Delete or update the related TODO item.
3. Update module docs only if architecture or durable behavior changed.