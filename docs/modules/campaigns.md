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

Campaign presets define journeys: campaign identity, step order, timing, channel/purpose/scope, and message template references.

Messaging definitions define message copy and delivery templates.

Campaign presets must not be the primary home for reusable email/SMS copy.

Campaign presets must not define or override message payloads.

Campaign message templates are resolved from Messaging by:

    channel + purpose + scope + campaign_key + step_number

The matching Messaging config path is:

    messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}

Campaign preset steps should reference the message template context only through first-class step fields:

    dispatch_key
    channel
    purpose
    scope

Do not use `meta.message` as the canonical CampaignStep message reference.

`campaign_steps.channel`, `campaign_steps.purpose`, and `campaign_steps.scope` are first-class template-reference fields.

`campaign_steps.meta` may keep non-routing/debug metadata such as:

    type = message

The campaign key and step number come from the Campaign/CampaignStep definition.

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
    campaign_enrollments

Current models:

    Campaign
    CampaignStep
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
