# Engage Core Module Boundaries

Engage Core is a modular contact engagement platform.

The goal is to let each client enable only the capabilities they need without forcing every client into CRM, sales, webinar, marketing, internal notifications, automation, or vertical-specific workflows.

This document defines module ownership, dependency direction, and the architectural rules that should guide future implementation.

## Core Rule

Modules may depend on another module’s public API, but should not depend on another module’s private internals.

Good dependencies use:

- actions
- services
- contracts
- registries
- DTOs/data objects
- events
- documented config keys
- intentionally public model relationships

Avoid dependencies on:

- another module’s private table details
- another module’s private status fields
- another module’s internal config structure
- direct creation of another module’s records when a public action/service exists
- hardcoded lifecycle assumptions such as `converted_at`, `crm_status`, `assigned_to`, or module-specific state on `contacts`

The preferred shape is:

    Module A -> Module B public action/service/contract/event

Not:

    Module A -> Module B implementation detail

## Installed Schema vs Enabled Features

Shared platform, core, and reusable capability-module tables may exist in every install.

A table existing does not mean the feature is enabled.

Feature availability is controlled through:

- `config/modules.php`
- module provider registration
- route registration and route middleware
- navigation visibility
- controllers/actions
- views/components
- jobs/listeners
- policies/gates
- service bindings
- module extension points

Do not put module-enabled conditionals inside normal shared migrations.

The database can contain reusable capability tables even when a module is not visible to the current client.

## Module Enabled vs Provider Loaded

There is an important distinction:

- `module_enabled('x')` means the feature module is explicitly enabled for the client.
- Provider loading may include dependency modules needed by explicitly enabled modules.

Example:

- If `inbound_messaging` depends on `messaging`, the Messaging provider may need to load.
- But Messaging UI should not appear unless `messaging` itself is explicitly enabled.

Feature visibility should follow explicit module enablement.

Provider availability may include dependencies.

## Migration Organization

Shared core and reusable capability-module migrations live in:

    database/migrations

Vertical-specific migrations live in explicit paths:

    database/migrations/verticals/mortgage
    database/migrations/verticals/dog-training
    database/migrations/verticals/music

Normal platform setup:

    php artisan migrate

Vertical setup:

    php artisan migrate --path=database/migrations/verticals/mortgage

Vertical migrations should only run when that vertical is explicitly installed.

## Schema Ownership Freeze

Before client rollout, each existing table should have exactly one owning layer.

Current ownership:

| Table | Owner |
| --- | --- |
| users | App-global auth |
| cache | App-global infrastructure |
| jobs | App-global infrastructure |
| contacts | Core |
| contact_statuses | Core |
| contact_tags | Core |
| notes | Core |
| team_members | InternalNotifications |
| team_member_notification_preferences | InternalNotifications |
| contact_workflow_profiles | Workflow |
| flow_routes | FlowRoutes |
| points | FlowRoutes |
| flow_route_points | FlowRoutes |
| contact_flow_route_progress | FlowRoutes |
| tasks | Tasks |
| message_consents | Messaging |
| consent_revocations | Messaging |
| scheduled_messages | Messaging |
| message_suppressions | Messaging |
| inbound_messages | InboundMessaging |
| campaigns | Campaigns |
| campaign_steps | Campaigns |
| campaign_enrollments | Campaigns |
| broadcasts | Broadcasts |
| broadcast_recipients | Broadcasts |
| webinar_series | Webinars |
| webinars | Webinars |
| webinar_registrations | Webinars |
| webinar_waitlist_signups | Webinars |
| mortgage_stages | Mortgage |
| contact_mortgage_profiles | Mortgage |

Core schema freeze target:

- contacts
- contact_statuses
- contact_tags
- notes

App-global schema:

- users
- cache
- jobs

Everything else belongs to a first-party module, vertical module, or app-global infrastructure.

A table should not move ownership after client rollout unless there is a clear architectural mistake.

## Current Module Layout

Primary application modules live under:

    app/Modules

Current modules include:

- `Core`
- `Messaging`
- `InboundMessaging`
- `InternalNotifications`
- `Tasks`
- `Workflow`
- `FlowRoutes`
- `Campaigns`
- `Broadcasts`
- `Webinars`
- `Reporting`
- `Mortgage`

Blade views intentionally remain under:

    resources/views

External provider adapters intentionally remain outside modules under:

    app/Integrations

Examples:

- Resend email adapter
- Telnyx SMS adapter
- Twilio SMS adapter
- Zoom webinar adapter

Adapters are not modules. They sit behind module-owned contracts/managers/services.

## Dependency Direction

Orthogonal modules do not mean zero dependencies.

Dependencies are allowed when they are logical, intentional, and one-way.

Accepted dependency direction:

- Webinars -> Core
- Webinars -> Messaging
- Campaigns -> Core
- Campaigns -> Messaging
- Broadcasts -> Core
- Broadcasts -> Messaging
- Tasks -> Core
- Tasks may use InternalNotifications and Messaging through public actions/services/contracts
- Workflow -> Core
- FlowRoutes -> Workflow
- FlowRoutes may optionally use Tasks through public task actions/services when Tasks is enabled
- FlowRoutes may optionally use Messaging through public message actions/services when Messaging is enabled
- FlowRoutes may optionally use Campaigns through public campaign actions/services when Campaigns is enabled
- InboundMessaging -> Core
- InboundMessaging -> Messaging
- InternalNotifications -> Messaging
- InternalNotifications may conditionally integrate with InboundMessaging through events/listeners
- Mortgage may consume Core, Workflow, FlowRoutes, Tasks, Messaging, Campaigns, Broadcasts, Webinars, Reporting, and Integrations as needed
- Messaging may use Integrations through provider contracts/managers
- Webinars may use Integrations through provider contracts/managers
- Mortgage may use Integrations through provider contracts/managers

Avoid:

- Core -> feature modules
- Core -> Messaging
- Core -> InboundMessaging
- Core -> InternalNotifications
- Core -> Tasks
- Core -> Webinars
- Core -> Campaigns
- Core -> Broadcasts
- Core -> FlowRoutes
- Core -> Mortgage
- Messaging -> InternalNotifications
- Messaging -> InboundMessaging
- Messaging -> Webinars
- Messaging -> Campaigns
- Messaging -> Broadcasts
- Messaging -> FlowRoutes
- Messaging -> Tasks
- InboundMessaging -> InternalNotifications
- Workflow -> FlowRoutes
- Campaigns -> FlowRoutes
- Campaigns -> Webinars
- Campaigns -> Broadcasts
- Broadcasts -> Campaigns
- Webinars -> FlowRoutes unless through public events/listeners or explicitly documented integration
- lower-level shared modules importing higher-level feature modules
- circular dependencies
- Integrations -> feature modules

## Automation Event Seam

Some module outcomes should be exposed through the app-level automation event seam instead of consumer modules listening to every producer-specific event.

Current seam:

    App\Support\AutomationEvents\Data\AutomationEventData
    App\Support\AutomationEvents\Events\AutomationEventRecorded

This seam is intentionally app-level support infrastructure, not a feature module.

It exists to prevent FlowRoutes, Tasks, Campaigns, InternalNotifications, or future vertical modules from accumulating producer-specific listeners for every module outcome.

Preferred shape:

    Producer module records its own domain state
    Producer module emits AutomationEventRecorded
    FlowRoutes listens to AutomationEventRecorded
    FlowRoutes maps the generic event into its own FlowRouteExternalEvent internally
    FlowRoutes resumes matching event_wait points if configured

Producer modules should not import FlowRoutes.

Producer modules should not call `FlowRouteExternalEvent::make`.

FlowRoutes should not add producer-specific listeners such as:

    WebinarOutcomeRecorded -> FlowRoutes
    TaskCompleted -> FlowRoutes
    MortgageStageChanged -> FlowRoutes

Good:

    Webinars -> AutomationEventRecorded(webinar.attended)
    Tasks -> AutomationEventRecorded(task.completed)
    FlowRoutes -> AutomationEventRecorded listener

