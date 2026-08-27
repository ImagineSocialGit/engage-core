# Webinars TODO

## Message/readiness follow-up

- [x] UX Phase / 23B1 Webinar workspace hierarchy: make the actual Webinar Workspace the primary CRM surface, keep upcoming sessions in a compact side panel with message/event quick actions, and move series refresh, setup, and testing controls behind task-oriented management.
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

## Rob production Webinar contact migration

Retain this only until the one-time migration is completed and verified.

- [ ] Re-verify `ConsentDomainRegistry` and Webinar consent-domain behavior before touching real contacts.
- [ ] Re-verify normal versus imported consent behavior: imported consent normalizes without `MessageConsentGranted` or opt-in acknowledgement sends.
- [ ] Confirm Rob `presets:sync` and `setup:validate` are clean.
- [ ] Finalize importer dry-run-by-default plus explicit `--apply` behavior and actionable malformed-phone/SMS-consent row output.
- [ ] Prepare and inspect the exact 11-row CSV in dry-run before apply.
- [ ] After apply, verify 11 Contacts, expected channel+purpose consent state, 11 Webinar registrations, no confirmations/opt-in sends, only future-valid reminders, and idempotent rerun with no duplicates.