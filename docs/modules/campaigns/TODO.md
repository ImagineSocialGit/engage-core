# Campaigns TODO

Work these in order. Keep Campaigns independent from FlowRoutes, Webinars, Forms, Scheduling, InboundMessaging, and other producer modules; use shared/public automation seams instead of direct module dependencies.

## 1. Runtime cutover first

- [ ] Migrate Campaigns fully to Messaging MessageChains while preserving Campaign identity, activation, audience/enrollment intent, source context, and reporting.
- [x] Make new Campaign enrollments create a thin CampaignEnrollment wrapper around a version-pinned MessageChainEnrollment. New starts now use Messaging runtime; legacy progression columns remain compatibility-only until readers/schema are removed.
- [x] Publish current Campaign preset timing, channel strategy, variants, and dependencies into immutable Messaging-owned MessageChain definitions during transitional preset sync.
- [x] Update Campaign cancellation/deactivation to use Messaging public chain-enrollment cancellation/skip actions. Linked F5+ enrollments now delegate to MessageChainEnrollment cancellation; legacy unlinked shutdown cleanup remains temporary only until F7 removes the old runtime.
- [ ] Replace Campaign-owned step/variant progression fields only after every runtime reader and Project State path uses the MessageChain relationship.
- [x] Preserve current operational lifecycle semantics: Off cancels open work and skips pending messages; turning back on permits future enrollments only and never resurrects cancelled journeys. Campaign-generated MessageChains are lifecycle-aligned without forcing a reusable/shared chain off for unrelated consumers.
- [x] Add generic MessageChain and Campaign enrollment pause/resume lifecycle seams. Pause skips unsent pending messages for that enrollment, does not rewrite already-sending/terminal work, and resume preserves remaining future delay or immediately re-evaluates a materialized skipped wave.
- [ ] After runtime cutover, add a dev-only Campaign simulator that can enroll a test contact, set/fake the clock, advance through scheduled moments, run MessageChain progression, and inspect scheduled/sent/skipped outcomes without provider delivery. It must be unavailable in production.

## 2. Campaign lifecycle and launch safety

- [ ] Add bounded bulk Campaign audience enrollment orchestration for operator/import-driven starts so large recipient sets reuse the generic bulk policy instead of creating an unbounded burst of enrollment/progression jobs.
- [ ] Define a generic Campaign entry/start contract so activation never implies audience selection; enrollment must come from an explicit operator action or shared automation/public action seam.
- [ ] Define the shared trigger-authoring/binding seam needed by the Campaign Builder's `What starts this campaign?` stage without introducing Campaigns -> FlowRoutes or FlowRoutes -> Campaigns private coupling.
- [ ] Support business exits such as conversion, qualifying status changes, application started, appointment booked, or other configured outcomes by invoking Campaign-owned cancellation through neutral events/public automation seams.
- [ ] Support campaign-response outcomes without hardcoding client phrases in Campaigns. Client-configured rules such as `YES`, `READY`, `GAME PLAN`, `LATER`, `NO`, or `DONE` should react through neutral inbound-reply events and public automation actions.
- [ ] Decide and implement the generic outcome when an enrollment has no remaining eligible delivery channels; prefer a terminal/exited state over carrying a year-long enrollment through only skipped messages.
- [ ] Keep campaign intent responses such as `NO` distinct from legal/provider opt-out commands such as `STOP`.
- [ ] Coordinate the InboundMessaging/Messaging-owned SMS re-opt-in gap: either implement durable `START`/resubscribe handling that restores Engage Core consent state or stop instructing recipients to reply START.
- [ ] Coordinate/prove an InboundMessaging-owned human email-reply ingestion path before campaigns rely on email copy that tells recipients to reply.
- [ ] Add focused business-lifecycle proof covering enrollment -> scheduled messages -> meaningful reply -> STOP -> START/resubscribe -> email unsubscribe/suppression -> conversion/exit.

## 3. Campaign workspace and Builder

- [ ] Establish the business-facing Campaigns workspace and shared Builder shell used by Edit, Copy, and Create.
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