Bad:

    Webinars -> FlowRoutes
    Tasks -> FlowRoutes task-specific listener
    Mortgage -> FlowRoutes
    Producer module -> FlowRouteExternalEvent

Automation events should be contact-aware, not contact-required.

Shape:

    event_key
    contact_id nullable
    subject_type nullable
    subject_id nullable
    occurred_at
    payload
    meta

Examples:

    webinar.registered
    webinar.cancelled
    webinar.attended
    webinar.missed
    webinar.ended
    task.completed

Contact-specific events can resume contact FlowRoute progress.

Contactless events, such as `webinar.ended`, may be useful for future team/admin automation, but current FlowRoutes contact progress should ignore them unless a matching contact context exists.

The automation event seam is for cross-module automation decisions.

It is not required for every module-to-module call.

Direct public action/service calls are still correct when a module is using another module as a capability.

Good direct calls:

    Webinars -> Messaging registration/reminder messages
    FlowRoutes -> CreateTaskAction
    FlowRoutes -> EnrollContactInCampaignAction
    Campaigns -> Messaging schedule/send actions

Do not route everything through automation events just for purity.

## Core Module

Core is required for every install.

Core owns:

- contacts
- contact statuses
- contact tags
- contact notes
- contact imports
- generic contact CRM pages/controllers
- contact show extension registries
- module-safe contact-facing extension points

Core contacts must remain generic.

Core contacts should answer:

> Who is this person, how can we reach them, and where did they come from?

Core contacts should not contain workflow, sales, mortgage, webinar, campaign, task assignment, internal notification, messaging, inbound message, or automation lifecycle state.

Avoid these fields on `contacts`:

- `status`
- `crm_status`
- `contact_status_id`
- `converted_at`
- `closed_at`
- `assigned_to`
- team member fields
- location/address fields
- mortgage-specific fields
- webinar-specific fields
- messaging-specific fields
- workflow-specific fields
- FlowRoute-specific fields

`ContactStatus` belongs to Core.

A contact status should be available for manual CRM adjustment even when Workflow or FlowRoutes are disabled.

Core may expose public extension points that other modules contribute to.

Current Core contact extension points include:

- `ContactPanelProvider`
- `ContactPanelRegistry`
- `ContactShowDataProvider`
- `ContactShowDataRegistry`
- `ContactImportHandler`
- `ContactImportRegistry`

Core `ContactController` may ask registries for module-provided data.

Core `ContactController` must not directly import module-specific models/services such as Tasks, Messaging, InboundMessaging, InternalNotifications, Webinars, Campaigns, Mortgage, or FlowRoutes.

## Messaging Module

Messaging is a reusable capability module.

Messaging owns outbound and scheduled message infrastructure.

Messaging owns:

- scheduled messages
- message consents
- consent revocations
- message suppressions
- email/SMS provider contracts
- provider managers
- message payloads
- message gates
- eligibility checks
- send guards
- dispatch actions
- schedule actions
- scheduled message jobs
- public opt-out/unsubscribe controllers
- message-related events
- recipient gate extension points
- recipient payload extension points

Messaging does not own:

- inbound webhook normalization/routing
- TeamMember models
- internal notification preferences
- webinar registrations
- campaigns
- FlowRoutes
- task assignment

Other modules may use Messaging through public actions/services/contracts.

Good:

    DispatchMessageAction
    ScheduleMessageAction
    SkipScheduledMessagesAction
    GrantMessageConsentAction
    RevokeMessageConsentAction
    MessageRecipientGate
    MessageRecipientPayloadProvider

Avoid:

    ScheduledMessage::query()->create(...)

unless the code is inside Messaging itself or there is an intentional, documented exception.

Messaging must remain generic.

Messaging must not import InternalNotifications or InboundMessaging.

InternalNotifications can contribute TeamMember-specific behavior to Messaging through Messaging-owned extension points.

Current Messaging extension points include:

- `MessageRecipientGate`
- `MessageRecipientGateRegistry`
- `MessageRecipientPayloadProvider`
- `MessageRecipientPayloadProviderRegistry`

