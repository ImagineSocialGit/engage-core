# Recurring Allocation Campaigns

## Purpose

Campaigns supports two different execution shapes:

```text
sequence
    A Contact enters one Messaging MessageChainEnrollment and progresses through
    that immutable MessageChainVersion in order.

recurring_allocation
    Campaigns periodically allocates distinct eligible Contacts to individual
    messages from the Campaign's current published MessageChainVersion. There is
    no per-Contact MessageChain progression state.
```

The execution strategy is Campaign-owned because it describes how a Campaign
uses its message set. Messaging remains authoritative for reusable message copy,
immutable MessageChain versions, consent, suppressions, destination/provider
eligibility, ScheduledMessage delivery, and provider submission.

Recurring allocation must not create fake sequential CampaignEnrollments or
MessageChainEnrollments merely to reuse Messaging runtime behavior.

## Campaign configuration

`campaigns.execution_strategy` is one of:

```text
sequence
recurring_allocation
```

Existing Campaigns are migrated to `sequence`, so this foundation does not
change existing runtime behavior.

Recurring allocation settings are stored in `campaigns.allocation_settings`.
The runtime authoring contract will normalize these keys:

```text
run_every_days
allocation_size_per_message
recipient_cooldown_days
```

Meaning:

```text
run_every_days
    Minimum cadence between allocation runs.

allocation_size_per_message
    Maximum number of distinct Contacts allocated to each active message in one
    allocation run.

recipient_cooldown_days
    Minimum time after a known prior send/receipt before the Contact may receive
    another message from this allocation Campaign.
```

Allocation size is not a provider or daily send limit. Campaign send-pattern
pacing remains the separate authority for when allocated marketing email may be
scheduled. `Spread throughout the day` can defer allocated messages across later
allowed days when daily capacity is exhausted; `Send as due` does not gain a new
Campaign daily cap merely because recurring allocation is enabled.

## Allocation enrollment

Recurring allocation has its own Campaign-owned enrollment history in:

```text
campaign_allocation_enrollments
```

An allocation enrollment identifies that one Contact is participating in one
recurring allocation Campaign. It does not own generic message progression and
therefore has no MessageChainEnrollment.

Important fields:

```text
campaign_id
contact_id
source_type / source_id
start_message_step_key
status
stable dedupe_key
started_at
completed_at
cancelled_at
meta
```

`start_message_step_key` is an inclusive allocation floor. If a Contact is
explicitly enrolled or re-enrolled starting at message C, messages before C are
not allocation candidates for that enrollment. Those earlier messages are not
recorded as sent or previously received merely because the floor starts later.

Re-enrollment creates a new allocation enrollment history row rather than
rewriting historical assignments. The actions batch owns active-enrollment
replacement/cancellation and idempotency rules.

## Allocation runs

Each recurring allocation evaluation is durably represented by:

```text
campaign_allocation_runs
```

A run stores a stable `run_key`, Campaign, scheduled time, lifecycle timestamps,
status, and compact metadata. Runtime jobs use the run identity as the durable
idempotency boundary for one allocation pass.

Run lifecycle values are:

```text
scheduled
running
completed
failed
cancelled
```

The runtime batch will create and process these rows transactionally. This
schema batch does not schedule or execute runs.

## Allocation assignments

A successful allocation decision is durable in:

```text
campaign_allocation_assignments
```

Each assignment records:

```text
campaign
Contact
allocation run
allocation enrollment
exact Messaging MessageChainVersion
stable message_step_key
optional ScheduledMessage
assigned_at
sent_at
```

The stable message step key is the Campaign business identity for the message.
The MessageChainVersion records the immutable Messaging definition that was
current when the allocation was made.

A Contact + Campaign + message step may be assigned only once. Re-enrolling a
Contact does not erase prior assignment history and does not make an already
allocated message eligible again.

Within one run, all active messages draw from one shared eligible Contact pool.
Once a Contact is assigned to one message, that Contact is removed from the pool
for the rest of that run. A single run therefore does not allocate several
Campaign messages to the same Contact.

