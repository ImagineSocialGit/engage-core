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

The operator-facing provider-account provisioning procedure lives in:

```text
docs/operations/client-third-party-services-checklist.md
```

This document is the technical routing and runtime contract.

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

## Resend webhook contract

Create two Resend webhook registrations.

The Messaging-owned delivery/lifecycle endpoint receives:

```text
email.sent
email.delivered
email.delivery_delayed
email.bounced
email.complained
email.suppressed
email.failed
contact.updated
```

`contact.updated` has a Messaging consequence only when the payload reports `data.unsubscribed=true`.

Do not subscribe this endpoint to `email.received`. `ResendMessageEventWebhookHandler` rejects `email.received` rather than allowing a human message to enter the provider-lifecycle path.

The Inbound Messaging-owned endpoint receives only:

```text
email.received
```

Do not subscribe the inbound endpoint to delivery/lifecycle events.

Current Engage Core does not define runtime semantics for Resend `suppression.added` or `suppression.removed`. Do not add those provider events to the canonical registration until explicit handling and tests exist.

## Resend signing secrets

Each Resend webhook registration may have its own Svix signing secret.

`RESEND_WEBHOOK_SECRET` accepts one or more active trusted secrets separated by commas, semicolons, or whitespace. When both canonical Resend webhook registrations are active, keep both endpoint secrets in the same selected-client variable.

Example shape:

```env
RESEND_WEBHOOK_SECRET=whsec_delivery...,whsec_inbound...
```

Remove a retired secret only after the corresponding provider registration no longer sends traffic.

`RESEND_WEBHOOK_TIMESTAMP_DRIFT_SECONDS` remains the root/process-owned timestamp tolerance.

## Resend inbound-email retrieval contract

The `email.received` webhook carries receiving metadata, not the complete message body.

When Inbound Messaging receives `email.received`, `ResendReceivedEmailClient` uses `data.email_id` to retrieve the complete received-email resource from:

```text
GET https://api.resend.com/emails/receiving/{email_id}
```

The current implementation uses the same `RESEND_API_KEY` used by the Resend integration. Therefore:

```text
outbound email only
    a Resend Sending Access key may be sufficient

Inbound Messaging email receiving enabled
    RESEND_API_KEY must have Full Access so Engage Core can retrieve received email
```

Do not document a second receive-only credential until the runtime is deliberately changed to support one.

The receiving domain is selected-client deployment state:

```env
INBOUND_EMAIL_DOMAIN=replies.<root-domain>
```

When configured, Messaging may generate signed Reply-To addresses such as:

```text
reply+<message-reference>.<signature>@replies.<root-domain>
```

Inbound Messaging validates and correlates those addresses back to the originating sent message. The `reply+` local-part namespace is reserved for signed correlation.

Authored inbound email routes may use other local parts on the same receiving domain.

## Resend tracking

Engage Core owns explicit CTA engagement tracking through Messaging `tracking_key` links and signed redirect URLs.

Keep Resend domain-level Open Tracking and Click Tracking disabled unless the platform deliberately adopts those provider features later.

In particular, Resend Click Tracking rewrites links through a Resend redirect. Enabling it on top of Engage Core CTA tracking would create a second redirect/tracking layer and competing engagement semantics.

## Resend durable consequences

Messaging applies these durable consequences for provider feedback:

- `email.bounced` -> email suppression with reason `bounce`;
- `email.complained` -> email suppression with reason `complaint`;
- `email.suppressed` -> email suppression with reason `provider`;
- definitive invalid-address `email.failed` evidence -> email suppression with reason `invalid_destination`;
- temporary/ambiguous `email.failed` evidence -> no automatic suppression;
- `contact.updated` with `data.unsubscribed=true` -> revoke the Contact's email marketing permission at the channel + purpose boundary;
- legacy `email.unsubscribed` payloads remain accepted during compatibility cutover.

Informational delivery events may have no additional business consequence yet. They are still accepted through the Messaging endpoint and durably deduplicated by `WebhookInbox`.

Raw provider payloads remain operational evidence in `webhook_inbox_receipts`. Message suppressions and consent revocations store only bounded normalized evidence needed for durable Messaging behavior.

A current suppression affecting the Contact's current email destination becomes an operator-visible Messaging Delivery Issue. Editing the Contact to a different destination does not erase the old suppression record; it removes that historical destination from the Contact's current issue state. Complaint suppressions are not casually releasable through the normal resolution workflow.

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

Current runtime records and deduplicates Telnyx outbound delivery events but deliberately does not infer SMS suppression or consent changes from Telnyx delivery statuses. Add those consequences only after defining explicit provider-status semantics and tests.

## Deployment / provider cutover

Recommended order:

1. deploy the canonical routes while the historical aliases still exist;
2. configure the Resend delivery/lifecycle registration to `/message-events/email/resend`;
3. configure the Resend received-email registration to `/inbound/email/resend`;
4. place all currently active Resend signing secrets in `RESEND_WEBHOOK_SECRET`;
5. configure `INBOUND_EMAIL_DOMAIN` when inbound email is enabled;
6. point the Telnyx Messaging Profile inbound webhook to `/inbound/sms/telnyx`;
7. send test email/SMS traffic and verify `webhook_inbox_receipts`, suppressions, revocations, delivery issues, and inbound messages;
8. after every client/provider has moved, remove the historical aliases in a later cleanup batch.

No database migration or Project State version change is required for this routing split.