# Campaigns Module

## Status

Campaigns is optional.

The Campaign -> Messaging MessageChain runtime cutover is complete. The ownership boundary is now:

```text
Campaigns owns Campaign identity, activation, audience/enrollment intent, provenance, and Campaign-specific reporting meaning.
Messaging owns reusable MessageChains, immutable versions, MessageChainEnrollments, timing, progression, lifecycle state, and delivery.
Campaigns references Messaging runtime state instead of maintaining a second chain engine.
```

Campaign preset sync still converts the current compact Campaign step/variant authoring definition into a Messaging-owned MessageChain and immutable published MessageChainVersion, then stores the selected chain on `campaigns.message_chain_id`. That conversion is now only a temporary authoring bridge. `campaign_steps` and `campaign_step_variants` are not runtime progression state.


## Campaign identity and eligibility foundation

`campaigns.key` is the canonical Campaign machine identity. Legacy
channel/purpose/scope arguments may remain on public enrollment calls for
compatibility, but they do not select which Campaign is being enrolled.

Campaigns now owns first-class eligibility policy:

- `eligibility_filter`
- `enrollment_mode`
- `reentry_policy`
- `ineligible_behavior`

and derived per-Contact state in `campaign_eligibility_states`.

Eligibility reuses Core's contributed Contact-filter criterion registry rather
than creating a Campaign-specific rule engine or a dependency on Workflow or
Relationships. Stored criteria use stable semantic values; the current Workflow
status criterion's numeric runtime values are translated from stable
ContactStatus keys at the Campaign/Core boundary.

This foundation only evaluates and records eligibility transitions. Automatic
enrollment, false-eligibility lifecycle behavior, event-driven reevaluation,
periodic reconciliation, and Process Highway presentation are separate follow-up
runtime/surface batches.

See `docs/modules/campaigns/eligibility.md`.

## Annual Touches audience independence

Annual Touches are standalone recurring programs and do not require Workflow, a Contact Status, or Campaign enrollment. Their durable audience is stored in `campaign_touch_programs.audience_filter` and can select all Contacts, contributed Contact-filter criteria, or explicit Contacts, with optional criterion/contact exclusions.

The audience reuses Core's `ContactFilterCriterionRegistry`, so Status and Relationship remain optional contributed criteria instead of Campaigns dependencies. Saved criteria owned by an unavailable optional module are preserved and fail closed rather than silently widening the audience. Legacy `contact_status` programs are backfilled/readable through the same contract.

Messaging remains authoritative for consent, suppression, destination/runtime/provider eligibility, reusable message copy, and explicit missing-token fallback behavior.

See `docs/modules/campaigns/annual-touch-dates.md`.


New Campaign enrollment creates a compact CampaignEnrollment wrapper, starts the selected immutable MessageChainVersion through Messaging, and stores `campaign_enrollments.message_chain_enrollment_id`. Explicit cancellation, Campaign deactivation, and enrollment pause/resume all delegate to Messaging-owned MessageChainEnrollment lifecycle actions. Campaign workspace/contact visibility and automation result metadata read progression/lifecycle facts from the linked MessageChainEnrollment. The old Campaign step scheduler, terminal-result progression listener, and duplicate CampaignEnrollment runtime columns have been removed.

### CRM Campaign creation

    The CRM now has a first-class purpose-guided Create Campaign entry point. Create mode reuses the shared Campaign Builder shell rather than introducing a parallel wizard or runtime. A new Campaign starts `inactive` with manual entry, records only generic authoring intent, creates one canonical Messaging-owned reusable MessageTemplate/MessageTemplateVersion for the first Email or SMS message, and publishes one direct immutable MessageChainVersion with an immediate first step. Email creation uses the universal Messaging Media authoring contract.

    CRM-created Campaigns do not create new `campaign_steps` or `campaign_step_variants` rows. Those tables remain only the temporary legacy/preset authoring projection. The existing Start, Schedule, Messages, and Review editors operate on the selected direct MessageChain after creation. The Campaign index likewise reads message-step count from the published MessageChain first and falls back to the legacy projection only for records that still require it.

    Creation deliberately defaults to manual entry and redirects to the existing Start editor so audience/eligibility choices remain explicit before activation. Technical identity such as `marketing` purpose, `campaign` scope, `campaign_step_due`, and the `marketing` queue is server-owned rather than browser-authored.

Messaging also owns dependency-aware MessageChain execution, pending-message skipping, bounded bulk delivery, and provider submission pacing.

## Responsibility

