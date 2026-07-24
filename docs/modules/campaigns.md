# Campaigns Module

## Config and token contracts

Campaign preset definitions are closed by the registered `campaigns.preset_definition` contract.
Campaign token providers expose real Campaign and CampaignEnrollment columns and producer context;
they do not make `meta` or arbitrary start context globally selectable. Start-context fields become
authorable only when the enrolling producer declares a compatible payload contract.

Campaign step variants use semantic identity—campaign key, step number, variant key, channel,
purpose, and scope. `source_config_path` remains migration/legacy fallback metadata, not the primary
identity. The Slam Dunk golden runtime fixture proves variant resolution and enrollment through
this path.

## Campaign lifecycle authority

`Campaign.status` is the sole top-level Campaign lifecycle authority.

Supported states are:

```text
active
inactive
archived
```

Only `active` Campaigns may accept new enrollments or appear in active Campaign authoring choices. `inactive` and `archived` Campaign definitions remain installed and referenceable by stable key so FlowRoutes can stay configured while Campaign execution is paused.

An enrollment attempt against an existing non-active Campaign must stop safely with the explicit reason `campaign_inactive`. A missing Campaign uses the distinct reason `campaign_missing`.

Campaign Steps and Campaign Step Variants retain their own `is_active` fields because those are independent child-level availability controls. Do not restore a Campaign-level `is_active` field in config, Eloquent, tokens, or persistence.

Campaign preset `status` is an installation default. Routine preset sync updates non-customized Campaign definitions, Steps, and Variants, but it does not overwrite the status of an existing Campaign. Existing Campaign status is operational database state.

Use the dedicated Campaign deactivation workflow to stop a Campaign. Deactivation:

```text
sets an active Campaign to inactive
blocks new enrollments through the Campaign status gate
cancels active and paused enrollments with campaign_deactivated provenance
skips pending ScheduledMessages carrying that Campaign identity
leaves sending, sent, failed, and previously skipped messages unchanged
leaves referring FlowRoutes configured and dormant
preserves Campaign, enrollment, and message history
```

The deactivation action is the single implementation used by the CRM and `php artisan campaigns:deactivate {campaign_key}`. Enrollment creation locks the Campaign row while confirming active status so deactivation and enrollment cannot cross without one operation observing the other's result.

Reactivation permits future enrollments only. It never resumes cancelled enrollments or requeues skipped messages. Archived Campaigns require an explicit archival recovery decision and cannot be reactivated from the normal CRM lifecycle control.

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Campaigns is optional.

Campaigns owns enrolled, multi-step campaign journeys.

Campaigns are outbound conversion, nurture, and re-engagement message journeys.

Campaigns are not general workflows.

Campaigns should not model every business process, task dependency, status transition, or automation decision.

Use FlowRoutes for automation/control flow.

Use Tasks for manual human actions/dependencies.

Use Messaging for delivery.

Use Broadcasts for one-time or batch recipient sends.

Campaigns owns:

- campaigns
- campaign steps
- campaign enrollments
- campaign enrollment lifecycle
- campaign progression
- campaign cancellation/exit behavior
- campaign preset sync
- campaign step scheduling behavior
- campaign listeners
- campaign-specific metadata
- campaign conditions/segments later

Campaigns is authoritative for Campaign-owned message behavior:

```text
step/variant timing
step/variant conditions
variant strategy
dependency rules
step order and progression
Campaign-specific skip behavior
```

Reusable Messaging templates referenced by Campaign steps/variants own reusable copy and delivery-template metadata only. They must not carry competing Campaign timing, schedule, conditions, sequencing, or dependency behavior.

Campaign runtime resolves the selected `CampaignStep` / `CampaignStepVariant` behavior first, selects the reusable Messaging template, and uses `ResolvedMessageDispatchBuilder` to assemble a `ResolvedMessageDispatch` with an exact `send_at`. When the variant is the concrete behavior owner, the resulting scheduled message preserves the `CampaignStepVariant` as polymorphic behavior provenance.

Campaigns does not own:

- broadcasts
- broadcast recipients
- one-time/batch sends
- outbound delivery infrastructure
- scheduled message infrastructure
- webinar registrations
- FlowRoutes
- Workflow status/profile state

Broadcasts belong to the Broadcasts module.

Messaging owns scheduled/outbound message infrastructure.

