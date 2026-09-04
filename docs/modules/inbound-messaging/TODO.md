# Inbound Messaging TODO

- [x] Add deterministic Contact extraction for named inbound email addresses. Route-owned extraction definitions may resolve Contact email/name/phone from sender, Reply-To, subject, or labeled normalized body text; extraction links the Inbox message and republishes the existing route automation event with Contact identity.
- [ ] Add additional deterministic extraction strategies only when a real provider template requires them; do not introduce arbitrary AI parsing as the default intake authority.

- [ ] Add optional copy/forward-to-inbox behavior for CRM replies when a concrete operator workflow requires it. Contact-visible bounded history and reply-from-CRM are implemented.
- [ ] Add provider attachment handling only when a concrete product need exists; keep attachment binaries/provider envelopes outside normalized message rows.