Campaigns represents outbound marketing/conversion/nurture programs.

Campaigns are not general workflows.

Use:

```text
FlowRoutes
    business automation and cross-module control flow

Tasks
    manual human work

Messaging MessageChains
    reusable message sequence, timing, variants, dependencies, and progression

Broadcasts
    one-time or batch sends
```

Campaigns owns:

- Campaign identity and business meaning;
- Campaign activation/deactivation/archive state;
- Campaign enrollment intent;
- Campaign-specific audience/segment behavior;
- Campaign-specific source/origin context;
- Campaign-specific reporting and outcome interpretation;
- Campaign preset sync for Campaign definitions and selected MessageChain identity;
- Campaign CRM presentation.

Campaigns does not own:

- reusable subject/body/SMS copy;
- immutable template versions;
- chain steps or variants after cutover;
- generic chain progression;
- scheduled-message delivery infrastructure;
- Broadcasts;
- FlowRoutes;
- Webinar registrations.

Campaigns may depend on Core and Messaging.

## Campaign family / priority arbitration

Campaigns owns optional same-lane exclusivity for Campaign enrollment. This is a Campaign business rule, not generic MessageChain progression.

```text
family_key = null
    Campaign is independent; existing enrollment behavior is unchanged

family_key = same value
    Campaigns are mutually exclusive for one Contact while an enrollment is active/paused

priority = higher integer
    candidate may supersede lower-priority open family enrollments

priority = equal or lower
    existing open family enrollment remains incumbent and the candidate is blocked
```

Supersession uses `CancelCampaignEnrollmentAction`, which delegates to Messaging-owned MessageChainEnrollment cancellation and pending-message skipping. The candidate enrollment and any supersession cancellations occur in one database transaction so a failed new start does not strand the prior journey as cancelled.

Family arbitration is opt-in. Existing Campaigns with no `family_key` do not become mutually exclusive. Family keys are generic stable business-lane identifiers and must not encode Mortgage, Webinar, or other producer-module branching into Campaign runtime code.

Compact arbitration provenance may be retained in CampaignEnrollment metadata; generic chain progression and delivery history remain Messaging-owned.

## Runtime variant availability

Campaign variant availability is provider-aware. A variant is currently available only when Messaging exposes its channel for the Campaign surface, its purpose/scope is enabled, and the channel's provider is enabled.

An unavailable sibling variant is normal runtime state, not a broken Messaging definition. Strategies such as `first_available` and `dependency_aware` may intentionally fall back to another active variant, and dependency state `unavailable` explicitly represents that condition. Setup validation therefore skips definition/payload findings for an unavailable variant when another active variant can run.

If an active Campaign step has no currently available variants at all, setup validation emits one step-level warning. This preserves visibility that the step cannot deliver while avoiding misleading per-variant `missing payload` warnings for intentionally unavailable providers.

## Campaign lifecycle authority

`Campaign.status` is the sole top-level Campaign lifecycle authority.

Supported states:

```text
active
inactive
archived
```

Only active Campaigns accept new enrollments.

Inactive and archived Campaigns remain referenceable by stable key so FlowRoutes and other configuration do not become structurally invalid merely because execution is paused.

Enrollment failure reasons remain distinct:

```text
campaign_missing
campaign_inactive
campaign_family_blocked
```

Routine preset sync may update non-customized Campaign definitions but must not silently overwrite existing operational status.

Use one dedicated deactivation path for CRM and CLI.

Deactivation should:

```text
set active Campaign -> inactive
block new Campaign enrollments
cancel active Campaign-linked MessageChainEnrollments
skip pending ScheduledMessages created from those enrollments
leave sending/sent/failed/previously skipped messages unchanged
leave referring FlowRoutes configured and dormant
preserve Campaign and delivery history
```

Reactivation permits future enrollments only.

It does not resume cancelled chain enrollments or requeue skipped deliveries. When the selected Campaign-generated MessageChain was inactivated with the Campaign, activation restores that chain to active before future enrollment. A reusable/shared MessageChain is not forcibly deactivated merely because one Campaign is turned off.

Enrollment pause/resume is separate from Campaign deactivation:

```text
pause enrollment
    MessageChainEnrollment -> paused
    pending ScheduledMessages for that enrollment -> skipped
    sending/sent/failed/already-skipped messages remain unchanged

resume enrollment
    MessageChainEnrollment -> active
    future unmaterialized timing preserves the remaining delay across the pause
    a previously materialized/skipped current wave is re-evaluated immediately so progression can continue
```

