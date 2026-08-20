# Messaging TODO

- [ ] Add reply-profile authoring UX so templates/MessageChain variants can select a stable business reply profile while provider addresses/numbers remain implementation details; preserve notification-only behavior when no downstream automation is configured.
- [ ] Add first-class CTA click tracking attributable to ScheduledMessage + logical CTA, with scanner/prefetch protection, compact evidence, explicit retention, and Webinar replay links as an initial consumer rather than a Webinar-owned tracking silo.
- [ ] Add production-shaped row-size, index-plan, retention, and pruning measurements for the immutable template/chain/delivery persistence model.
- [ ] Add provider-aware bulk delivery orchestration/backpressure for large Campaign/Broadcast audiences: bounded recipient/enrollment chunks, bounded queue fan-out/replenishment, idempotent retries, provider/channel pacing, and production-shaped proof that a roughly 2,000-recipient burst does not create one giant transaction or an uncontrolled immediate job flood.
- [ ] Add a safer first-class owning-module reissue/recovery mechanism for exact skipped/failed logical deliveries; do not bypass normal consent/suppression/provider gates.
- [ ] Add dedicated `Messaging -> Opt-In Messages` management separate from ordinary module message-template pages.
- [ ] Make readiness presentation aware of the actual delivery/consolidation/fallback path rather than treating one template row as the complete outcome.
- [ ] Add Messaging-level plain-text email support for every email source without creating parallel lifecycle behavior paths.