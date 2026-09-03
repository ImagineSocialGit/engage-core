# Broadcasts TODO

## Persistence/runtime cutover

- [x] Bound large Broadcast recipient sets with durable recipient snapshots, paced chunk scheduling, the Messaging-owned bulk queue, and shared provider submission limiting.
- [x] Pin scheduled Broadcast content to one private Messaging template plus immutable template version shared by all recipient deliveries.
- [x] Replace transitional `broadcasts.payload` with first-class private Messaging template + pinned version relationships, including Project State v2 transfer.
- [x] Replace `broadcast_recipients.scheduled_message_ids` arrays with one nullable ScheduledMessage relationship for the normal single-channel Broadcast contract.
- [ ] Replace remaining scheduling metadata with justified first-class summaries and remove generic metadata only after retained values are audited.

## UX polish

- [x] Keep imported-contact permission invitations secondary to normal Broadcast authoring and hide invitation controls when no eligible contacts exist.
- [x] Collapse duplicate-send protection by default with a clear summary.
- [x] Refine the guided authoring sequence: recipients -> preview -> channel/content -> duplicate protection/review -> schedule/send.
- [x] Add `Make a new Broadcast from this` with a clean WHO/timing reset; do not persist clone lineage without a proven audit/product need.
- [x] Let regular Broadcast copy be explicitly saved into Messaging's existing reusable Message Templates catalog and loaded into later Broadcast drafts.
- [x] Add Contact-field personalization to regular Broadcast authoring through the registered `broadcast_send` token context, including 23C1 missing-field behavior and reusable-template preservation.
- [x] Add one first-class primary CTA to regular email Broadcast authoring, persist it in the private immutable Messaging payload, preserve it through reusable Message Templates, and let Messaging inject signed click tracking at ScheduledMessage render time.