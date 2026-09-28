# Recurring Allocation Campaigns

## Purpose

Campaigns supports two execution shapes:

```text
sequence
    A Contact enters one Messaging MessageChainEnrollment and progresses through
    one immutable MessageChainVersion in order.

recurring_allocation
    Campaigns periodically allocates distinct eligible Contacts to individual
    messages from an immutable Campaign MessageChainVersion. There is no
    per-Contact MessageChain progression state.
```

Campaigns owns allocation cadence, participation, candidate selection, message
assignment, cooldown, and allocation-run history. Messaging remains authoritative
for reusable message copy, immutable MessageChain versions, consent,
suppressions, destination/runtime availability, ScheduledMessage delivery,
provider submission, and normal send-time recipient gates.

Recurring allocation does not create fake sequential CampaignEnrollments or
MessageChainEnrollments merely to reuse Messaging behavior.

## Campaign configuration

`campaigns.execution_strategy` is one of:

```text
sequence
recurring_allocation
```

Existing Campaigns default to `sequence`.

Recurring allocation settings are stored in `campaigns.allocation_settings`:

```text
run_every_days
allocation_size_per_message
recipient_cooldown_days
```

Working defaults:

```text
run_every_days = 14
allocation_size_per_message = 50
recipient_cooldown_days = 14
```

Meaning:

```text
run_every_days
    Minimum cadence between completed/failed allocation runs.

allocation_size_per_message
    Maximum number of distinct Contacts allocated to each active message in one
    allocation run.

recipient_cooldown_days
    Minimum time after an allocation reservation or dated prior receipt before a
    Contact can be allocated another message from this Campaign.
```

An allocation assignment starts cooldown at `assigned_at`. This is deliberate:
allocation is a reservation, and a Contact with a pending/sending allocation
message is also excluded from new allocation regardless of cooldown. A dated
external prior receipt participates in cooldown. An undated prior receipt still
excludes that exact message but does not invent a Campaign-wide cooldown date.

Allocation size is not a daily/provider send limit. Campaign send-pattern pacing
remains the authority for marketing-email scheduling. In `spread` mode, the
existing Campaign daily limit and delivery window count sequential Campaign
messages and recurring-allocation messages together for the same Campaign.
Recurring allocation does not introduce a competing limiter.

Recurring allocation currently requires:

```text
enrollment_mode = manual
family_key = null
```

That remains deliberate fail-closed behavior. Existing automatic eligibility
lifecycle and Campaign-family priority arbitration operate on sequential
CampaignEnrollment / MessageChainEnrollment state. Automatic allocation
enrollment and cross-strategy family arbitration require explicit later
integration.

Changing execution strategy remains guarded. A Campaign cannot switch from
sequence to recurring allocation while sequential enrollments are open, and it
cannot switch back to sequence while allocation participation or a
scheduled/running allocation run remains open. Historical terminal
enrollment/assignment evidence remains intact.

## Allocation enrollment

Recurring allocation participation is stored in:

```text
campaign_allocation_enrollments
```

Important fields:

```text
campaign_id
contact_id
source_type / source_id
start_message_step_key
status
dedupe_key
started_at
completed_at
cancelled_at
meta
```

`start_message_step_key` is an inclusive allocation floor. If a Contact starts at
message C, A and B are not candidates for that enrollment. The floor does not
claim A or B were sent or received.

Re-enrollment creates a new allocation enrollment history row rather than
rewriting historical assignments. Prior assignments, prior receipts, and
explicit exclusions survive re-enrollment.

## Allocation runs

Each recurring allocation pass is durable in:

```text
campaign_allocation_runs
```

Run lifecycle values are:

```text
scheduled
running
completed
failed
cancelled
```

The scheduler checks active recurring-allocation Campaigns every minute.

The first run becomes due as soon as the Campaign has an active allocation
enrollment. Later runs are due after `run_every_days` from the previous
completed/failed run. A cancelled run does not impose a new cadence delay.

