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
- Tasks -> Core
- Workflow -> Core
- FlowRoutes -> Workflow
- FlowRoutes may later use Tasks through public task-facing contracts/services
- InboundMessaging -> Core
- InboundMessaging -> Messaging
- InternalNotifications -> Messaging
- InternalNotifications may conditionally integrate with InboundMessaging through events/listeners
- Mortgage may consume Core, Workflow, FlowRoutes, Tasks, Messaging, Campaigns, Webinars, and Integrations as needed

Avoid:

- Core -> feature modules
- Core -> Messaging
- Core -> InboundMessaging
- Core -> InternalNotifications
- Core -> Tasks
- Core -> Webinars
- Core -> Campaigns
- Core -> FlowRoutes
- Core -> Mortgage
- Messaging -> InternalNotifications
- Messaging -> InboundMessaging
- Messaging -> Webinars
- Messaging -> Campaigns
- Messaging -> FlowRoutes
- Messaging -> Tasks
- InboundMessaging -> InternalNotifications
- Workflow -> FlowRoutes
- lower-level shared modules importing higher-level feature modules
- circular dependencies

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
- route/point automation behavior
- active route execution state later
- route cancellation behavior later
- point execution behavior later

FlowRoutes depends on Workflow.

FlowRoutes should react to Workflow status/profile events.

Core should not call FlowRoutes.

Workflow should not call FlowRoutes.

Expected direction:

1. Contact status/profile changes through Workflow.
2. Workflow records the change.
3. Workflow emits an event.
4. FlowRoutes listens/reacts.
5. FlowRoutes cancels active route progress if needed.
6. FlowRoutes evaluates whether the new ContactStatus has a FlowRoute.
7. FlowRoutes starts or advances route execution if appropriate.

Current tables/models:

    flow_routes
    points
    flow_route_points

Current models:

    FlowRoute
    Point
    FlowRoutePoint

A `ContactStatus` may have at most one active FlowRoute.

Runtime meaning:

    ContactStatus has one FlowRoute
    FlowRoute has many FlowRoutePoints
    FlowRoutePoint belongs to Point

Generated tasks or scheduled work should snapshot relevant cancellation/condition rules so later route/point edits do not mutate history.

FlowRoutes may later use Tasks through public task-facing services/contracts.

FlowRoutes may later use Messaging through public Messaging actions/services if route points send messages.

## Campaigns Module

Campaigns is optional.

Campaigns owns:

- broadcasts
- broadcast recipients
- campaigns
- campaign enrollments
- campaign step scheduling behavior
- campaign listeners
- segments/conditions later
- newsletters later
- announcement-style sends later

Campaigns may depend on:

- Core
- Messaging

Campaigns may use Messaging to send messages.

Campaigns should not directly depend on Workflow status, Webinar conversion, or Mortgage stages unless those relationships are introduced through explicit public APIs/events/resolvers.

Use generic fields such as:

    start_context
    exit_conditions
    exited_at
    exit_reason

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

    ScheduleMessageAction

Avoid direct ScheduledMessage creation from Campaigns unless explicitly documented.

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
- Campaigns, through public Campaigns actions if post-webinar campaigns are enabled

Webinars may use Messaging to send reminders/follow-ups.

Webinars may trigger Campaign enrollment through public Campaigns actions.

Good:

    EnrollContactInCampaignAction
    DispatchMessageAction
    ScheduleMessageAction

Bad:

    CampaignEnrollment::create(...)
    ScheduledMessage::create(...)

Webinars can contribute contact page UI through Core’s contact panel extension point.

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

When a boundary test fails, prefer improving the architecture over whitelisting the violation.

A whitelist should only be used for a deliberate, documented exception.

## Current Implementation Direction

The current architecture is ready for Workflow and FlowRoutes buildout.

Recommended next implementation order:

1. Strengthen Workflow-owned status/profile transition path.
2. Emit Workflow events after profile/status changes.
3. Keep Core independent from Workflow/FlowRoutes internals.
4. Let FlowRoutes react to Workflow events.
5. Add FlowRoutes route evaluation/cancellation/progression incrementally.
6. Add task/message route point behavior only through public Tasks/Messaging APIs.

The goal is to keep module dependencies intentional, one-way, and future-proof before adding new automation behavior.