A message already claimed as `sending` cannot be recalled by pause; pause prevents pending work and future progression.

Archived Campaigns require an explicit recovery decision.

## Target schema relationship

### `campaigns`

Target Campaign fields should remain business-focused:

```text
id
key
name
description nullable
message_chain_id
family_key nullable
priority
status
source nullable
source_version nullable
is_customized
customized_at nullable
timestamps
```

Small purpose/scope summaries may remain only when they have an independent Campaign classification/reporting use. Do not keep them merely because old Campaign steps repeated them.

No generic `meta` column is planned for new Campaign definitions.

### `campaign_enrollments`

A Campaign enrollment becomes a thin Campaign-specific wrapper around generic Messaging progression.

Current compact fields:

```text
id
contact_id
campaign_id nullable
message_chain_enrollment_id nullable during the atomic start bridge
source_type nullable
source_id nullable
campaign_key
start_context nullable
dedupe_key nullable
started_at nullable
meta nullable
timestamps
```

`campaign_key` is retained as stable Campaign business identity even if the optional Campaign FK is later nulled by deletion/archive maintenance. `start_context` is retained as Campaign-owned enrollment input/provenance so generic MessageChain timing/conditions can resolve start-time values without importing producer modules. `meta` may retain compact Campaign-specific provenance such as who/what paused or cancelled an enrollment; it must not become a second progression ledger.

Possible Campaign-specific outcome fields should be added only when Reporting or Campaign product behavior needs them.

Do not duplicate from `message_chain_enrollments`:

```text
status
current step
pause/resume/cancel/complete timestamps
exit conditions
exit reason
next action time
scheduled-message IDs
```

The generic MessageChainEnrollment owns those facts.

## Campaign and MessageChain relationship

One Campaign selects one reusable MessageChain for new enrollments.

```text
Campaign.message_chain_id
    stable chain identity

new Campaign enrollment
    pins MessageChain.current_version_id
    creates MessageChainEnrollment
    records CampaignEnrollment.message_chain_enrollment_id
```

Editing the selected chain creates a new MessageChainVersion.

Existing Campaign enrollments remain on the chain version they started with.

Changing `Campaign.message_chain_id` affects future enrollments only.

## Campaign presets

Campaign preset config should define:

```text
Campaign key
name
description
status installation default
selected MessageChain key
Campaign-specific classification/segment defaults when needed
optional family key + integer priority for same-lane exclusivity
source version
```

Campaign presets should not define message copy.

After chain cutover, Campaign presets should not own a second nested step/variant schema.

The transition currently converts the compact Campaign step/variant config into MessageChain, MessageChainVersion, MessageChainStep, and MessageChainStepVariant records during sync. The generated stable chain key is `campaign.{campaign_key}` and published versions are content-addressed/idempotent: an unchanged sequence reuses the current immutable version; a changed sequence publishes a new version.

This compatibility conversion is temporary. Runtime/read ownership has already moved to Messaging. The target authoring config shape is a Campaign definition that selects a Messaging-owned `message_chain_key`; Campaign config will stop owning nested message sequence structure after the Builder/authoring migration.

That conversion is a temporary compatibility authoring path, not a reason to retain Campaign-owned step tables permanently.

Target authoring direction:

```text
message chain definitions
    Messaging-owned seed/config domain

campaign definitions
    reference message_chain_key
```

Do not put subject/body/message payload overrides into Campaign config.

Do not use physical config paths as runtime identity.

## Message-chain step behavior used by Campaigns

The following existing Campaign concepts remain valid, but move into generic Messaging chain definitions.

### Business moment

A chain step represents the business moment.

Examples:

```text
initial follow-up
question prompt
one-week check-in
final re-engagement
```

### Channel variant

A chain-step variant represents a channel-specific delivery option.

Examples:

```text
email
sms
```

### Channel strategy

Supported concepts remain:

```text
first_available
send_all_eligible
dependency_aware
```

`first_available`:

```text
schedule one eligible variant using configured priority
```

`send_all_eligible`:

```text
schedule every independently eligible variant
```

`dependency_aware`:

```text
evaluate explicit sibling-variant state dependencies
```

Do not infer dependency from timing, broad channel matching, purpose/scope, message type, or dispatch key.

### Dependency identity

Dependency checks must use immutable chain identity:

```text
same MessageChainEnrollment
same MessageChainStep
specific sibling variant key
allowed states
```

Supported state concepts may include:

```text
scheduled
pending
sent
skipped
failed
terminal
unavailable
```

