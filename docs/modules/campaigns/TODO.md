# Campaigns TODO

Work these in order. Keep Campaigns independent from FlowRoutes, Webinars, Forms, Scheduling, InboundMessaging, and other producer modules; use shared/public automation seams instead of direct module dependencies.

## 1. Finish direct MessageChain authoring

- [ ] Migrate Campaign Builder/preset authoring to direct Messaging MessageChain definitions, then remove the temporary `campaign_steps` / `campaign_step_variants` authoring projection.

## 2. Campaign lifecycle and launch safety

- [x] Add bounded bulk Campaign audience enrollment orchestration for Contact-import/operator-driven starts: import enrollment suppresses eager MessageChain progression and Messaging drains due enrollments through `BulkMessageDeliveryPolicy` on the `bulk_messages` queue.
- [x] Define a generic Campaign entry/start contract: activation never implies audience selection, `EnrollContactInCampaignAction` remains the public single-Contact seam, and optional stable `entryKey` identity makes import/automation retries idempotent across terminal history.
- [ ] Define the shared trigger-authoring/binding seam needed by the Campaign Builder's `What starts this campaign?` stage without introducing Campaigns -> FlowRoutes or FlowRoutes -> Campaigns private coupling.
- [ ] Support business exits such as conversion, qualifying status changes, application started, appointment booked, or other configured outcomes by invoking Campaign-owned cancellation through neutral events/public automation seams.
- [ ] Support campaign-response outcomes without hardcoding client phrases in Campaigns. Client-configured rules such as `YES`, `READY`, `GAME PLAN`, `LATER`, `NO`, or `DONE` should react through neutral inbound-reply events and public automation actions.
- [ ] Decide and implement the generic outcome when an enrollment has no remaining eligible delivery channels; prefer a terminal/exited state over carrying a year-long enrollment through only skipped messages.
- [ ] Keep campaign intent responses such as `NO` distinct from legal/provider opt-out commands such as `STOP`.
- [ ] Coordinate the InboundMessaging/Messaging-owned SMS re-opt-in gap: either implement durable `START`/resubscribe handling that restores Engage Core consent state or stop instructing recipients to reply START.
- [ ] Coordinate/prove an InboundMessaging-owned human email-reply ingestion path before campaigns rely on email copy that tells recipients to reply.
- [ ] Add focused business-lifecycle proof covering enrollment -> scheduled messages -> meaningful reply -> STOP -> START/resubscribe -> email unsubscribe/suppression -> conversion/exit.

## 3. Campaign workspace and Builder

- [ ] Extend the existing Campaign workspace/shared Builder shell into the actual Edit, Copy, and Create flows rather than creating separate wizards.
- [ ] Lead with Campaign name, who it is for, what starts it, message count/channels, human-readable schedule, lifecycle state, and enrollment/outcome summaries.
- [ ] Add `Copy an existing campaign` as the recommended new-Campaign path; copies must be independent and use Messaging copy-on-write/immutable MessageChain and MessageTemplate version semantics.
- [ ] Add create-from-scratch mode using the same Builder stages, with guidance that it is best suited to short/simple campaigns.
- [ ] Build the `What starts this campaign?` step from client/module-available shared automation capabilities rather than hardcoded producer-module imports.
- [ ] Build a human-readable schedule summary and editor; allow add/remove/reorder/timing changes without exposing raw timing/config fields.
- [ ] Build guided message review/editing in Campaign context while Messaging remains owner of reusable copy/template versions.
- [ ] Add campaign-wide message search across subjects, email bodies, and SMS copy with match counts and jump-to-message behavior; start with search/highlight rather than blind replace-all.
- [ ] Add duplicate/add-before/add-after message conveniences where they reduce editing time.
- [ ] Add final review/activation with clear start rule, schedule, message count/channels, exit behavior, audience/enrollment implications, and live-change warnings.
- [ ] Add draft/publish safety for message/schedule changes so active enrollments remain pinned to the immutable version they started with.
- [ ] Keep technical specs such as dispatch keys, payload classes, purpose/scope, config paths, message types, and strategy internals behind diagnostics/details.

## 4. UX cleanup while the Builder lands

- [ ] Replace `delivery options` wording with message-step/channel language.
- [ ] Collapse detailed message steps by default and show business moment, channel badges, human-readable timing, and readiness in summaries.
- [ ] Remove repetitive template-selection labels and make copy editing feel like part of the Campaign workflow even when Messaging owns the underlying editor/version.
- [ ] Keep all Campaign workspace/Builder screens usable at phone widths: no page-level horizontal overflow, long values wrap, and primary actions stack/full-width on phones where useful.
- [ ] Do not add visual/Tailwind assertions; test routes, data/behavior contracts, validation, lifecycle actions, and boundary rules.

## 5. Client rollout after the generic lifecycle is safe

- [ ] Resume additional long-running client Campaign rollout only after the generic entry, reply/outcome, exit, START/resubscribe, and email-reply assumptions are resolved or deliberately removed from the copy.
- [ ] Run production-like smoke tests before bulk enrollment of old/cold contacts; activation alone must never enroll a historical audience automatically.