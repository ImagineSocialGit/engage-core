# Imported Contact Permission Invitations

Imported contact permission invitations are a Messaging-owned one-time consent flow for contacts imported from another system.

The purpose is to send one email asking an imported contact to confirm whether they want to receive future messages through email, SMS, or both.

This is not a general marketing consent bypass.

## Ownership

Messaging owns:

- `contact_permission_invitations`
- invitation token generation
- one-time send enforcement
- import-batch permission invitation scheduling action
- import-batch permission invitation eligibility checks
- duplicate pending/sent scheduled invitation protection
- public preference routes/controllers
- consent recording from the public form
- accepted channel tracking
- injection of public preference URLs into the invitation email payload

Core owns:

- Contact records
- contact import batches
- import batch CRM visibility
- generic contact filter normalization/resolution

Messaging owns the CRM action that schedules imported-contact permission invitation messages for an import batch.

Core owns the import batch records and import batch detail page.

Core may display the Messaging-owned action when Messaging is enabled, but Core must not import Messaging models, actions, or services directly.

Broadcasts may provide an operator-facing entry point for scheduling imported-contact permission invitations and may own Broadcast recipient bookkeeping for that batch operation.

Messaging still owns the permission-invitation capability itself, including:

- invitation one-time enforcement
- invitation claim/token creation
- consent creation
- public preference token behavior
- Messaging delivery gates
- invitation lifecycle state

Broadcasts must not directly create permission invitation records or bypass Messaging-owned claim, eligibility, consent, or delivery rules.

## Runtime flow

1. A client imports contacts.
2. Contacts are marked as imported by one of:
   - `source = import`
   - `meta.imported = true`
   - `meta.imported_at` present
   - a present `contact_import_batch_id`
3. An operator opens the Core-owned import batch detail page.
4. If Messaging is enabled, the page exposes a Messaging-owned action to send permission invitations for that batch.
5. The Messaging action evaluates imported contacts for eligibility.
6. Contacts are skipped when they:
   - are not imported
   - have no email address
   - already have the configured marketing email consent
   - already have an imported-contact email permission invitation row
   - already have a pending or sent imported-contact permission invitation scheduled message
7. Messaging schedules the canonical imported-contact permission invitation email message.
8. The scheduled-message send job evaluates final send-time gates.
9. The scheduled-message send job claims the permission invitation before provider send.
10. Messaging creates the `contact_permission_invitations` row at claim/send time.
11. Messaging injects the public preference URL into the email payload.
12. The contact clicks the CTA/link.
13. The public preference page lets the contact choose email, SMS, or both, depending on channel availability config.
14. Messaging creates `MessageConsent` rows for the configured scopes.
15. Messaging marks the invitation accepted and stores accepted channels.
16. After the acceptance transaction succeeds, Messaging emits the neutral `permission_invitation.accepted` automation event exactly once for that invitation.

## Import batch invitation visibility

The import batch detail page may show operator-facing permission invitation status for the currently displayed page of contacts.

This visibility is operational UI.

It is not the final permission check.

Messaging still owns eligibility checks, one-time enforcement, scheduled-message creation, send-time claiming, and consent creation.

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

Because permission invitation rows are claimed at send time, eligibility also treats an existing pending or sent `ScheduledMessage` with `message_type = imported_contact_permission_invitation` as already invited.

This prevents repeated operator clicks from scheduling duplicate invitation messages before the first message is sent.

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


These configured values are requested Messaging scopes. Consent persistence still passes through `ConsentDomainRegistry`.

That means:

```text
mapped related scope
    -> store/check the resolved consent domain

unknown unmapped scope
    -> remain narrow by falling back to itself

ambiguous equal-specificity mapping
    -> fail loudly
```

Permission invitations do not bypass consent-domain normalization and should not create a second parallel consent model.

The default purpose for consent records created from accepted preferences is:

```text
marketing
```

The invitation email itself remains transactional because it exists to ask for communication preferences, not to send normal marketing content.

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

`email` controls the invitation email subject/body and CTA labels.