Campaigns may depend on:

- Core
- Messaging

Campaigns may schedule messages through Messaging public actions.

Campaign presets define journeys: campaign identity, step order, step timing, channel strategy, and message template references through variants.

## Compact Campaign preset authoring

Campaign preset config has one canonical author-facing shape. Runtime/database rows remain explicit, but config authors do not repeat values that are already determined by structure or parent identity.

```text
definitions map key -> Campaign key
steps list position -> step_number
variants map key -> CampaignStepVariant key
variants map order -> sort_order values 10, 20, 30, ...
Campaign purpose/scope/source_version -> child defaults
canonical campaign_step_due dispatch -> every step and variant
Campaign variant_strategy -> step default
variant channels -> Campaign and step channel summaries
```

Campaign dispatch is fixed at `campaign_step_due`; `status` defaults to `active` for initial installation; and `variant_strategy` defaults to `first_available`.

Do not author the following derived fields:

```text
Campaign key, Campaign channel, or Campaign dispatch_key
step_number
step dispatch_key, channel, purpose, or scope
variant key, sort_order, order, dispatch_key, purpose, or scope
```

A variant must declare its channel. Include child `is_active` only when setting it to `false`. Include step or variant `source_version` only when overriding the Campaign version. Omit empty `criteria`, `dependency_rules`, and `meta` objects.

Legacy verbose fields and dependency aliases are rejected rather than normalized through permanent compatibility branches. Preset sync expands the compact DTO into the existing explicit Campaign, CampaignStep, and CampaignStepVariant persistence model.

## Campaign steps and channel variants

The current campaign/channel architecture is:

```text
Campaign enrollment = lifecycle
Campaign step = business moment
Campaign step variant = channel-specific delivery option
```

This allows one campaign enrollment to coordinate email and SMS without creating separate competing campaigns.

Example:

```text
Campaign: Webinar Attended Nurture
  Step group 1: Initial follow-up
    Variant: email
    Variant: sms
  Step group 2: Question prompt
    Variant: email
    Variant: sms
```

Campaign step variants should reference Messaging-owned template presets or assignments.

They should not embed reusable subject/body/message copy.

### Channel strategies

Each Campaign defines how channel variants interact; a step may override that strategy when needed.

Initial strategies:

```text
first_available
send_all_eligible
dependency_aware
```

`first_available` means send one eligible variant based on configured channel priority.

Use it when email and SMS are alternatives, such as the same message at the same interval.

`send_all_eligible` means schedule every eligible variant.

Use it when email and SMS are intentionally independent messages.

`dependency_aware` means evaluate explicit sibling-variant dependency rules before scheduling a variant.

Use it when one channel's message depends on another sibling variant reaching a clear state, such as an SMS that should only schedule after the email variant for the same step has been scheduled or sent.

Dependency-aware behavior should not be inferred from timing alone, broad channel matching, or message type guesses.

Different unrelated email/SMS messages at the same delay must explicitly choose whether they are independent sends or alternatives.


### Variant dependency rules

Dependency-aware variants should use explicit dependency rules.

The recommended dependency shape is:

```php
'dependency_rules' => [
    'requires_variant_states' => [
        'email' => ['scheduled', 'sent'],
    ],
],
```

Meaning:

```text
Before scheduling this variant, the campaign runtime must find the sibling
variant named email for the same campaign enrollment and same campaign step
in one of the allowed states: scheduled or sent.
```

Supported dependency states should be explicit:

```text
scheduled
pending
sent
skipped
failed
terminal
unavailable
```

`terminal` means the sibling variant has reached a final Messaging outcome such as sent, skipped, or failed when the runtime intentionally treats those as no-longer-pending for progression/dependency purposes.

### Terminal delivery failure policy

A Messaging-owned `ScheduledMessage` reaches terminal failure only after Messaging has exhausted the delivery behavior represented by that message. Campaigns treats that failed delivery as an accounted-for terminal variant, not as an invisible reason to strand the enrollment.

The Campaign progression policy is:

```text
pending or sending sibling variant exists
    wait on the current Campaign step

every scheduled sibling variant is sent, skipped, or failed
    record the failed-message outcome and policy on CampaignEnrollment.meta
    continue to the next schedulable Campaign step
```

Dependency checks should be scoped to:

```text
same campaign enrollment
same campaign step
same sibling variant key
```

A dependency match from a different enrollment, a different campaign step, a different campaign, or a broad channel/purpose/scope match should not satisfy the dependency.

The runtime may consider both:

```text
sibling variants scheduled in the current scheduling pass
existing ScheduledMessage records for the same enrollment + campaign step + variant identity
```

This keeps dependency-aware behavior durable when a variant is scheduled later by retry/replay/admin action instead of only during the original PHP scheduling loop.

Avoid ambiguous dependency rules such as:

```text
requires email
requires campaign_step_due
requires marketing:webinar_nurture
requires any message on this step
```

Those are too broad because same-channel variants and same-step variants may share channel, purpose, scope, dispatch key, or derived message type.

If a dependency-aware variant is not scheduled, Campaigns should record compact debug metadata explaining which dependency was missing and which states were accepted.

Messaging definitions define message copy and delivery templates.

Campaign presets must not be the primary home for reusable email/SMS copy.

Campaign presets must not define or override message payloads.

Campaign variant templates are resolved from Messaging by:

    channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key

In the DB-backed target architecture, that context should resolve to a selected `MessageTemplatePresetAssignment`, which then loads the selected `MessageTemplatePreset`.

Messaging may also create `MessageTemplateCatalogEntry` records for campaign step templates so the Messaging template browser can show campaign messages together, such as:

```text
Campaigns -> Webinar Attended Nurture -> Step 1 Email
Campaigns -> Webinar Attended Nurture -> Step 2 Email
```

Those catalog entries are for browsing/copy editing only. Campaigns still own the campaign, step order, timing, progression, and enrollment lifecycle.

The Campaign Message Templates CRM surface is the current Campaign-side setup surface for selecting which Messaging template is active for each campaign step. It may link to Message Templates for copy editing, but it should not duplicate reusable message copy editing inside Campaigns.

Campaign runtime should use variant-specific DB assignments first. Variant-specific config may seed/sync available templates, but step-level campaign template fallback is not supported.

Campaign runtime should also keep variant progression/dependency checks tied to first-class variant identity. Do not collapse variants by channel, purpose, scope, dispatch key, or derived message type.


The matching Messaging config path is:

    messaging.{channel}.definitions.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}

Campaign preset steps should define the business moment and strategy.

Campaign preset variants reference the message template context through their variant map key, explicit `channel`, and inherited Campaign `purpose` and `scope`, plus the fixed `campaign_step_due` dispatch key.

Do not use `meta.message` as the canonical CampaignStep or CampaignStepVariant message reference.

Persisted `campaign_step_variants.channel`, `purpose`, and `scope` remain explicit runtime fields. Preset sync derives and writes them from the compact definition. Persisted step channel/purpose/scope remain summary/debug fields and are derived the same way.

The Campaign key comes from the definitions map key. Step number comes from list position. Variant key comes from the variants map key.

Do not require authors to invent per-step `message_type` names for campaign journey steps.

Messaging may derive runtime `message_type` values such as:

    webinar_attended_nurture_step_1

Those derived values are runtime/debug identifiers, not author-facing lookup keys.

Campaign step timing may be author-friendly:

    minutes
    hours
    days

Before calling Messaging runtime actions, Campaigns should normalize its author-friendly timing into an exact resolved send time for `ResolvedMessageDispatch`.

Example:

    criteria.timing.days = 3

resolves from the Campaign-owned trigger/step context to:

    send_at = exact timestamp

The reusable Messaging template does not own that delay and must not provide a competing fallback schedule.

Campaign scheduling should provide stable module-owned occurrence identity for the enrollment + step + variant occurrence. Retry/dedupe identity must not rely on `send_at` alone; the same logical Campaign occurrence should keep the same occurrence key even if its timestamp is recalculated.


If a referenced Messaging template is missing, fail loudly because the config is broken.


## Campaign setup validation ownership

Campaigns contributes Campaign-owned checks through `CampaignsSetupValidationContributor` to the shared app-level setup validation manager.

Campaign validation uses resolved selected Campaign definitions, Campaign step/variant definition DTOs, supported variant strategies, active DB-owned Campaign runtime state, and Messaging-owned public reference/resolution seams as executable truth.

Shared preset-composition validation owns package/group/definition structure, including missing selected groups and duplicate contributed group/definition keys.