`scheduled_message_id` remains a logical cross-module reference. Campaigns
depends on Messaging, but the Campaigns schema does not add physical foreign
keys into Messaging-owned tables whose migration order is separate.

## Prior-message evidence

Existing `campaign_prior_message_receipts` remains the authority for known
messages received outside the normal Campaign runtime.

That evidence is intentionally different from an allocation assignment:

```text
prior receipt
    The Contact is known to have actually received that Campaign message before.

allocation assignment
    This system selected that Contact/message pair for an allocation run.
```

For recurring allocation:

- a prior receipt excludes that same `message_step_key` from future allocation;
- a prior receipt with `received_at` contributes known timing to recipient
  cooldown evaluation;
- a prior receipt without `received_at` still excludes that message, but does
  not invent a date for Campaign-wide cooldown calculations;
- no fictitious historical ScheduledMessage is created.

The existing Contact-import prior-message surface therefore remains reusable for
allocation Campaigns.

## Explicit message exclusions

A per-Contact allocation exclusion is durable in:

```text
campaign_allocation_message_exclusions
```

This is intentionally separate from prior-message evidence.

Example:

```text
Start at A, but explicitly disable B.
```

means:

```text
A remains eligible if otherwise allowed.
B is excluded for this Contact.
C and later messages remain eligible.
```

The exclusion must not claim B was sent, received, skipped by Messaging, or
otherwise delivered. It records only an operator/system decision that B is not
an allocation candidate for this Contact.

Exclusions use the stable Campaign message step key and retain optional source,
operator, and reason provenance.

## Candidate eligibility contract

The runtime allocator will build candidates from active recurring-allocation
enrollments and then apply Campaign-owned allocation rules before asking
Messaging to plan delivery.

Campaign-owned exclusion rules include:

```text
Campaign is active and uses recurring_allocation
allocation enrollment is active
current Campaign eligibility policy still permits participation
message is active in the current published MessageChainVersion
message is at/after the enrollment's start_message_step_key floor
Contact has no prior receipt for that message
Contact has no prior allocation assignment for that message
Contact has no explicit allocation exclusion for that message
Contact has no already-pending allocation assignment that would overlap this run
known recipient cooldown has elapsed
Contact has not already been selected by another message in the same run
```

Messaging then remains responsible for its normal planning and recipient gates,
including consent, suppression, destination availability, provider/runtime
availability, message payload resolution, and delivery.

Allocation must not weaken those Messaging gates.

## Re-enrollment semantics

The operator-facing bulk action will support both execution strategies.

Sequential Campaign:

```text
re-enroll starting at message C
    terminate the current open sequential enrollment through the normal
    Campaign/Messaging lifecycle path, then create a new CampaignEnrollment whose
    MessageChainEnrollment starts at active step C.
```

The existing Campaign/Messaging enrollment seam already supports a
`startStepKey`; the later actions batch will expose a deliberate re-enrollment
path rather than changing ordinary enrollment arbitration.

Recurring allocation Campaign:

```text
re-enroll starting at message C
    end/replace the current active allocation enrollment and create a new active
    allocation enrollment with start_message_step_key = C.
```

Historical allocation assignments, prior receipts, and explicit exclusions are
preserved. Re-enrollment changes the participation floor; it is not a history
reset.

## Schema in this foundation batch

This foundation adds:

```text
campaigns.execution_strategy
campaigns.allocation_settings
campaign_allocation_enrollments
campaign_allocation_runs
campaign_allocation_assignments
campaign_allocation_message_exclusions
```

and the corresponding Campaign-owned Eloquent models/relationships.

It deliberately does not yet add:

```text
allocation scheduler/jobs
allocation candidate selection
allocation transaction/locking logic
ScheduledMessage planning for assignments
send-pattern bridge generalization
sent/cooldown reconciliation listeners
bulk enroll/re-enroll/exclude actions
import enrollment-floor/exclusion authoring
CRM allocation settings
Campaign workspace surfaces
new recurring-allocation tests
```

Those belong to the actions/jobs, UI/surface, and test batches that follow this
schema contract.