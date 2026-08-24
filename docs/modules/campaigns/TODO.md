# Campaigns TODO

Work these in order. Keep Campaigns independent from FlowRoutes, Webinars, Forms, Scheduling, InboundMessaging, and other producer modules; use shared/public automation seams instead of direct module dependencies.

## 1. Finish direct MessageChain authoring

- [ ] Migrate Campaign Builder/preset authoring to direct Messaging MessageChain definitions, then remove the temporary `campaign_steps` / `campaign_step_variants` authoring projection.
- [x] Keep Campaign message-copy editing inside Campaign Setup while publishing through Messaging's reusable immutable-template action.
- [x] Add a payload-free schedule popup that can become the direct MessageChain schedule editor without changing the Campaign Setup navigation contract.
- [x] Make Campaign-context message and schedule edits publish copy-on-write MessageChain versions for future enrollments while existing enrollments remain pinned.

## 2. Campaign lifecycle and launch safety

- [x] Add bounded bulk Campaign audience enrollment orchestration for Contact-import/operator-driven starts: import enrollment suppresses eager MessageChain progression and Messaging drains due enrollments through `BulkMessageDeliveryPolicy` on the `bulk_messages` queue.
- [x] Define a generic Campaign entry/start contract: activation never implies audience selection, `EnrollContactInCampaignAction` remains the public single-Contact seam, and optional stable `entryKey` identity makes import/automation retries idempotent across terminal history.
- [x] Author the Campaign Builder's `What starts this campaign?` stage through the shared Contact-filter criterion registry without introducing Campaigns -> producer-module coupling.
- [ ] Support business exits such as conversion, qualifying status changes, application started, appointment booked, or other configured outcomes by invoking Campaign-owned cancellation through neutral events/public automation seams.
- [ ] Support campaign-response outcomes without hardcoding client phrases in Campaigns. Client-configured rules such as `YES`, `READY`, `GAME PLAN`, `LATER`, `NO`, or `DONE` should react through neutral inbound-reply events and public automation actions.
- [ ] Decide and implement the generic outcome when an enrollment has no remaining eligible delivery channels; prefer a terminal/exited state over carrying a year-long enrollment through only skipped messages.
- [ ] Keep campaign intent responses such as `NO` distinct from legal/provider opt-out commands such as `STOP`.
- [ ] Coordinate the InboundMessaging/Messaging-owned SMS re-opt-in gap: either implement durable `START`/resubscribe handling that restores Engage Core consent state or stop instructing recipients to reply START.
- [ ] Coordinate/prove an InboundMessaging-owned human email-reply ingestion path before campaigns rely on email copy that tells recipients to reply.
- [ ] Add focused business-lifecycle proof covering enrollment -> scheduled messages -> meaningful reply -> STOP -> START/resubscribe -> email unsubscribe/suppression -> conversion/exit.

## 3. Campaign workspace and Builder

- [ ] Extend the existing Campaign workspace/shared Builder shell into Copy and Create flows without creating separate wizards. The Edit flow is now actionable.
- [x] Lead Edit with Campaign name, what starts it, message count/channels, a human-readable schedule preview, lifecycle state, and enrollment/message summaries.
- [ ] Add `Copy an existing campaign` as the recommended new-Campaign path; copies must be independent and use Messaging copy-on-write/immutable MessageChain and MessageTemplate version semantics.
- [ ] Add create-from-scratch mode using the same Builder stages, with guidance that it is best suited to short/simple campaigns.
- [x] Build the `What starts this campaign?` step from client/module-available shared Contact-filter criteria rather than hardcoded producer-module imports.
- [x] Finish the human-readable schedule editor; allow add/remove/reorder/timing changes without exposing raw timing/config fields.
- [x] Build guided message review/editing in a Campaign Setup modal while Messaging remains owner of reusable copy/template versions.
- [ ] Add campaign-wide message search across subjects, email bodies, and SMS copy with match counts and jump-to-message behavior; start with search/highlight rather than blind replace-all.
- [ ] Add duplicate/add-before/add-after message conveniences where they reduce editing time.
- [ ] Complete final review with clear start rule, schedule, message count/channels, exit behavior, audience implications, and live-change warnings. Activation/deactivation is now available in Campaign Setup.
- [x] Add optimistic version checks and copy-on-write publication so active enrollments remain pinned to the immutable version they started with.
- [x] Keep technical specs such as dispatch keys, payload classes, purpose/scope, config paths, message types, and strategy internals behind advanced setup/details.

## 4. UX cleanup while the Builder lands

- [ ] Replace `delivery options` wording with message-step/channel language in the advanced compatibility screen.
- [ ] Collapse detailed message steps by default in the advanced compatibility screen; Campaign Setup now uses concise cards and carousels.
- [x] Make copy editing feel like part of the Campaign workflow while Messaging owns the underlying editor/version.
- [ ] Complete phone-width UI review for Campaign Setup and its modals: no page-level horizontal overflow, long values wrap, and primary actions stack/full-width where useful.
- [x] Test routes, data/behavior contracts, validation, lifecycle actions, and module boundaries instead of visual/Tailwind classes.

## 5. Client rollout after the generic lifecycle is safe

- [ ] Resume additional long-running client Campaign rollout only after the generic entry, reply/outcome, exit, START/resubscribe, and email-reply assumptions are resolved or deliberately removed from the copy.
- [ ] Run production-like smoke tests before bulk enrollment of old/cold contacts; activation alone must never enroll a historical audience automatically.