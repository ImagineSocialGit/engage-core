# Inbound Messaging TODO

- [ ] Add the CRM authoring surface for durable `inbound_email_routes` so operators can create/disable semantic aliases without direct database work; runtime resolution, route evidence, automation events, setup validation, and Project State transfer are implemented.
- [ ] Finish the canonical webhook-inbox cutover by removing the generic inbound-message `meta` column and redundant `inbound_message_receipts` table when all provider paths use the canonical receipt boundary. C5B removed the remaining SMS source/IP/user-agent request copies from normalized inbound rows and compacted STOP revocation evidence.
- [ ] Add optional copy/forward-to-inbox behavior for CRM replies when a concrete operator workflow requires it. Contact-visible bounded history and reply-from-CRM are implemented.
- [ ] Add provider attachment handling only when a concrete product need exists; keep attachment binaries/provider envelopes outside normalized message rows.