Messaging consent records are currently Contact-scoped.

That is intentional for external contact messaging consent.

Internal team notification preferences belong to InternalNotifications, not Messaging.

Generic recipient support for scheduled delivery lives in `scheduled_messages.recipient_type` / `scheduled_messages.recipient_id`.

Messaging may schedule messages for non-Contact recipients through recipient payload/gate extension points, but it should not own those recipient models or their preferences.

## InboundMessaging Module

InboundMessaging is a reusable capability module.

InboundMessaging owns inbound webhooks and inbound message recording/routing.

InboundMessaging owns:

- inbound messages
- inbound SMS webhook controller/action
- inbound email webhook controller/action
- inbound payload normalization
- inbound message classification
- inbound purpose resolution
- inbound sender resolution
- inbound handler routing
- inbound webhook handler resolver bindings
- `InboundMessageReceived` event

InboundMessaging may depend on:

- Core, for resolving contacts as senders
- Messaging, for STOP/HELP consent-related behavior

InboundMessaging does not own:

- internal notification routing
- TeamMember recipients
- internal notification preferences
- internal notification scheduling

InboundMessaging should not directly notify internal users.

Instead:

1. InboundMessaging records the inbound message.
2. InboundMessaging emits `InboundMessageReceived`.
3. InternalNotifications may conditionally listen to that event and schedule internal alerts.

InboundMessaging must not import InternalNotifications.

InboundMessaging may use Messaging-owned channel and purpose concepts when classifying inbound messages.

That dependency is acceptable because InboundMessaging depends on Messaging.

Inbound message records should remain generic and should not store internal notification routing state.

## InternalNotifications Module

InternalNotifications is a reusable capability module.

InternalNotifications owns team-facing notifications and notification preferences.

InternalNotifications owns:

- team members
- team member notification preferences
- internal notification gate
- internal notification channel resolver
- internal notification recipient objects
- internal notification scheduling action
- internal notification preference resolvers
- inbound-message notification listener
- TeamMember-specific Messaging adapters

InternalNotifications may depend on Messaging.

InternalNotifications may conditionally integrate with InboundMessaging through events/listeners when both modules are enabled.

InternalNotifications contributes TeamMember support to Messaging through:

- `TeamMemberMessageRecipientGate`
- `TeamMemberMessageRecipientPayloadProvider`

Core contacts should not know about TeamMembers.

Good:

    InternalNotifications -> Messaging contract

Bad:

    Messaging -> InternalNotifications model

## Tasks Module

Tasks is a reusable capability module.

Tasks owns generic human work items.

Tasks owns:

- tasks
- task creation actions
- task digest actions
- task notification actions
- task assignment resolution
- task related-subject resolution
- task-related request validation
- task controllers
- task contact show data provider

Tasks may depend on:

- Core, for contact-related tasks
- InternalNotifications, for TeamMember assignment/notification behavior if needed
- Messaging, for sending task notifications/digests if needed

Tasks should remain independently enableable.

Tasks should not be folded into Workflow.

Some clients may want standalone contact-related tasks/reminders without Workflow or FlowRoutes.

Workflow may use Tasks later.

FlowRoutes may create Tasks later through public task-facing services/contracts.

Core should not import Tasks.

Tasks can contribute contact page data through Core’s `ContactShowDataProvider`.

Tasks emits generic automation events for automation-worthy task lifecycle outcomes.

Current event:

    task.completed

Expected shape:

    TaskCompleted -> Tasks listener -> AutomationEventRecorded(task.completed)

FlowRoutes should not listen directly to `TaskCompleted`.

FlowRoutes should resume task-related `event_wait` points through its generic `AutomationEventRecorded` listener.

## Workflow Module

Workflow is optional.

Workflow owns contact workflow state.

Workflow owns:

- `ContactWorkflowProfile`
- workflow/profile state around a contact
- shared workflow-facing status transition services/actions
- workflow events emitted after profile/status changes
- public services FlowRoutes can depend on

Workflow depends on Core.

Workflow does not own:

- `Contact`
- `ContactStatus`
- tasks as a capability
- TeamMembers as a capability
- internal notification routing
- scheduled message infrastructure
- automated route execution

Workflow state belongs in:

    contact_workflow_profiles

Not in:

    contacts

A contact may exist with no workflow profile.

Workflow should provide the shared path for status/profile transitions when Workflow is enabled.

Expected direction:

1. Core/manual CRM or another module requests a status/profile transition through Workflow.
2. Workflow records the state/profile change.
3. Workflow emits an event.
4. FlowRoutes may later react to that event.

Workflow should not depend on FlowRoutes.

Workflow should not call FlowRoutes directly.

## FlowRoutes Module

FlowRoutes is optional and depends on Workflow.

Use the term `FlowRoutes`.

Do not casually call this domain “flows,” because that creates confusion with Workflow.

FlowRoutes owns:

- `FlowRoute`
- `FlowRoutePoint`
- `Point`
- `ContactFlowRouteProgress`
- route/point automation behavior
- active route execution state
- route cancellation/superseding behavior
- point execution behavior
- wait/resume behavior
- condition/branch evaluation behavior
- external event wait/resume behavior
- FlowRoute preset sync

FlowRoutes depends on Workflow.

FlowRoutes reacts to Workflow status/profile events.

FlowRoutes also listens to the generic `AutomationEventRecorded` seam for external event waits.

FlowRoutes should have exactly these broad listener categories:

1. Workflow lifecycle events, such as `ContactWorkflowStatusChanged`.
2. Generic automation events, such as `AutomationEventRecorded`.

Workflow lifecycle events are allowed to stay direct because they start, supersede, and evaluate route progress based on ContactStatus.

Automation-worthy producer outcomes should go through `AutomationEventRecorded`.

FlowRoutes should not add producer-specific listeners for Webinars, Tasks, Mortgage, Campaigns, Broadcasts, or other vertical modules.

Core should not call FlowRoutes.

Workflow should not call FlowRoutes directly.

Expected direction:

1. Contact status/profile changes through Workflow.
2. Workflow records the change.
3. Workflow emits an event.
4. FlowRoutes listens/reacts.
5. FlowRoutes supersedes active route progress if needed.
6. FlowRoutes evaluates whether the new ContactStatus has a FlowRoute.
7. FlowRoutes starts or advances route execution if appropriate.

Current tables/models:

    flow_routes
    points
    flow_route_points
    contact_flow_route_progress

Current models:

    FlowRoute
    Point
    FlowRoutePoint
    ContactFlowRouteProgress

A `ContactStatus` may have at most one active FlowRoute.

Runtime meaning:

    ContactStatus has one FlowRoute
    FlowRoute has many FlowRoutePoints
    FlowRoutePoint belongs to Point
    ContactFlowRouteProgress records active/waiting/completed/cancelled execution state

FlowRoutes runtime behavior should read DB-owned route/point definitions.

Preset config may create/update DB-owned FlowRoute definitions, but runtime execution should not depend directly on config definitions.

Current point handler capabilities include:

- noop
- wait
- condition
- branch_evaluate
- event_wait
- create_task
- send_message
- change_status
- enroll_campaign
- cancel_campaign

FlowRoutes may use Tasks only through public task-facing services/actions.

FlowRoutes may use Messaging only through public Messaging actions/services.

FlowRoutes may use Campaigns only through public Campaigns actions/services.

Good:

    CreateTaskAction
    DispatchMessageAction
    TransitionContactWorkflowStatusAction
    EnrollContactInCampaignAction
    CancelCampaignEnrollmentAction

Bad:

    Task::create(...)
    ScheduledMessage::create(...)
    CampaignEnrollment::create(...)

Also bad:

    Webinars imports FlowRoutes
    Tasks completion listener inside FlowRoutes
    Producer modules call FlowRouteExternalEvent::make(...)

`contact_flow_route_progress` may store denormalized `contact_id` and `contact_status_id` values for query/runtime convenience.

Those fields do not make FlowRoutes the owner of Contact or ContactStatus.

Canonical ownership remains:

- Contact belongs to Core.
- ContactStatus belongs to Core.
- ContactWorkflowProfile belongs to Workflow.
- ContactFlowRouteProgress belongs to FlowRoutes.

## Campaigns Module

Campaigns is optional.

Campaigns owns enrolled, multi-step campaign journeys.

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

## Broadcasts Module

Broadcasts is optional.

Broadcasts owns one-time and batch audience sends.

Broadcasts owns:

- broadcasts
- broadcast recipients
- broadcast audience metadata
- broadcast recipient state
- broadcast scheduling/orchestration behavior later
- broadcast-specific metadata

Broadcasts does not own:

- enrolled multi-step campaign journeys
- campaign definitions
- campaign steps
- campaign enrollments
- message delivery infrastructure
- message consent/suppression infrastructure

Campaigns and Broadcasts are separate concepts.

Campaigns are enrolled, multi-step journeys with lifecycle/progression.

Broadcasts are one-time or batch sends to an audience.

Messaging owns scheduled/outbound message infrastructure.

Broadcasts may depend on:

- Core
- Messaging

Broadcasts should use Messaging public actions/services to schedule or send messages.

Good:

    DispatchMessageAction
    ScheduleMessageAction

Bad:

    ScheduledMessage::query()->create(...)

Broadcasts should not depend on Campaigns.

Campaigns should not depend on Broadcasts.

Broadcasts may reference Messaging-owned scheduled messages for bookkeeping and visibility.

That reference is acceptable because Broadcasts depends on Messaging.

Broadcasts should still send or schedule through Messaging public actions/services.

`broadcast_recipients.scheduled_message_ids` is broadcast bookkeeping, not ownership of scheduled delivery infrastructure.

## Webinars Module

Webinars is optional.

Webinars owns:

- webinar series
- webinars
- webinar registrations
- webinar waitlist signups
- webinar provider behavior
- webinar reminders
- webinar follow-ups
- webinar attendance recording
- webinar post-event behavior
- webinar contact panels

Zoom is not a module.

Zoom is an adapter used by Webinars.

Webinars may depend on:

- Core
- Messaging

Webinars may use Messaging to send reminders/follow-ups.

Webinars should not directly own Campaign enrollment routing.

Current outcome direction:

1. Webinars records webinar registration/attendance/outcome state.
2. Webinars emits `AutomationEventRecorded` for automation-worthy outcomes.
3. FlowRoutes listens to the generic automation event seam.
4. FlowRoutes maps generic automation events into `FlowRouteExternalEvent` internally.
5. FlowRoutes decides whether to create tasks, change status, enroll Campaigns, cancel Campaigns, or send messages.
6. Campaigns owns Campaign enrollment/progression.
7. Messaging owns delivery/scheduling.

Current webinar automation events:

    webinar.registered
    webinar.cancelled
    webinar.attended
    webinar.missed
    webinar.ended

`webinar.ended` may be contactless.

Contactless automation events should not force contact FlowRoute progress to resume unless a contact context exists.

Good:

    DispatchMessageAction
    ScheduleMessageAction
    AutomationEventRecorded
    AutomationEventData

Bad:

    CampaignEnrollment::create(...)
    ScheduledMessage::create(...)
    Webinars directly deciding Campaign route orchestration

Webinar registration records should store webinar participation state, registration source, join token, and webinar-specific metadata.

Consent audit details such as IP address, user agent, opt-in language, and opt-in timestamp belong to Messaging consent records, not Webinar registration records.

Webinar outcome fields such as `registered_at`, `attended_at`, and `cancelled_at` belong to Webinars.

Those outcome fields are emitted through `AutomationEventRecorded`, then FlowRoutes maps them into `FlowRouteExternalEvent` internally when needed.

Webinars should not decide Campaign, Workflow, task, or FlowRoute orchestration directly.

## Reporting Module

Reporting is optional.

Reporting owns reporting-specific queries, dashboards, data objects, and report controllers.

Reporting may read from other modules through public read services, query services, events, or documented reporting interfaces.

Reporting should avoid becoming a dumping ground for cross-module business logic.

