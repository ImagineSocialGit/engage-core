# Inbound Messaging TODO

- [ ] Finish the canonical webhook-inbox cutover by moving the remaining narrow SMS compliance source/IP/user-agent evidence out of `inbound_messages.meta`, then remove the generic inbound-message `meta` column and redundant `inbound_message_receipts` table when all provider paths use the canonical receipt boundary.
- [ ] Add CRM conversation/reply UX completeness: Contact-visible inbound history, reply-from-CRM, and optional copy/forward behavior without making those product surfaces prerequisites for durable capture.
- [ ] Add provider attachment handling only when a concrete product need exists; keep attachment binaries/provider envelopes outside normalized message rows.