At minimum, Campaigns validates:

```text
definition map keys normalize to stable Campaign identity
steps are a non-empty sequential list whose positions derive unique step numbers
variants are non-empty keyed maps whose keys normalize uniquely within a step
variant_strategy is supported and inherited deliberately
variant dependency rules reference real sibling variant keys
variant dependency states are supported
variant channel/purpose/scope/template context is resolvable through Messaging-owned seams
Campaign presets do not own reusable subject/body/message payload copy
active DB/runtime Campaign state remains safe and coherent
```

Campaigns owns `enroll_campaign` and `cancel_campaign` Point-definition parsing and Campaign-reference validation through its `AutomationPointDefinitionContributor`. FlowRoutes owns the Route envelope, capability/handler availability, route graph, progression, and runtime consistency; it should not re-implement Campaign existence checks in a central FlowRoutes validator.

A Campaign configuration that cannot execute safely is a hard error. A dormant but safe unused Campaign or variant may be a warning.

Campaign validation must not turn Campaigns into the owner of Messaging template internals. Cross-module checks should use public contracts/services/registries or shared setup-validation context.

If a referenced Messaging template exists but has no usable payload, skip scheduling safely with debug metadata instead of crashing runtime delivery.

Preset sync is authoritative for non-customized Campaign definitions.

If a client preset replaces a default campaign with fewer steps, stale non-customized DB steps should be removed rather than inherited accidentally.


Campaigns should not directly depend on Workflow status, Webinar outcomes, FlowRoute progress, Mortgage stages, or Broadcast behavior unless those relationships are introduced through explicit public APIs/events/resolvers.

Runtime Campaign behavior should read DB-owned Campaign and CampaignStep definitions.

Preset config may create/update DB-owned Campaign definitions, but runtime Campaign execution should not depend directly on config definitions.

Current tables/models:

    campaigns
    campaign_steps
    campaign_step_variants
    campaign_enrollments

Current models:

    Campaign
    CampaignStep
    CampaignStepVariant
    CampaignEnrollment

`CampaignEnrollment` is lifecycle state, not delivery identity.

It should not own first-class:

```text
channel
purpose
scope
```

Those belong to the delivery/template layers:

```text
Campaign
    default/classification context

CampaignStep
    step-level fallback/summary context

CampaignStepVariant
    authoritative channel/purpose/scope delivery-template context

ScheduledMessage
    actual delivery instance

CampaignEnrollment
    one contact moving through the campaign lifecycle
```

One campaign step may schedule multiple variants, so CampaignEnrollment should not carry a single current variant pointer merely to describe delivery.

Use generic lifecycle fields such as:

    start_context
    exit_conditions
    exited_at
    exit_reason
    meta

Example start context:

    {
      "workflow": {
        "contact_status_id": 3
      }
    }

Example exit condition:

    {
      "stop_when_contact_leaves_workflow_status": true
    }

Future condition checks should move behind a resolver such as:

    CampaignExitConditionResolver

Good:

    DispatchMessageAction
    ScheduleMessageAction
    SkipScheduledMessagesAction

Avoid direct ScheduledMessage creation from Campaigns unless explicitly documented.

Good:

    Campaigns owns CancelCampaignEnrollmentAction
    FlowRoutes calls CancelCampaignEnrollmentAction

Bad:

    FlowRoutes mutates CampaignEnrollment internals directly
    Webinars creates CampaignEnrollment records directly

Campaign enrollments may reference Messaging-owned `scheduled_messages` through `last_scheduled_message_id`.

That reference is acceptable because Campaigns depends on Messaging.

Campaigns still should schedule/skip delivery through Messaging public actions instead of directly mutating scheduled message lifecycle internals.

`campaign_enrollments.source_type` / `source_id` may point to another module as enrollment context.

That does not make Campaigns depend on the source module.

Campaigns should treat source morphs as context unless an explicit public integration is introduced.



## FlowRoutes-created campaign enrollment correlation

Campaigns owns campaign enrollment lifecycle, progression, cancellation, exit
behavior, and campaign scheduling.

When a FlowRoutes automation action creates a CampaignEnrollment through the
Campaigns-owned action seam, FlowRoutes records the created artifact on its own
ContactFlowRouteProgressItem:

```text
created_subject_type
created_subject_id
correlation_key = campaign_enrollment.id
correlation_type = campaign_enrollment
correlation.campaign_enrollment_id
correlation.campaign_key
```

CampaignEnrollment keeps its normal source morph, start context, and opaque
caller metadata. It must not add first-class FlowRoutes foreign keys,
relationships, or imports merely to duplicate provenance already owned by
FlowRoutes.

Caller-supplied metadata may be copied to the first ScheduledMessage for compact
diagnostics. Campaigns treats that metadata as opaque and must not parse or
reconstruct FlowRoutes runtime state from CampaignEnrollment columns.

Campaigns should not import FlowRoutes runtime internals. FlowRoutes should not
mutate CampaignEnrollment internals directly.


## Campaign preset sync force behavior

Campaign preset sync intentionally does not currently expose a force mode.

Normal sync behavior is authoritative for non-customized Campaign definitions, CampaignSteps, and CampaignStepVariants. It may create/update non-customized records and remove stale non-customized steps/variants when a preset definition changes.

Customized Campaigns, customized CampaignSteps, and customized CampaignStepVariants are preserved and skipped.

Do not treat `presets:sync` or `campaigns:sync-presets` as destructive Campaign reset tools. If a future production-support workflow needs a Campaign force mode, design that deliberately with clear command naming, operator warnings, and tests for destructive overwrite semantics.

Current decision:

```text
No --force-campaigns option.
No implicit destructive Campaign reset during global preset sync.
Customized Campaign structures are preserved.
```

## Routes campaign usage

FlowRoutes may enroll or cancel Campaigns through Campaign-owned public actions.

Client/operator Route UI should use Campaign terminology consistently:

```text
Start Campaign: Webinar Attended Nurture.
Stop Campaign: Webinar Missed Nurture.
```

Do not relabel Campaigns as `follow-up sequences` inside Routes.

Do not expose `CampaignEnrollment` internals or Campaign step machinery as primary Route labels.

The normal Route editor may hide `Stop Campaign` unless the current Route already contains a `Start Campaign` Point. That is a contextual authoring guardrail, not a claim that Campaign cancellation is technically impossible outside that exact sequence.

Campaign runtime ownership remains unchanged: FlowRoutes calls Campaign-owned public actions; Campaigns owns enrollment lifecycle, progression, cancellation, and delivery behavior.

## CRM presentation and labels

Campaign UI should describe the business journey before technical machinery.

Use client/operator-facing language such as:

```text
Message steps
Step 1
Step 2
Available channels
When it sends
```

Avoid making these primary labels:

```text
delivery options
variant strategy
dispatch key
message_type
purpose
scope
config path
```

`Delivery options` is technically accurate for channel variants, but it is not intuitive enough as a primary campaign label. Most operators understand `message steps` more quickly.

A campaign card/list item should make the campaign shape obvious:

```text
5 message steps
Email + SMS available
Starts after webinar attendance
```

Each campaign step should be collapsible. The collapsed state should show only the most useful information:

```text
step number
title/business moment
available channel badges
human-readable timing summary
selected template/readiness state
```

Technical specs should live behind details/debug affordances.

Dropdown labels should avoid repeated machine context.

Bad:

```text
Step 1 Email — Webinar Attended Nurture — Step 1 Email
```

Better:

```text
Email follow-up
SMS follow-up
Attended thank-you email
Missed webinar replay email
```

Raw IDs, derived message types, dispatch keys, and config paths should remain available for diagnostics but should not be the primary operator view.

## Human-readable campaign timing

Campaign timing should be shown in business language.

Good examples:

```text
Sends 10 days after the webinar.
Sends 2 weeks after the previous message.
Sends 2 hours after attendance.
Sends immediately when this campaign starts.
```

Avoid exposing raw timing as the main label:

```text
Delay 10 minutes
criteria.timing.days = 3
schedule.type = delay
```

Campaigns may store canonical timing in `criteria.timing` and normalize to Messaging schedule definitions at runtime. UI should use a schedule summary service/helper that understands the business anchor.

Likely summary inputs:

```text
campaign start context
step number
previous step/variant context
criteria.timing
variant strategy
source event or route point, when available
```

Do not persist summary text unless a concrete reporting/audit reason appears. Prefer deriving it from the canonical step/variant timing definition.