`consent.scopes` controls which Messaging scopes are requested after acceptance; the consent grant path normalizes each through `ConsentDomainRegistry` before persistence.

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

Do not hand-author public preference URLs in client copy.


## Accepted automation event

Accepted permission invitations emit the neutral automation event:

```text
permission_invitation.accepted
```

Messaging remains independent from downstream consumers.

The acceptance path should:

```text
lock the invitation row
recheck accepted state inside the transaction
update the SMS phone number, when selected/provided
create/update configured MessageConsent rows
mark the invitation accepted
commit
emit permission_invitation.accepted after the transaction succeeds
```

The event is contact-scoped and uses `ContactPermissionInvitation` as its subject.

Payload includes compact invitation context such as:

```text
accepted_channels
consent_scopes
accepted_at
invitation source/status/channel
context_type / context_id
scheduled_message_id
```

The event must not be emitted again when an already accepted invitation is submitted again.

Downstream behavior belongs to consumers such as FlowRoutes through the generic `AutomationEventRecorded` seam. Messaging must not import FlowRoutes, Campaigns, Tasks, Workflow, or vertical modules to react to acceptance.

## Cancellation, skip, and failure lifecycle

Permission invitation lifecycle state and scheduled-message delivery state are related, but they do not use identical vocabularies.

Canonical permission invitation states remain:

```text
claimed
sent
failed
accepted
```

Do not add `cancelled` or `skipped` invitation states unless a later workflow proves they are needed.

Expected behavior:

```text
Cancelled before send-time claim
    ScheduledMessage = skipped
    ContactPermissionInvitation = no row
    BroadcastRecipient = cancelled, when Broadcast-owned

Messaging gate denial before claim
    ScheduledMessage = skipped
    ContactPermissionInvitation = no row
    BroadcastRecipient = skipped, when Broadcast-owned

Duplicate invitation discovered at claim
    ScheduledMessage = skipped
    Existing ContactPermissionInvitation = unchanged

Local preparation failure after claim, including unresolved tokens
    ScheduledMessage = skipped
    ContactPermissionInvitation = failed
    invitation.failure_reason mirrors the scheduled-message skip reason

Provider/runtime exception after claim
    ScheduledMessage = failed
    ContactPermissionInvitation = failed

Successful send followed by acceptance
    ScheduledMessage remains sent
    ContactPermissionInvitation = accepted
```

A claimed invitation must never remain stuck in `claimed` after its scheduled message reaches a terminal skipped state. Messaging listens to `ScheduledMessageSkipped` and reconciles a matching claimed invitation to `failed`.

The reconciliation is intentionally scoped by `scheduled_message_id` and `status = claimed`. Therefore:

- pre-claim skips create no invitation row;
- an existing sent/failed/accepted invitation is not rewritten;
- a duplicate scheduled attempt does not mutate the invitation that already consumed the one-time claim.

Failed invitation rows continue to count as already invited for one-time enforcement. Do not automatically create a fresh invitation after failure. A future explicit retry/reissue workflow may revisit that policy with operator-visible audit semantics.

## Testing expectations

Coverage should prove:

- public invitation page renders from a valid token
- invalid tokens return 404
- email-only acceptance creates email consent records
- SMS-only acceptance requires/stores phone and creates SMS consent records
- email+SMS acceptance creates both sets of consent records
- accepted invitations show the accepted state
- already accepted invitations do not create duplicate consent rows
- first acceptance emits exactly one `permission_invitation.accepted` automation event
- already accepted invitations do not emit the acceptance event again
- scheduled-send job injects the public preference URL before sending
- SMS does not receive the email-only bypass
- normal Broadcasts do not receive the imported-contact bypass
- import-batch permission invitation scheduling only schedules eligible contacts from the selected import batch
- running the import-batch scheduling action twice does not create duplicate pending/sent invitation messages
- contacts with existing imported-contact email invitation rows are skipped
- contacts with required marketing email consent are skipped
- contacts without email addresses are skipped
- contacts with `contact_import_batch_id` count as imported for final Messaging send-time enforcement
