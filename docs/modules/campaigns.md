# Campaigns Module

## Status

Campaigns is optional.

The current `campaign_steps`, `campaign_step_variants`, and Campaign-owned message-progression engine are transitional.

The approved target is:

```text
Campaigns owns Campaign identity, activation, audience/enrollment intent, and reporting.
Messaging owns reusable MessageChains and MessageChainEnrollments.
Campaigns references a MessageChain rather than duplicating a second chain engine.
```

Migrations and models should move before runtime scheduling behavior.

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

It does not resume cancelled chain enrollments or requeue skipped deliveries.

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

Target fields:

```text
id
campaign_id
contact_id
message_chain_enrollment_id
source_type nullable
source_id nullable
dedupe_key nullable
started_at
timestamps
```

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

A non-null Campaign FK makes a repeated `campaign_key` unnecessary.

A chain-enrollment FK makes `current_step`, `current_campaign_step_id`, `last_scheduled_message_id`, `start_context`, `exit_conditions`, and generic CampaignEnrollment metadata unnecessary.

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
source version
```

Campaign presets should not define message copy.

After chain cutover, Campaign presets should not own a second nested step/variant schema.

The transition may initially convert the current compact Campaign step/variant config into MessageChain, MessageChainVersion, MessageChainStep, and MessageChainStepVariant records during sync.

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

A failed ScheduledMessage is a terminal accounted-for result after Messaging exhausts that delivery's safe retry policy.

Generic chain progression should determine whether the step:

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

The Campaign action should create CampaignEnrollment and MessageChainEnrollment atomically.

The chain enrollment should use:

```text
recipient = Contact
context = CampaignEnrollment
origin = CampaignEnrollment
```

When another module or FlowRoutes starts a Campaign, `campaign_enrollments.source_type/source_id` may preserve neutral business source context.

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

Chain exit conditions remain on MessageChainVersion.

CampaignEnrollment does not copy them.

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

## Migration boundary

The next migrations/models batch should:

- add the Campaign-to-MessageChain relationship;
- slim CampaignEnrollment and add its MessageChainEnrollment relationship;
- preserve current Campaign lifecycle fields needed before runtime cutover;
- introduce target models without switching the current scheduler;
- rewrite pre-production create migrations rather than add compatibility alter migrations;
- add schema/model tests;
- leave controller, sync, scheduler, listener, and UI cutover for later batches.

The legacy CampaignStep and CampaignStepVariant models may need to remain temporarily while runtime still depends on them.

Their eventual removal is part of runtime cutover, not a reason to keep duplicate target and legacy progression indefinitely.