The chain runner, not Campaigns, owns generic sibling-variant dependency evaluation.

Campaigns may interpret final campaign outcomes after the chain completes or exits.

### Terminal delivery policy

A failed ScheduledMessage is a terminal accounted-for result after Messaging exhausts that delivery's safe retry policy. Messaging's MessageChain runner consumes ScheduledMessage terminal state and owns the decision to wait, advance, complete, or exit. Campaigns no longer listens to ScheduledMessage terminal events to advance its own step engine.

Generic chain progression determines whether the step:

```text
waits for another pending/sending sibling
advances after all required variants are terminal
exits according to chain policy
```

Do not grow CampaignEnrollment metadata arrays containing per-step scheduling attempts or failure histories.

Scheduled messages and delivery attempts already provide delivery history.

## Enrollment start

Campaign enrollment should require:

```text
active Campaign
valid Contact
selected active MessageChain
published/current MessageChainVersion
dedupe identity
```

The Campaign action creates CampaignEnrollment and MessageChainEnrollment atomically. The wrapper is created first so it can be the MessageChain context, and Messaging progression dispatch uses `afterCommit()` so the processor cannot observe an uncommitted Campaign wrapper/link.

The chain enrollment identity is:

```text
recipient = Contact
context = CampaignEnrollment
origin = Campaign
surface = campaigns
```

When another module or FlowRoutes starts a Campaign, `campaign_enrollments.source_type/source_id` may preserve neutral business source context. That source morph is intentionally distinct from MessageChain `origin`: the producer explains why the Campaign was started, while `origin = Campaign` keeps delivery attribution tied to the Campaign that owns the journey.

This preserves a durable attribution path from ScheduledMessage -> MessageChainEnrollment -> CampaignEnrollment -> Campaign while keeping the producer/source morph on CampaignEnrollment. A Campaign execution-context provider exposes Campaign-owned `start_context`/payload values to generic MessageChain timing/conditions without making Campaigns depend on the producer module. Canonical Contact/Campaign/CampaignEnrollment model values override caller-provided keys.

Campaigns should not import the source module merely to interpret that morph.

## Cancellation and exit

Campaign cancellation remains Campaign-owned business intent.

Implementation target:

```text
CancelCampaignEnrollmentAction
    resolves linked MessageChainEnrollment
    cancels it through Messaging public action
    skips eligible pending ScheduledMessages
    records Campaign-specific cancellation reason only when independently needed
```

Chain exit conditions remain on MessageChainVersion. `campaign_enrollments` no longer stores or evaluates generic exit conditions. The legacy public enrollment/FlowRoute field may remain temporarily at caller-definition boundaries so existing empty definitions still parse, but only null/empty values are accepted; non-empty per-enrollment exit conditions fail explicitly and must move to the selected MessageChainVersion. Do not reintroduce per-enrollment generic exit behavior into Campaigns.

Campaign-specific exit behavior that is not reusable generic chain behavior should be expressed through a Campaign-owned cancellation/exit action invoked by the relevant producer or event seam.

Do not turn Messaging into a general Campaign segment engine.

## FlowRoutes boundary

FlowRoutes may:

```text
start Campaign
stop Campaign
```

FlowRoutes must call Campaign-owned public actions.

FlowRoutes does not create CampaignEnrollment or MessageChainEnrollment rows directly.

Campaigns owns the transaction that creates the Campaign wrapper and starts the selected chain.

FlowRoutes records the resulting CampaignEnrollment or MessageChainEnrollment identity in FlowRoutes-owned progress state.

Campaigns does not add FlowRoutes-specific columns merely for provenance symmetry.

## Webinars boundary

Webinars may trigger Campaign enrollment through:

```text
neutral automation events
FlowRoutes
a future explicit Campaign public action integration
```

Webinars must not create CampaignEnrollment rows directly.

Webinar transactional confirmations/reminders/post-event messages should use Webinar-selected MessageChains directly rather than pretending every sequence is a Campaign.

Campaigns remain appropriate for marketing nurture where Campaign identity/reporting matters.

## Messaging boundary

Campaigns uses Messaging public actions for:

```text
start chain
cancel chain enrollment
pause/resume chain enrollment
skip pending messages
read chain/enrollment status
```

Campaigns does not create or mutate ScheduledMessage internals.

Campaign UI may link to Messaging chain/template authoring but should not duplicate the complete Messaging editor.

## Config and token contracts

Campaign config contracts should validate:

