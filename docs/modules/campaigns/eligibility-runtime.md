# Campaign Eligibility Lifecycle Runtime

This document describes the Batch 2A lifecycle behavior layered on top of the
Campaign eligibility foundation.

## Ownership

Campaigns owns:

- whether a Campaign is automatic or manual;
- the Campaign eligibility filter;
- the per-Contact eligibility state and eligibility cycle;
- the re-entry policy;
- the behavior when eligibility becomes false;
- applying eligibility transitions to Campaign enrollment lifecycle.

Core and optional modules continue to own the facts queried by the shared
ContactFilter criteria registry. Messaging remains authoritative for MessageChain
progression, delivery, consent, suppression, and provider availability.

Flow Routes remain allowed to enroll, pause, resume, or stop Campaigns explicitly.
Automatic eligibility removes the need for Routes whose only business purpose is
"when this fact is true, start this Campaign."

## Automatic lifecycle semantics

Automatic lifecycle evaluation is applied only when all of the following are true:

1. the Campaign is active;
2. `enrollment_mode` is `automatic`;
3. the Campaign has a non-empty `eligibility_filter`.

Manual Campaigns are not evaluated and no eligibility-state row is created for them.

### Eligible

When the Contact is eligible:

- an existing active Campaign enrollment is left alone;
- an enrollment paused specifically because Campaign eligibility became false is
  resumed;
- an enrollment paused for another reason is not resumed automatically;
- a Contact with no historical enrollment may enroll, including retrying a prior
  family-priority block while eligibility remains true;
- a Contact with historical enrollment does not restart on the first automatic
  eligibility evaluation;
- a Contact with historical enrollment may create a new enrollment only after a
  genuine later eligibility cycle and only when `reentry_policy` is
  `when_eligible_again`.

This first-cycle history guard is deliberate. Converting an existing Campaign from
Flow-Route-driven entry to automatic eligibility must not restart every historical
participant whose current facts still match.

### Ineligible

When the Contact is ineligible and has an open enrollment:

- `continue` leaves the MessageChain enrollment untouched;
- `pause` pauses the MessageChain enrollment and skips pending Campaign messages;
- `cancel` cancels the MessageChain enrollment and skips pending Campaign messages.

When `pause` was applied by eligibility, a later return to eligibility resumes that
same enrollment. Human/manual pauses are not automatically resumed.

## Re-entry

`never` means a Contact who has already participated in the Campaign does not receive
a new enrollment after the existing enrollment becomes terminal.

`when_eligible_again` means a new enrollment may be created only after eligibility
has first become false and then true in a later eligibility cycle.

Re-entry policy does not prevent resuming the same enrollment after an
eligibility-owned pause, because resume is not a new Campaign enrollment.

## Atomicity and retries

`ApplyAutomaticCampaignEligibilityAction` wraps eligibility-state mutation and the
resulting Campaign lifecycle action in one database transaction. Unexpected lifecycle
failures therefore roll the eligibility-state transition back rather than leaving a
truth-state change that can no longer be acted upon.

Family-priority arbitration is an expected blocked outcome rather than an error. If a
Campaign has never enrolled the Contact, later reconciliation may retry enrollment
while the Contact remains eligible after the family blocker is gone.

## Reconciliation actions

`ReconcileContactCampaignEligibilityAction` evaluates one Contact against every
active automatic Campaign.

`ReconcileCampaignEligibilityAction` evaluates every Contact against one Campaign in
bounded chunks and returns action counts. It is an explicit runtime seam in this
batch; Batch 2B will wire relevant fact changes and the periodic reconciliation
safety net to these actions.

## Not in Batch 2A

This batch deliberately does not yet:

- listen to Contact Status changes;
- listen to tag changes;
- listen to Relationship stage changes;
- listen to imports or general Contact mutations;
- schedule periodic reconciliation;
- change Campaign authoring UI;
- change Process Highway presentation;
- remove Slam Dunk Flow Routes;
- change Messaging consent/scope semantics.