Reporting should not mutate another module’s internal state directly.

## Mortgage Module

Mortgage is a vertical module.

Mortgage is optional and should not be installed by default.

Mortgage owns:

- mortgage stages
- contact mortgage profiles
- mortgage-specific fields
- LOS automation
- mortgage-specific adapters
- mortgage-specific workflow definitions later
- mortgage-specific FlowRoute definitions later

Mortgage may consume:

- Core
- Workflow
- FlowRoutes
- Tasks
- Messaging
- Campaigns
- Webinars
- Reporting
- Integrations

Mortgage must not push mortgage-specific state into Core contacts.

Vertical-specific migrations belong under:

    database/migrations/verticals/mortgage

Mortgage may depend on Arive or other LOS providers through adapter contracts/services.

## Location Module

Location is not currently present in the tree, but may exist later as an optional module.

Location would own:

- contact locations
- city/state/zip/country
- latitude/longitude
- radius/market filtering

Location should not be part of Core contacts unless there is a deliberate future decision to make basic address fields core.

## Adapters / Integrations

Adapters are not modules.

Examples:

- Resend powers email
- Telnyx/Twilio power SMS
- Zoom powers webinar behavior
- Arive may power mortgage LOS behavior later

Adapters should sit behind contracts, managers, resolvers, or provider services.

Modules should depend on contracts/managers/services, not concrete adapter internals.

Current integration path:

    app/Integrations

Current examples:

    app/Integrations/Messaging/Email/Resend
    app/Integrations/Messaging/Sms/Telnyx
    app/Integrations/Messaging/Sms/Twilio
    app/Integrations/Webinars/Zoom

## Contact Show UI

The Core contact show page is a shell.

Core owns the page and generic contact details.

Modules contribute module-specific contact data/UI through Core-owned extension points.

Current extension points:

    ContactPanelProvider
    ContactPanelRegistry
    ContactShowDataProvider
    ContactShowDataRegistry

Examples:

- Webinars contributes webinar history panel.
- Tasks contributes task data.
- Messaging contributes scheduled message/consent data.

Good:

    Tasks -> ContactShowDataProvider
    Messaging -> ContactShowDataProvider
    Webinars -> ContactPanelProvider

Bad:

    Core ContactController -> Task::query()
    Core ContactController -> TeamMember::query()
    Core ContactController -> ScheduledMessage::query()
    Core Contact model -> messageConsents()
    Core Contact model -> inboundMessages()

Core contacts remain generic. Module-specific contact page details are contributed by modules.

## Practical Dependency Standard

When module A needs module B, prefer:

    Module A -> Module B public action/service/contract

Avoid:

    Module A -> Module B table internals

Examples:

Good:

    $dispatchMessageAction->handle(...)

Bad:

    ScheduledMessage::query()->create(...)

Good:

    $enrollContactInCampaignAction->handle(...)

Bad:

    CampaignEnrollment::query()->create(...)

Good:

    Workflow emits ContactWorkflowProfileChanged

Bad:

    Core calls FlowRoutes execution internals

Good:

    FlowRoutes listens to Workflow events

Bad:

    Workflow starts FlowRoute execution directly

Good:

    InternalNotifications contributes TeamMember support to Messaging through MessageRecipientGate

Bad:

    Messaging imports TeamMember

## Boundary Guardrails

The test suite should protect module boundaries.

Current guardrails should ensure:

- Core does not import higher-level feature modules.
- Messaging does not import InternalNotifications or InboundMessaging.
- InboundMessaging does not import InternalNotifications.
- Provider dependency expansion does not accidentally change explicit module visibility.
- Contact show module visibility respects enabled modules.
- Producer modules do not import FlowRoutes.
- `FlowRouteExternalEvent::make(...)` is only called from FlowRoutes-owned code.
- FlowRoutes does not listen directly to producer-specific events such as TaskCompleted or Webinar-specific outcomes.
- Automation-worthy producer outcomes use `AutomationEventRecorded`.

When a boundary test fails, prefer improving the architecture over whitelisting the violation.