A scheduled run snapshots:

```text
allocation settings
selected MessageChain id
current published MessageChainVersion id
```

The immutable MessageChainVersion is the message-definition authority for that
run even if future Campaign authoring publishes a newer version while the run is
processing.

The run key is stable and idempotent for one pass after the previous durable run.
A scheduled run is redispatched if needed; the run processor and assignment
uniqueness rules make retries safe.

Run completion means:

```text
candidate allocation and ScheduledMessage planning completed
```

It does not mean provider delivery completed. ScheduledMessage terminal delivery
can happen later.

A run may complete with zero assignments. Active allocation enrollments remain
active so messages appended later can become eligible without re-enrolling the
audience.

Campaign deactivation immediately cancels active allocation enrollments, skips
their pending assignment-context ScheduledMessages through Messaging, and marks
scheduled/running allocation runs cancelled.

## Allocation assignments

A durable allocation decision is stored in:

```text
campaign_allocation_assignments
```

Each assignment records:

```text
Campaign
Contact
allocation run
allocation enrollment
immutable Messaging MessageChainVersion
stable message_step_key
optional ScheduledMessage
assigned_at
sent_at
meta
```

A Contact + Campaign + message step may be assigned only once. Re-enrollment
does not make an already-assigned message eligible again.

All active messages in one run draw from one shared Contact pool. Once a Contact
is assigned to one message in a run, that Contact is unavailable to every other
message in that run.

Candidate ordering is deterministic and fairness-oriented:

```text
never previously allocated / no dated prior receipt first
then oldest last allocation activity
then oldest enrollment/id as stable tie-breakers
```

This is not random A/B assignment.

`scheduled_message_id` is a logical cross-module reference. Campaigns depends on
Messaging, but Campaigns does not add physical foreign keys into
Messaging-owned tables.

## Candidate eligibility

For one run/message, Campaigns requires all of the following before a Contact can
be assigned:

```text
Campaign is active and recurring_allocation
Campaign uses manual enrollment and no family_key
allocation enrollment is active
current Campaign eligibility criteria still pass when criteria are configured
message is active in the run's pinned published MessageChainVersion
message is at/after the enrollment start-message floor
Contact has no prior receipt for this message
Contact has no prior allocation assignment for this message
Contact has no explicit allocation exclusion for this message
Contact has no pending/sending allocation ScheduledMessage
recipient cooldown has elapsed
Contact has not already been assigned elsewhere in this run
```

The runtime currently requires allocation message steps to use:

```text
variant_strategy = first_available
```

The first currently plannable active variant is selected. Allocation does not
reinterpret `send_all_eligible` or dependency-aware step semantics.

Before an assignment is created, the selected variant must also pass the normal
Messaging planning boundaries used by MessageChains:

```text
Campaigns surface channel availability
step conditions
variant conditions
resolvable immutable MessageTemplateVersion
recipient destination
Messaging consent
Messaging suppression
```

Messaging remains authoritative for those checks.

## ScheduledMessage planning

After Campaigns records the assignment, it schedules through
`ScheduleMessageAction`.

The ScheduledMessage uses:

```text
recipient
    Contact

context
    CampaignAllocationAssignment

behavior owner
    CampaignAllocationAssignment

message template version
    immutable version selected by the pinned MessageChainVersion

message-chain enrollment
    none

message-chain step variant runtime reference
    none
```

This is intentionally not a fake MessageChain enrollment.

The allocation ScheduledMessage carries Campaign-compatible runtime tokens,
including:

```text
contact / recipient
campaign
campaign_enrollment
campaign_allocation_enrollment
campaign_allocation_assignment
campaign_allocation_run
```

`campaign_enrollment` is retained as a compatibility alias for existing
Campaign-authored token expectations; its value is the allocation enrollment,
not a sequential CampaignEnrollment.

The message also carries Campaign metadata such as `campaign_key` and the stable
message step key so existing Campaign email presentation behavior can continue
without changing Messaging ownership.

