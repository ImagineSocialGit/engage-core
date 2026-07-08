# Campaigns Module

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

Each campaign step should explicitly define how channel variants interact.

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
sent
skipped
terminal
```

`terminal` means the sibling variant has reached a final Messaging outcome such as sent, skipped, or failed when the runtime intentionally treats those as no-longer-pending for progression/dependency purposes.

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

    messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}

Campaign preset steps should define the business moment and strategy.

Campaign preset variants should reference the message template context through first-class variant fields:

    key
    dispatch_key
    channel
    purpose
    scope

Do not use `meta.message` as the canonical CampaignStep or CampaignStepVariant message reference.

`campaign_step_variants.channel`, `campaign_step_variants.purpose`, and `campaign_step_variants.scope` are first-class template-reference fields. Step-level channel/purpose/scope fields may remain as summary/debug defaults, but campaign delivery references belong on variants.

`campaign_steps.meta` may keep non-routing/debug metadata such as:

    type = message

The campaign key and step number come from the Campaign/CampaignStep definition. The variant key comes from CampaignStepVariant.

Do not require authors to invent per-step `message_type` names for campaign journey steps.

Messaging may derive runtime `message_type` values such as:

    webinar_attended_nurture_step_1

Those derived values are runtime/debug identifiers, not author-facing lookup keys.

Campaign step timing may be author-friendly:

    minutes
    hours
    days

Before calling Messaging runtime actions, Campaigns should normalize timing into the canonical Messaging schedule shape.

Example:

    criteria.timing.days = 3

normalizes to:

    schedule.type = delay
    schedule.minutes = 4320

If a referenced Messaging template is missing, fail loudly because the config is broken.

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


## Automatic Follow-ups campaign usage

FlowRoutes may enroll or cancel Campaigns through Campaign-owned public actions.

Automatic Follow-ups UI should describe these as follow-up sequence consequences, such as:

```text
Start follow-up sequence: Webinar Attended Nurture.
Stop follow-up sequence: Webinar Missed Nurture.
```

It should not expose CampaignEnrollment internals or campaign step machinery as primary labels.