A whitelist should only be used for a deliberate, documented exception.

## Current Implementation Direction

The current architecture is entering client-rollout schema freeze.

Recommended next implementation order:

1. Phase 17 — Schema/module freeze pass
2. Phase 18 — Generic automation event seam for external resumes
3. Phase 18.5 — Automation boundary audit and cleanup
4. Phase 19 — Default MVP presets
5. Phase 20 — Tasks and digest verification
6. Phase 21 — Minimal contact visibility/debug
7. Phase 22 — Client MVP smoke test

### Phase 17 — Schema/module freeze pass

Confirm Core stays minimal.

Confirm every existing table belongs clearly to one owner:

- Core
- app-global auth/infrastructure
- reusable first-party module
- vertical module

Confirm model namespaces match table ownership.

Confirm `config/modules.php` dependency direction is final enough for rollout.

Confirm `config/presets.php` module selections align with ownership.

Update boundary docs and boundary tests only where they protect final dependency direction.

Do not add product features, admin UI, or migrations unless the audit reveals a clear ownership mistake.

### Phase 18 — Generic automation event seam for external resumes

Add the generic automation event seam:

    AutomationEventData
    AutomationEventRecorded

Producer modules emit generic automation events after recording their own domain state.

FlowRoutes listens to `AutomationEventRecorded` and maps those events into `FlowRouteExternalEvent` internally.

Webinars emits:

- `webinar.registered`
- `webinar.cancelled`
- `webinar.attended`
- `webinar.missed`
- `webinar.ended`

Tasks emits:

- `task.completed`

Remove direct producer-specific FlowRoutes listeners where the outcome is an external event wait/resume trigger.

Keep Workflow status changes direct because they are FlowRoute lifecycle behavior, not generic event_wait behavior.

FlowRoutes should decide reactions through:

- `event_wait`
- `enroll_campaign`
- `cancel_campaign`
- `create_task`
- `change_status`
- `send_message`

### Phase 18.5 — Automation boundary audit and cleanup

Audit event/listener boundaries before adding MVP presets.

Confirmed direction:

- FlowRoutes listens to `ContactWorkflowStatusChanged` for Workflow lifecycle behavior.
- FlowRoutes listens to `AutomationEventRecorded` for generic external event waits.
- Tasks emits `task.completed` through `AutomationEventRecorded`.
- Webinars emits webinar outcomes through `AutomationEventRecorded`.
- Campaigns may listen to `ScheduledMessageSent` for campaign step progression because that is Campaign-owned lifecycle bookkeeping.
- InternalNotifications may listen to `InboundMessageReceived` because inbound reply notification is an InternalNotifications feature.
- Messaging consent and scheduled-message events remain Messaging domain events.

Guardrails:

- Do not add producer-specific listeners to FlowRoutes.
- Do not make producer modules import FlowRoutes.
- Do not route ordinary capability calls through automation events unnecessarily.
- Do not use the automation bus as a replacement for public actions/services.

### Phase 19 — Default MVP presets

Add MVP preset definitions for:

- webinar registration/default follow-up path
- attended path
- missed/no-show path
- long-term nurture Campaign
- internal follow-up task path

Runtime behavior should remain DB-driven through preset sync.

Preset config should only create/update DB-owned definitions.

### Phase 20 — Tasks and digest verification

Confirm FlowRoutes-created tasks are assigned correctly.

Confirm daily and weekly digests include those tasks.

Confirm TeamMember notification preferences and gates work.

Confirm the email digest path works end-to-end.

### Phase 21 — Minimal contact visibility/debug

Add read-only contact visibility for:

- current status/workflow profile
- active/recent FlowRoute progress
- Campaign enrollments
- scheduled messages
- tasks

Do not add builders or editors.

### Phase 22 — Client MVP smoke test

Verify the real MVP path:

1. Register for webinar.
2. Simulate provider attendance or missed outcome.
3. Confirm status/event routing.
4. Confirm FlowRoute progress.
5. Confirm Campaign enrollment/cancellation.
6. Confirm scheduled messages.
7. Confirm task creation/digest.