```text
stable Campaign key
supported Campaign status
existing selected message_chain_key
no reusable payload/copy fields
no physical config-path identity
source version shape
```

Campaign token providers expose real Campaign and CampaignEnrollment business fields.

They do not make arbitrary metadata, old start-context payloads, or chain internals globally authorable.

Producer-specific fields become authorable only through an explicit compatible producer context.

## Preset sync and customization

Normal sync:

```text
missing non-customized Campaign
    create

existing non-customized Campaign
    update definition fields and selected chain reference
    preserve operational status unless the lifecycle contract says otherwise

existing customized Campaign
    preserve

stale non-customized config-owned Campaign
    remove or deactivate according to the final preset-removal contract

manual Campaign
    preserve
```

Campaign sync intentionally should not gain a destructive force mode merely for symmetry.

A future destructive reset must have explicit operator wording, warnings, and tests.

MessageChain and MessageTemplate force/customization behavior remains Messaging-owned.

## Setup validation

`CampaignsSetupValidationContributor` should validate Campaign-owned semantics and use Messaging public resolution seams for chain references.

At minimum:

```text
Campaign key is stable
status is supported
selected MessageChain exists
selected MessageChain has a current published version
Campaign config contains no reusable copy/payload
active Campaign can create a valid chain enrollment
FlowRoute Campaign references resolve
no duplicate active Campaign identity
```

Campaigns should not inspect private Messaging table internals from a global validator.

Hard errors represent impossible intended execution.

Warnings represent dormant or safely inactive Campaigns.

## CRM presentation

Campaign UI should lead with business meaning:

```text
Campaign name
who it is for
what starts it
message count derived from selected chain
channels derived from selected chain
human-readable timing summary derived from chain
active/inactive/archived state
```

Avoid primary labels such as:

```text
dispatch key
payload class
purpose
scope
config path
message_type
variant strategy
```

Those may remain in diagnostics.

Recommended Campaign editing flow:

```text
1. name and describe the Campaign
2. choose or create a MessageChain
3. define audience/enrollment behavior
4. review chain summary
5. activate
```

Detailed template copy and chain-step editing belong to Messaging authoring surfaces, opened in context from the Campaign page.

## Human-readable timing

Campaign timing is derived from the selected immutable MessageChainVersion.

Good:

```text
Sends 7 days after Campaign start.
Sends 2 days after the previous step.
Sends when the prior email reaches a terminal state.
```

Avoid storing summary text.

Generate it from:

```text
chain step timing
anchor
offset
strategy/dependencies
```

## Reporting

Campaigns owns Campaign-specific reporting dimensions such as:

```text
Campaign identity
enrollment source
enrollment count
chain completion/exit by Campaign
Campaign-attributed sent/skipped/failed outcomes
conversion/outcome measures introduced later
```

Messaging remains the source of delivery execution facts.

Campaigns should query through stable relationships/read services rather than copy delivery history into CampaignEnrollment metadata.

## Post-cutover boundary

The Campaign runtime cutover is complete:

- Campaign selects a Messaging-owned MessageChain;
- new enrollment starts a version-pinned MessageChainEnrollment;
- CampaignEnrollment is a compact business/provenance wrapper;
- Messaging owns progression, lifecycle timestamps, exit state, and ScheduledMessage terminal handling;
- Campaign cancellation/deactivation/pause/resume delegate through Messaging public actions;
- workspace/contact/automation reads derive runtime state from the linked chain enrollment;
- Project State Campaigns section exports the compact wrapper using the current contract in `config/project_state/campaigns.php`, while Messaging section v2 owns the chain enrollment and deferred Campaign context/origin references;
- the legacy Campaign scheduler/listener classes and duplicate enrollment progression columns are removed.

`campaign_steps` and `campaign_step_variants` remain only as the temporary authoring projection consumed by the current Campaign message editor and preset bridge. They must not regain runtime meaning. A later Builder/authoring migration should make Messaging MessageChain definitions the direct authoring source, then remove these Campaign authoring tables.

The dev-only fake-clock Campaign Simulator now exercises the real MessageChain/ScheduledMessage runtime locally through the shared testing-tool guard/runtime scope. Simulator-owned `testing:campaigns` enrollments are excluded from ordinary due scanning and Messaging recovery, local delivery is intercepted by `DevMessageSink`, and reset removes only simulator-owned runtime records. The reusable pattern is documented in `docs/testing-tools.md`. Inbound reply capture/attribution, stage-driven suppression/orchestration, client campaign definitions, and reporting can proceed as separate workstreams.