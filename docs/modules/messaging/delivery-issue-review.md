# Messaging Delivery Issue Review

## Ownership

Messaging owns durable delivery suppression and operator review of current delivery problems.

The authoritative persistence remains:

```text
message_suppressions
```

Do not create a second delivery-issue table merely for CRM presentation.

`MessageGate` and `MessageSuppressionService` remain the runtime safety boundary. The review UI
does not decide whether a message may send.

## Current issue identity

An active suppression is an operator-facing delivery issue only while its destination still matches
a Contact's current destination for the same channel.

```text
email suppression
    matches current Contact.email case-insensitively

sms suppression
    matches current Contact.phone using the stored runtime destination value
```

Historical suppressions remain durable even after Contact information changes.

## Correcting bad Contact information

When an operator determines that the Contact destination itself is wrong:

```text
old destination
    remains suppressed
    remains historical evidence

Contact email/phone
    is corrected through normal Contact editing

review state
    disappears automatically because the old suppressed destination
    no longer matches the Contact's current destination
```

Do not rewrite the historical suppression to the corrected destination.

Do not release the historical suppression merely because the Contact record was corrected.

## Dismissing operator review

An authenticated CRM operator may dismiss a current delivery issue from the review queue without
releasing the underlying destination suppression.

Dismissal is review state only:

```text
message_suppressions.released_at
    remains null

delivery/runtime gate
    remains suppressed

CRM review queue / dashboard / Contact issue panel
    no longer shows the dismissed issue
```

Dismissal evidence is stored under `message_suppressions.meta.delivery_issue_review`.

If a later distinct provider event suppresses the same still-active destination again, Messaging
reopens the delivery issue for operator review while preserving the prior dismissal evidence.

A soft-deleted Contact is never a current delivery-issue owner. Raw current-contact matching must
explicitly exclude `contacts.deleted_at` because Query Builder subqueries do not receive Eloquent's
SoftDeletes global scope.

## Explicit suppression release

When the current destination is genuinely correct and the underlying delivery/provider problem has
been resolved, an authenticated CRM operator may explicitly release an eligible suppression.

Manual CRM release must use:

```text
MessageSuppressionService::release()
```

The original suppression record remains durable and receives `released_at`.

Release audit evidence is retained under the existing release metadata:

```text
source = crm_delivery_issue_review
actor_user_id
resolution_reason
message_suppression_id
```

Supported general-review reasons are:

```text
destination_verified
provider_issue_resolved
manual_review_resolved
```

## Complaint boundary

Complaint suppressions are visible to operators but are not releasable from the general delivery
issue review surface.

A complaint is not treated as an ordinary typo or transient provider problem.

Any future complaint-remediation workflow must deliberately address provider and consent policy
rather than reopening delivery through this generic review action.

## Unsubscribe boundary

Unsubscribe remains consent revocation state.

It is not represented as a delivery-quality issue and this workflow must never silently opt a
Contact back into marketing.

## Operator surfaces

Messaging contributes current delivery issues through:

```text
Contact detail
    Messaging Delivery Issues panel

CRM review queue
    /messaging/delivery-issues

Dashboard
    messaging.delivery_issues
```

The dashboard panel is immediate work and returns no panel when there are no current issues.

## Audience boundary

Do not register delivery-issue state as a generic `ContactFilterCriterion`.

The generic Contact filter registry is also consumed by Broadcast and Campaign audience authoring.
A bounced or suppressed destination must not become a selectable marketing audience simply because
it needs operator review.

## Provider feedback relationship

Provider webhooks create normalized suppression evidence through Messaging-owned message-event
handling. The review workflow consumes that durable state; it does not parse provider payloads
itself.

Raw provider payloads remain within the webhook inbox/audit boundary established by Messaging
provider-event handling.

## Contact deletion resolution

A delivery issue has two distinct operator resolutions while the destination still belongs to the current Contact:

- keep the Contact and release an eligible suppression only after verification;
- delete the Contact when the Contact itself is invalid or should no longer remain active.

Contact deletion never releases or deletes `message_suppressions`. The suppression remains durable destination-level delivery evidence.

Before Core soft-deletes a Contact, Messaging cancels active/paused MessageChain enrollments for that Contact and skips pending ScheduledMessages addressed to it with reason `contact_deleted`. Already-sending or terminal deliveries are not rewritten.

## Immediate runtime consequence of a new suppression

A new durable provider suppression is both a future-send gate and an immediate pending-work boundary.

When email provider feedback creates a durable suppression for a Contact's current email destination, Messaging immediately marks that Contact's still-pending email ScheduledMessages as skipped. This prevents already-planned reminders/follow-ups from remaining apparently pending until their future send time merely to be denied by the send gate.

The send-time `MessageGate` remains the final safety boundary. It continues to reject an active suppressed destination even if a pending row was created through an unexpected path or escaped proactive cleanup.

Already-sending and terminal deliveries are not rewritten. Suppression remains channel-specific.