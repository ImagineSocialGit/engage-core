# Messaging TODO

- [ ] Add production-shaped row-size, index-plan, retention, and pruning measurements for the immutable template/chain/delivery persistence model.
- [ ] Add a safer first-class owning-module reissue/recovery mechanism for exact skipped/failed logical deliveries; do not bypass normal consent/suppression/provider gates.
- [ ] Add dedicated `Messaging -> Opt-In Messages` management separate from ordinary module message-template pages.
- [ ] Make readiness presentation aware of the actual delivery/consolidation/fallback path rather than treating one template row as the complete outcome.
- [ ] Add Messaging-level plain-text email support for every email source without creating parallel lifecycle behavior paths.