Scheduling uses a stable dedupe key derived from the allocation assignment id.
If a run job retries after the ScheduledMessage was already created, Messaging
returns the same scheduled message rather than creating a duplicate.

## Send-pattern pacing

`CampaignSendPatternConstraintProvider` resolves Campaign ownership from either:

```text
CampaignEnrollment
CampaignAllocationAssignment
```

For marketing email in `spread` mode, the existing pacing algorithm counts
scheduled messages from both context types against the same Campaign daily
capacity and window.

That preserves one Campaign-owned pacing policy:

```text
allocation decides who/message
send pattern decides when the marketing email can be scheduled
Messaging decides whether/how it can actually be delivered
```

## Prior-message evidence

`campaign_prior_message_receipts` remains the authority for known messages
received outside the normal Campaign runtime.

For recurring allocation:

- a prior receipt excludes that same `message_step_key`;
- a receipt with `received_at` contributes to cooldown;
- a receipt without `received_at` excludes only that message and does not invent
  a date;
- no fake ScheduledMessage is created.

The send-time Campaign prior-receipt recipient gate now also understands
`CampaignAllocationAssignment` context. If prior receipt evidence is recorded
after allocation planning but before send, Messaging skips the scheduled
allocation message rather than delivering a known duplicate.

## Explicit message exclusions

Per-Contact allocation exclusions remain durable in:

```text
campaign_allocation_message_exclusions
```

Example:

```text
Start at A, but explicitly disable B.
```

means:

```text
A remains eligible if otherwise allowed.
B is excluded.
C and later messages remain eligible.
```

An exclusion does not claim a send, receipt, or delivery failure.

The allocation candidate selector checks exclusions before assignment. A
Campaign allocation recipient gate also checks the live exclusion at send time.
That means an exclusion recorded after a ScheduledMessage was planned still
prevents delivery.

## Send-time safety

Allocation ScheduledMessages continue through Messaging's normal send-time
gates.

Campaigns contributes two allocation-aware checks:

```text
CampaignAllocationRecipientGate
    Campaign is still active/recurring
    allocation enrollment is still active
    recipient identity still matches
    no live explicit exclusion exists for the assigned message

CampaignPriorReceiptRecipientGate
    no prior receipt now exists for the assigned message
```

These are in addition to Messaging consent, suppression, destination,
conditions, and provider/runtime checks.

## Delivery reconciliation

Campaigns listens to Messaging terminal ScheduledMessage events:

```text
sent
skipped
failed
cancelled
```

For allocation-context messages, the matching assignment stores compact terminal
delivery evidence in `meta.delivery`.

Only a `sent` result populates:

```text
campaign_allocation_assignments.sent_at
```

Skipped, failed, and cancelled messages remain distinguishable from actual
delivery.

Allocation-run completion is not reopened or rewritten by later terminal
delivery. Run history describes allocation/planning; assignment delivery evidence
describes what happened afterward.

## Lifecycle actions

The runtime has explicit non-UI seams for:

```text
UpdateCampaignAllocationSettingsAction
EnrollContactInCampaignAllocationAction
CancelCampaignAllocationEnrollmentAction
ReenrollContactInCampaignAllocationAction
ReenrollContactInCampaignAction
ExcludeContactFromCampaignAllocationMessageAction
RemoveContactCampaignAllocationMessageExclusionAction
ScheduleDueCampaignAllocationRunsAction
ProcessCampaignAllocationRunAction
```

The recurring scheduler is driven by:

```text
ProcessDueCampaignAllocationsJob
ProcessCampaignAllocationRunJob
```

## Bulk Contact-result operations

Campaigns has one shared queued operation pipeline for operator-selected Contact
result sets.

Supported operation identities are:

```text
enroll
reenroll_from_message
exclude_allocation_message
remove_allocation_message_exclusion
enroll_with_allocation_message_exclusion
```

