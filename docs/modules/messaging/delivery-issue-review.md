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