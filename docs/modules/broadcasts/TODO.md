# Broadcasts TODO

## Persistence/runtime cutover

- [x] Bound large Broadcast recipient sets with durable recipient snapshots, paced chunk scheduling, the Messaging-owned bulk queue, and shared provider submission limiting.
- [ ] Move Broadcast content to a stable Messaging template plus pinned immutable template version.
- [ ] Replace `broadcast_recipients.scheduled_message_ids` arrays with one nullable ScheduledMessage relationship for the normal single-channel Broadcast contract.
- [ ] Replace remaining scheduling metadata with justified first-class summaries and remove generic metadata only after retained values are audited.

## UX polish

- [x] Keep imported-contact permission invitations secondary to normal Broadcast authoring and hide invitation controls when no eligible contacts exist.
- [x] Collapse duplicate-send protection by default with a clear summary.
- [x] Refine the guided authoring sequence: recipients -> preview -> channel/content -> duplicate protection/review -> schedule/send.
- [ ] Add `Make a new Broadcast from this` when useful; add clone lineage persistence only if a real audit/product need proves it necessary.