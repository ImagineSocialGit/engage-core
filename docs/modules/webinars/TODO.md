# Webinars TODO

## CRM Architecture

- [x] Split the Webinar CRM into three task-oriented surfaces: Webinar Types,
  Webinar Type Detail, and Specific Session Detail. Keep recurring setup/public
  links on the type, and attendance/participation/registration detail on the
  individual session.
- [x] Make intentionally removed sessions inspectable and recoverable instead of
  disappearing from normal operator wayfinding.
- [ ] Extend the specific-session provider data contract for Zoom Q&A, polls,
  and/or survey reporting if those provider facts are needed. Keep this separate
  from registration questions and define durable DTO/persistence/redaction
  semantics before exposing it in CRM.
- [x] Add Webinar Type lifecycle controls to Type Detail. Empty types may be
  permanently deleted; types with session, waitlist, or removed-provider history
  are archived through the existing `inactive` series status and remain
  restorable.
- [x] Keep archived Webinar Types out of normal active browsing and Zoom sync,
  while preserving registrations, attendance, waitlist history, removed-session
  evidence, and already-scheduled communication.
  - [x] Move full Webinar message-content review/editing to Webinar Type Detail
  through the canonical Messaging carousel popup. Specific Session Detail keeps
  only effective-plan/override context plus its occurrence-only schedule
  override.
- [x] Complete Webinar adoption of universal Email Media authoring so the
  canonical carousel can select/upload/preserve/remove Media through series
  copy-on-write immutable publication.

## Message/readiness follow-up

- [x] UX Phase / 23B1 Webinar workspace hierarchy: make the actual Webinar Workspace the primary CRM surface, keep upcoming sessions in a compact side panel with message/event quick actions, and move series refresh, setup, and testing controls behind task-oriented management.
- [x] UX Phase / 23B1A occurrence removal and message-plan clarity: separate operator visibility from provider lifecycle, permanently remove dependency-free occurrences with durable provider suppression, hide history-bearing occurrences without breaking references, and separate Zoom setup, Message plan, and Message content in series management.
- [x] Make Webinar message copy editable directly in the canonical Messaging carousel, including automatic series copy-on-write for shared defaults, published/edit mode at the top, click/tap gutters, touch swipe, and return-to-Webinar editing context.

- [ ] Verify generated Webinar URL schemes through the current public URL/token resolution path.
- [ ] Make Webinar readiness presentation delivery-consolidation/fallback aware where the current surface can misstate actual send readiness.

## Join-signal integrity

- [ ] Consume generic Messaging CTA click tracking for replay/registration/application links once available, and distinguish trusted human clicks from scanner/prefetch activity before treating them as engagement evidence.
- [ ] Separate raw join-link resolver hits from trusted human interaction so scanners/prefetchers do not become attendance/engagement evidence.
- [ ] Preserve enough join-link history to distinguish scanner/prefetch hits from later genuine interaction without retaining unnecessary sensitive request data.

## Duplicate registration/outcome safety

- [ ] Add a first-class duplicate-outcome suppression mechanism before contradictory attended/missed follow-ups are created.
- [ ] Define explicit Webinar-scoped precedence for likely duplicate conflicting outcomes.

## Post-event reliability

- [ ] Make post-event sequencing and recovery intent easier to inspect for operators without exposing provider/debug internals as the primary UX.

## CRM UX simplification

- [ ] Redesign the top-level Webinar workspace around upcoming activity and recent useful outcomes; surface exceptions prominently only when operator action is required.
- [ ] Simplify Webinar Type Detail around the next session, registration link, registration/results summary, and messages; move provider/sync mechanics and infrequent setup behind secondary management/advanced surfaces.
- [ ] Simplify Specific Session Detail around registrations, attendance, people, messages, and post-webinar follow-up; keep provider evidence and recovery mechanics secondary unless action is required.
- [ ] Make post-webinar message-plan editing and preview a simple Webinar-owned workflow that continues to use the canonical Messaging carousel/editor and immutable copy-on-write runtime.
- [ ] Keep success/outcome summaries semantically precise: distinguish scheduled, sent, delivered, attended, missed, and other facts rather than upgrading weaker evidence into stronger labels.