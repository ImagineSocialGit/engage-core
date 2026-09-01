# Messaging Provider Webhook Routing

## Ownership rule

A provider webhook is not automatically an inbound human message.

Engage Core separates provider-originated delivery/lifecycle events from human-originated inbound messages:

```text
Provider delivery / lifecycle feedback
    -> Messaging

Human email / SMS received
    -> Inbound Messaging
```

Messaging owns suppression, consent consequences, provider-delivery evidence, and outbound message correlation. Inbound Messaging owns the durable inbound conversation/message workflow.

## Canonical endpoints

Messaging-owned provider events:

```text
POST https://webhooks.{root-domain}/message-events/email/resend
POST https://webhooks.{root-domain}/message-events/sms/telnyx
```

Inbound Messaging-owned human messages:

```text
POST https://webhooks.{root-domain}/inbound/email/resend
POST https://webhooks.{root-domain}/inbound/sms/telnyx
```

The historical endpoints remain temporary compatibility aliases during provider cutover:

```text
POST https://webhooks.{root-domain}/email/resend
POST https://webhooks.{root-domain}/sms/telnyx
```

Do not configure new provider integrations against the compatibility aliases.

## Resend configuration

Create separate Resend webhook registrations for the two concerns.

The Messaging endpoint should receive delivery/lifecycle events used by the account, including applicable events such as:

```text
email.sent
email.delivered
email.delivery_delayed
email.bounced
email.complained
email.suppressed
email.failed
contact.updated (when data.unsubscribed=true)
```

The Inbound Messaging endpoint should receive:

```text
email.received
```

Each Resend webhook registration may have its own Svix signing secret. During this compatibility cutover, `RESEND_WEBHOOK_SECRET` accepts one or more active trusted signing secrets separated by commas, semicolons, or whitespace. Keep both endpoint secrets in that existing client-owned variable while both registrations are active, then remove any retired secret after provider cutover.

This batch deliberately does not add another client environment variable solely for the endpoint split.

## Resend durable consequences

Messaging applies the existing durable consequences for provider feedback:

- `email.bounced` -> email suppression with reason `bounce`;
- `email.complained` -> email suppression with reason `complaint`;
- `email.suppressed` -> email suppression with reason `provider`;
- definitive invalid-address `email.failed` evidence -> email suppression with reason `invalid_destination`;
- temporary/ambiguous `email.failed` evidence -> no automatic suppression;
- `contact.updated` with `data.unsubscribed=true` -> revoke the Contact's email marketing permission at the channel + purpose boundary;
- legacy `email.unsubscribed` payloads remain accepted during compatibility cutover.

Informational delivery events may have no additional business consequence yet. They are still accepted through the Messaging endpoint and durably deduplicated by `WebhookInbox`.

Raw provider payloads remain operational evidence in `webhook_inbox_receipts`. Message suppressions and consent revocations store only bounded normalized evidence needed for durable Messaging behavior.

## Telnyx configuration

Configure the Telnyx Messaging Profile webhook for inbound messages:

```text
https://webhooks.{root-domain}/inbound/sms/telnyx
```

Engage Core supplies the Messaging-owned callback URL on each outbound Telnyx message:

```text
https://webhooks.{root-domain}/message-events/sms/telnyx
```

Outbound sends set `use_profile_webhooks=false` so delivery callbacks for those messages do not also flow through the inbound Messaging Profile endpoint.

Inbound `message.received` continues through Inbound Messaging. Delivery/lifecycle events such as `message.sent` and `message.finalized` flow through Messaging.

This batch records and deduplicates Telnyx outbound delivery events but deliberately does not infer SMS suppression or consent changes from Telnyx delivery statuses. Add those consequences only after defining explicit provider-status semantics and tests.

## Deployment / provider cutover

Recommended order:

1. deploy this code while the historical aliases still exist;
2. configure the Resend delivery/lifecycle registration to the Messaging endpoint;
3. configure the Resend received-email registration to the inbound endpoint;
4. place all currently active Resend signing secrets in `RESEND_WEBHOOK_SECRET`;
5. point the Telnyx Messaging Profile inbound webhook to `/inbound/sms/telnyx`;
6. send test email/SMS traffic and verify `webhook_inbox_receipts`, suppressions, revocations, and inbound messages;
7. after every client/provider has moved, remove the historical aliases in a later cleanup batch.

No database migration or Project State version change is required for this routing split.