`enroll` chooses the correct lifecycle seam from the Campaign execution
strategy. Sequential Campaigns use ordinary Campaign enrollment; recurring
allocation Campaigns create allocation participation without fabricating a
MessageChainEnrollment.

`reenroll_from_message` also chooses by execution strategy. Sequential
Campaigns terminate open sequential enrollment and create a fresh enrollment
starting at the selected current message. Recurring allocation Campaigns
terminate active allocation participation and create a fresh allocation
enrollment whose selected message is the inclusive floor.

Allocation message exclusion operations remain allocation-only. The combined
`enroll_with_allocation_message_exclusion` operation records the exclusion
inside the same database transaction before allocation enrollment becomes
visible, so the scheduler cannot allocate that disabled message in between the
two operator intents.

Each operator submission receives one stable operation id. That id becomes the
stable entry identity used by sequential/allocation enrollment and
re-enrollment, making chunk retries idempotent. Chunk jobs re-check the actor's
Campaign Contact-result capability and Contact visibility before applying any
mutation.

One domain-invalid Contact does not poison the rest of a chunk. Expected
Campaign lifecycle/configuration rejections are logged and skipped; unexpected
runtime/database failures still fail the job and use normal queue retry
behavior.

Recording an allocation message exclusion immediately skips any still-pending
ScheduledMessage already planned from a matching allocation assignment. The
send-time exclusion gate remains the final safety net for races where planning
and exclusion happen concurrently.

Removing an exclusion restores future eligibility only when no prior assignment
already makes that message historical. It does not resurrect a terminal skipped
ScheduledMessage or erase assignment history.

The current simple CRM `Enroll in campaign` result action still uses its legacy
job class, but that job now delegates to this shared operation processor. This
means the existing simple bulk-enroll surface works for both execution
strategies before the richer operator UI is added.

## Production worker rollout

Normal local development does not require a queue/Horizon restart after applying
these source batches.

Production rollout of recurring-allocation runtime changes must restart the
long-lived workers after code pull and Campaigns migrations, before relying on
the new scheduler/jobs:

```text
git pull / deploy application code
run module migrations
php artisan queue:restart
sudo supervisorctl restart <client>-horizon
run validation / smoke checks
```

Do not use `php artisan horizon:terminate` for this deployment flow.

## Campaign Builder parity

Recurring allocation uses the same Campaign Setup authoring model as sequential Campaigns. Operators choose the execution strategy in Start, then use the existing Schedule and Messages editors to add, remove, reorder, and edit message copy.

The allocation-specific Start controls are:

```text
run every N days
leads per message per run
recipient cooldown in days
```

Schedule timing remains a Messaging MessageChain concern. Recurring allocation supports active `immediate` and `delay` steps. Waits may use seconds, minutes, hours, or days and are cumulative from the start of each allocation run. For example, an immediate first message, a two-day wait, and then a three-day wait are due at run start, run start +2 days, and run start +5 days. Each message is still allocated to a different eligible Contact from the shared run pool.

Existing allocation assignments remain pinned to the immutable MessageChainVersion they were created from. Publishing schedule changes affects future allocation runs; it does not rewrite already-created assignments. Sequential-only `extend_in_progress` append behavior is not available for recurring allocation.

Allocation candidate cooldown and fairness use known successful assignment `sent_at` timestamps plus dated prior-message receipts. Merely assigning a message does not invent successful-contact cooldown evidence. Pending/sending allocation messages remain separately protected from overlapping selection.

The workspace, Campaign index, and audience/progress read models are execution-strategy aware so active allocation participation is not reported as zero sequential enrollments.

## Still intentionally deferred

This runtime batch does not add:

```text
automatic eligibility enrollment for recurring allocation
cross-strategy Campaign-family arbitration
contact-import allocation floor/exclusion authoring
rich Contact-result operation controls
Campaign allocation run/history workspace UI
manual run/preview controls
new recurring-allocation test files
```

Those belong to the UI/surface and dedicated test phases.