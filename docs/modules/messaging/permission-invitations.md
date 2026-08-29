# Imported Contact Permission Invitations

Imported-contact permission invitations are a Messaging-owned one-time opt-in flow for
contacts that do not already have confirmed marketing permission.

They are not part of Contact import completion and they are not a general marketing
consent bypass.

## Import-time consent decision

When Messaging is enabled, every add-import exposes a batch-wide Marketing permission
decision before processing begins.

The operator must choose one of:

```text
Yes — permission was already collected elsewhere
No / I’m not sure
```

When prior permission is confirmed, the operator independently selects the channels that
already have permission and attests that the selected permission was previously granted.
Only those selected channels are imported as normal marketing consent.

When prior permission is not confirmed, the Contact import proceeds without marketing
permission. Import does not automatically schedule or send a permission invitation.

The import-batch detail page is Core operational history only. It does not own a Messaging
permission-invitation card, count, send button, or cancellation button.

An operator who wants to request permission later uses the opt-in Broadcast workflow.

## Ownership

Messaging owns:

- `contact_permission_invitations`;
- invitation token generation;
- one-time send enforcement;
- invitation eligibility and send-time claiming;
- public preference routes/controllers;
- consent recording from the public form;
- accepted channel tracking;
- injection of public preference URLs into the invitation email payload;
- the Contact-import `marketing_permission` post-processor.

Core owns:

- Contact records;
- Contact import batches and runs;
- the generic post-import processor/operator-input seams;
- import-batch CRM visibility.

Broadcasts may provide the operator-facing opt-in invitation Broadcast and owns its normal
Broadcast recipient bookkeeping. Broadcasts must not directly create permission-invitation
rows or bypass Messaging eligibility, claim, consent, or delivery rules.

## Opt-in invitation runtime flow

1. An operator creates/schedules an imported-contact opt-in Broadcast.
2. Broadcasts resolves and snapshots the recipient audience.
3. Messaging schedules the canonical imported-contact permission invitation email.
4. The ScheduledMessage send job evaluates final send-time gates.
5. The send job claims the one-time permission invitation before provider submission.
6. Messaging creates the `contact_permission_invitations` row at claim time.
7. Messaging injects the public preference URL into the email payload.
8. The contact opens the preference page and explicitly selects email, SMS, or both.
9. Messaging creates normal marketing `MessageConsent` rows for selected channels.
10. Messaging marks the invitation accepted and emits `permission_invitation.accepted`
    exactly once after the acceptance transaction succeeds.

## Required message identity

Permission invitation emails use:

```text
channel = email
purpose = transactional
scope = permission_invitation
dispatch_key = imported_contact_permission_invitation
message_type = imported_contact_permission_invitation
```

The message carries:

```php
'consent_policy' => [
    'permission_invitation' => [
        'source' => 'imported_contact',
        'one_time' => true,
    ],
],
```

## One-time enforcement

A contact may consume one imported-contact permission invitation per channel/source.
The DB-level uniqueness boundary is:

```text
contact_id + channel + source
```

At send time, `ContactPermissionInvitationService::claimForScheduledMessage()` first checks
for an existing matching invitation under a transaction/lock. If a concurrent insert causes
a unique-key race, the service re-reads the persisted invitation and may return the claimed
row for the same ScheduledMessage or `null` for a genuinely consumed invitation.

A `QueryException` is **not** proof that an invitation was already used. If the post-exception
re-read finds no matching persisted invitation, the original exception must be rethrown so
the ScheduledMessage job follows normal retry/failure behavior. Infrastructure failures must
never be converted into the business reason `permission_invitation_already_used`.

## Consent behavior

Accepted public preferences create normal `MessageConsent` records at the hard permission
boundary:

```text
channel + purpose
```

Permission-invitation acceptance uses:

```text
purpose = marketing
scope = permission_invitation
source = imported_contact_permission_invitation
```

The scope is capture provenance/context; it does not confine marketing permission to
invitation-specific messages. A later channel+marketing revocation blocks marketing on that
channel across scopes. Email and SMS remain independent.

Import-time confirmed permission uses the same normal marketing-consent domain. Its consent
metadata records that the permission was operator-attested as pre-existing; the import does
not fabricate a new contact interaction as evidence.

## SMS behavior

SMS permission must be explicit. Do not infer it from:

- imported status;
- email permission;
- receiving an invitation email;
- opening the public preference page;
- choosing email only.

If the public invitation accepts SMS, the form must collect or confirm a usable phone number.

## Cancellation, skip, failure, and retry

Permission invitation lifecycle state and ScheduledMessage delivery state are related but do
not use identical vocabularies.

Canonical invitation states remain:

```text
claimed
sent
failed
accepted
```

Expected behavior:

```text
Cancelled before send-time claim
    ScheduledMessage = skipped
    ContactPermissionInvitation = no row

Messaging gate denial before claim
    ScheduledMessage = skipped
    ContactPermissionInvitation = no row

Duplicate invitation discovered at claim
    ScheduledMessage = skipped
    Existing ContactPermissionInvitation = unchanged

Database/transient failure while claiming, no invitation persisted
    exception = rethrown
    ScheduledMessage job = normal retry/failure path
    ContactPermissionInvitation = no fabricated row/reason

Local preparation failure after claim
    ScheduledMessage = skipped
    ContactPermissionInvitation = failed

Provider/runtime exception after claim
    ScheduledMessage = failed
    ContactPermissionInvitation = failed

Successful send followed by acceptance
    ScheduledMessage remains sent
    ContactPermissionInvitation = accepted
```

A claimed invitation must not remain stuck in `claimed` after its ScheduledMessage reaches a
terminal skipped/failed state. Existing reconciliation listeners remain scoped to the matching
`scheduled_message_id` and `status = claimed`.

## Config and CTA

Default config:

```text
config/messaging/permission_invitations.php
```

Client override:

```text
client/{client-key}/config/messaging/permission_invitations.php
```

Messaging injects the public preference URL through:

```text
{permission_invitation.url}
:permission_invitation.url
```

and supplies canonical CTA/secondary-link payload values. Do not hand-author public
preference URLs in client copy.

## Testing expectations

Coverage should prove:

- import add-mode requires an explicit marketing-permission decision when Messaging is enabled;
- `No / I’m not sure` removes marketing-permission processing from the durable import plan;
- confirmed permission requires at least one selected allowed channel plus attestation;
- revoked marketing channels are not silently reactivated by import;
- public invitation acceptance remains explicit per channel;
- one-time invitation claiming remains race-safe;
- a claim-time `QueryException` with no persisted invitation is rethrown for job retry;
- a genuine already-consumed invitation remains a business-rule skip;
- Broadcast recipient diagnostics resolve terminal reason/provider evidence from Messaging's
  normalized terminal persistence rather than copying that data into BroadcastRecipient.