# Inbound Messaging TODO

- [ ] Add a provider-neutral routed-message consumer seam so an owning module/integration can claim a named inbound address, interpret normalized message content, establish person/business identity, record owning-module truth, and emit its own neutral business event. Keep provider-specific parsing and domain behavior out of InboundMessaging.
- [ ] Add optional copy/forward-to-inbox behavior for CRM replies when a concrete operator workflow requires it. Contact-visible bounded history and reply-from-CRM are implemented.
- [ ] Add provider attachment handling only when a concrete product need exists; keep attachment binaries/provider envelopes outside normalized message rows.