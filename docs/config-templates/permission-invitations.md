# Imported Contact Permission Invitations

Imported contact permission invitations are a Messaging-owned one-time consent flow for contacts imported from another system.

The purpose is to send one email asking an imported contact to confirm whether they want to receive future messages through email, SMS, or both.

This is not a general marketing consent bypass.

## Ownership

Messaging owns:

- `contact_permission_invitations`
- invitation token generation
- one-time send enforcement
- public preference routes/controllers
- consent recording from the public form
- accepted channel tracking
- injection of public preference URLs into the invitation email payload

Core owns:

- Contact records
- imported-contact filter resolution
- generic contact filter normalization/resolution

Broadcasts may own:

- the CRM UI entry point for creating/scheduling the invitation
- the Broadcast record used for audit/bookkeeping
- BroadcastRecipient records for delivery bookkeeping
- recipient preview/count behavior for the CRM invitation entry point
- Broadcast-side narrowing of imported-contact recipients before scheduling, such as excluding imported contacts that already have Messaging consent

Broadcasts must not own:

- invitation one-time enforcement
- consent creation
- public preference token behavior
- Messaging delivery gates

Broadcast-side recipient narrowing is not the permission bypass.

Messaging still owns the final one-time invitation enforcement.

## Runtime flow

1. A client imports contacts.
2. Contacts are marked as imported by one of:
   - `source = import`
   - `meta.imported = true`
   - `meta.imported_at` present
3. A user creates or schedules an opt-in invitation Broadcast.
4. The Broadcast uses `recipient_filter = {"type":"imported"}`.
5. Broadcast recipient resolution starts with the Core imported-contact filter.
6. Broadcasts may narrow that recipient set for invitation eligibility, such as excluding imported contacts that already have Messaging consent records.
7. Broadcast scheduling calls Messaging through public actions/services.
8. Messaging evaluates and enforces the permission invitation policy.
9. Messaging creates a `contact_permission_invitations` row before provider send.
10. Messaging injects a public preference URL into the email payload.
11. The contact clicks the CTA/link.
12. The public preference page lets the contact choose email, SMS, or both.
13. Messaging creates `MessageConsent` rows for the configured scopes.
14. Messaging marks the invitation accepted and stores accepted channels.

## Required message identity

Permission invitation emails must use:

```text
channel = email
purpose = transactional
scope = permission_invitation
dispatch_key = imported_contact_permission_invitation
message_type = imported_contact_permission_invitation
```

The message must carry:

```php
'consent_policy' => [
    'permission_invitation' => [
        'source' => 'imported_contact',
        'one_time' => true,
    ],
],
```

## One-time enforcement

A contact may receive one imported-contact permission invitation per channel/source.

The DB-level uniqueness rule is:

```text
contact_id + channel + source
```

Once a matching `contact_permission_invitations` row exists, Messaging should not send another invitation through the bypass.

This includes invitations that were claimed/sent before the public preference form was accepted.

## Consent behavior

Accepted public preferences create normal `MessageConsent` records.

Default scopes are:

```php
'broadcast'
'campaign'
```

Scopes are configured at:

```text
messaging.permission_invitations.consent.scopes
```

The default purpose for consent records is:

```text
marketing
```

The source is:

```text
imported_contact_permission_invitation
```

## SMS behavior

SMS must be explicit.

Do not infer SMS consent from:

- imported contact status
- email consent
- receiving the invitation email
- opening the public preference page
- choosing email only

If the contact chooses SMS, the form must collect or confirm a phone number.

SMS capabilities may exist in code while SMS options are hidden in UI by config.

## Config

Default config lives at:

```text
config/messaging/permission_invitations.php
```

Client override path:

```text
client/{client-key}/config/messaging/permission_invitations.php
```

Expected top-level keys:

```php
return [
    'email' => [],
    'consent' => [],
    'content' => [],
    'style' => [],
];
```

`email` controls CTA labels in the email payload.

`consent.scopes` controls which scopes receive consent records.

`content` controls public-page copy.

`style` controls public-page class strings.

## Email tokens and CTA

Messaging injects:

```text
{permission_invitation.url}
:permission_invitation.url
```

The email payload also receives:

```text
cta.label
cta.url
secondary_link.label
secondary_link.url
```

For the default email view, place `{cta}` on its own line in the body to render the button inline.

If `{cta}` is not present and the CTA exists, the default email view renders the button after the body.

## Testing expectations

Coverage should prove:

- public invitation page renders from a valid token
- invalid tokens return 404
- email-only acceptance creates email consent records
- SMS-only acceptance requires/stores phone and creates SMS consent records
- email+SMS acceptance creates both sets of consent records
- accepted invitations show the accepted state
- already accepted invitations do not create duplicate consent rows
- scheduled-send job injects the public preference URL before sending
- SMS does not receive the email-only bypass
- normal Broadcasts do not receive the imported-contact bypass