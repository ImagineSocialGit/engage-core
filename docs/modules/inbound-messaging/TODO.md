# Inbound Messaging TODO

- [ ] Finish the canonical webhook-inbox cutover by removing the generic inbound-message `meta` column and redundant `inbound_message_receipts` table when all provider paths use the canonical receipt boundary. C5B removed the remaining SMS source/IP/user-agent request copies from normalized inbound rows and compacted STOP revocation evidence.
- [ ] Add CRM conversation/reply UX completeness: Contact-visible inbound history, reply-from-CRM, and optional copy/forward behavior without making those product surfaces prerequisites for durable capture.
- [ ] Add provider attachment handling only when a concrete product need exists; keep attachment binaries/provider envelopes outside normalized message rows.