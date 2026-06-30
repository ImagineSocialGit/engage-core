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

    database/migrations/verticals/{vertical-key}

Examples:

    database/migrations/verticals/mortgage
    database/migrations/verticals/pet-service
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
| task_templates | Tasks |
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
- Tasks -> Core
- Tasks may optionally use InternalNotifications and Messaging through public actions/services/contracts when those modules are enabled
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
    FlowRoutes may start matching event-triggered routes
    FlowRoutes may resume matching event_wait points on already-started routes

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

Contact-specific events may start contact FlowRoutes or resume contact FlowRoute progress.

Contactless events, such as `webinar.ended`, may be useful for future team/admin automation, but current contact FlowRoute progress should ignore them unless a matching contact context exists.

The automation event seam is for cross-module automation decisions.

It is not required for every module-to-module call.

Direct public action/service calls are still correct when a module is using another module as a capability.

Good direct calls:

    Webinars -> Messaging registration/reminder/post-webinar transactional messages
    FlowRoutes -> CreateTaskAction
    FlowRoutes -> EnrollContactInCampaignAction
    Campaigns -> Messaging schedule/send actions

Do not route everything through automation events just for purity.

## Core Module

Core is required for every install.

Core owns:

- contacts
- contact statuses
- contact status preset sync
- contact tags
- contact notes
- contact imports
- generic contact CRM pages/controllers
- contact show extension registries
- module-safe contact-facing extension points

ContactStatus preset sync may be run directly through Core tooling, but normal new-project setup should be orchestrated by the app-level `presets:sync` command.

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

Messaging definitions should use a consistent canonical definition shape across config files and DB-adapted inline definitions.

Canonical message definition shape:

    dispatch_key
    message_type
    channel
    purpose
    scope
    timing
    queue
    payload_class
    conditions
    schedule
    payload
    meta

A definition may omit fields that are inferable from caller context, but adapters should normalize into this shape before calling Messaging runtime actions.

Messaging definitions are reusable templates.

Messaging owns reusable message copy and delivery templates, including subject/body/CTA payloads.

Campaign-owned message templates live inside Messaging configs under:

    campaigns.{campaign_key}.steps.{step_number}

Those campaign message templates are resolved by:

    channel + purpose + scope + campaign_key + step_number

Campaign presets should not duplicate reusable message copy.

Campaign presets should not define or override payloads.

Campaign presets own journey identity, step order, and step timing.

Messaging owns the delivery template for the campaign step.

Post-webinar transactional follow-ups should use the same Messaging definition shape as confirmations, reminders, opt-ins, and campaign message templates.


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

Tasks owns tracked manual human actions and dependencies.

A Task represents something a human needs to do, complete, provide, review, confirm, or manually resolve.

Tasks are not automation.

Tasks are not message journeys.

Tasks are not lifecycle statuses.

Tasks are not internal notifications.

Tasks may represent:

- internal team work
- contact-owned manual actions
- third-party/vendor/manual dependencies
- follow-up reminders
- review/checklist items
- mortgage process dependencies
- module-created human work generated by automation

Examples:

- Call Jan
- Review application
- Borrower needs to upload bank statements
- Client needs to sign disclosures
- Realtor needs to send contract
- Inspection needs to be completed
- Appraisal is pending
- Compile webinar questions
- Follow up after inbound reply

Tasks owns:

- tasks
- task creation actions
- task digest actions
- task notification actions
- task assignment resolution
- task related-subject resolution
- task responsible-party/responsibility metadata
- task-related request validation
- task controllers
- task contact show data provider
- task lifecycle events
- task templates
- task template preset sync

Current `tasks` responsibility shape:

    related_type / related_id
        What the task is about.

    assigned_to_type / assigned_to_id
        Optional internal owner/tracker of the task.

    responsible_party
        Who or what actually needs to perform the manual action.

    responsible_type / responsible_id
        Optional concrete model responsible for the action when one exists.

Current `responsible_party` values:

    internal
    contact
    third_party
    unknown

Meaning:

    assigned_to = who internally owns/tracks the task
    responsible_party = who/what must actually do the manual action
    responsible = concrete responsible model when available

Examples:

    Task: Call Jan
    related = Contact
    assigned_to = TeamMember
    responsible_party = internal
    responsible = null

    Task: Borrower needs to upload bank statements
    related = Contact or future MortgageFile/Application
    assigned_to = TeamMember processor, if applicable
    responsible_party = contact
    responsible = Contact

    Task: Inspection needs to be completed
    related = future MortgageFile/Application or Contact
    assigned_to = TeamMember loan coordinator, if applicable
    responsible_party = third_party
    responsible = null until a third-party/vendor model exists

Task templates are DB-owned default task definitions.

Task templates are not live tasks.

Task preset sync may create/update `task_templates`, but it must not create live `tasks`.

Runtime task creation should continue to use task-facing actions such as:

    CreateTaskAction

Task templates may later be used by admin UI, FlowRoutes, vertical modules, or standalone task creation screens to prefill task fields, but runtime behavior should remain DB/action-driven.

Task assignment is optional.

Unassigned tasks are valid when the system needs to track a manual dependency before a clear internal owner exists.

Task responsibility should not imply notification delivery.

Task responsibility should not imply Messaging consent.

Task responsibility should not imply that the responsible party can log in, receive messages, or complete the task directly.

Those behaviors require separate feature work.

Tasks depends on:

- Core, for contact-related tasks, contact-responsible tasks, and contact show extension points

Tasks may optionally use, when enabled:

- InternalNotifications, for TeamMember assignment notification and digest recipient behavior
- Messaging, indirectly through InternalNotifications notification delivery behavior

Tasks must remain useful with only Core enabled.

Task creation, completion, templates, and basic task visibility must not require InternalNotifications or Messaging.

Task assignment may exist as data even when notification delivery is unavailable.

Task digests and assignment notifications should no-op or be unavailable unless InternalNotifications is enabled.

Tasks should remain independently enableable.

Tasks should not be folded into Workflow.

Some clients may want standalone contact-related tasks/reminders without Workflow or FlowRoutes.

Workflow may use Tasks later through public task-facing actions/services.

FlowRoutes may create Tasks through public task-facing actions/services.

Core should not import Tasks.

Tasks can contribute contact page data through Core’s `ContactShowDataProvider`.

Reusable task Blade components should live under:

    resources/views/components/tasks

Contact pages may include those components with contact context.

Standalone task pages may later include those components without contact context.

Task UI should avoid exposing raw morph internals to users. Admin-facing labels should keep the mental model simple:

    Assigned To = internal tracker/follow-up owner
    Responsible Party = who needs to do the manual thing

FlowRoutes-created tasks should use:

    CreateTaskAction

Not:

    Task::query()->create(...)

Tasks emits generic automation events for automation-worthy task lifecycle outcomes.

Current event:

    task.completed

Expected shape:

    TaskCompleted -> Tasks listener -> AutomationEventRecorded(task.completed)

FlowRoutes should not listen directly to `TaskCompleted`.

FlowRoutes should resume task-related `event_wait` points through its generic `AutomationEventRecorded` listener.

Completing a task means the tracked manual action/dependency was completed.

It does not necessarily mean the assigned internal owner personally performed the action.

For example:

- A borrower uploads required documents.
- A processor marks the borrower document task completed.
- Tasks emits `task.completed`.
- FlowRoutes may resume from the generic automation event if configured.

Tasks should stay generic.

Mortgage-specific document collection, LOS milestones, appraisal details, title work, or underwriting state should belong to Mortgage or another vertical/domain module.

Tasks may reference those records through `related` or `responsible` morphs, but Tasks should not own their domain-specific state.

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
- external event start behavior
- external event wait/resume behavior
- FlowRoute preset sync

FlowRoute preset sync assumes required Campaign definitions already exist when a route uses campaign points.

Status-triggered FlowRoutes also assume required ContactStatus definitions already exist.

Normal setup should use the app-level `presets:sync` command so dependencies are created first.

FlowRoutes depends on Workflow for status-triggered route behavior.

FlowRoutes also listens to the generic `AutomationEventRecorded` seam for automation-event-triggered routes and event waits.

FlowRoutes should have exactly these broad listener categories:

1. Workflow lifecycle events, such as `ContactWorkflowStatusChanged`.
2. Generic automation events, such as `AutomationEventRecorded`.

Workflow lifecycle events are allowed to stay direct because they start, supersede, and evaluate route progress based on ContactStatus.

Automation-worthy producer outcomes should go through `AutomationEventRecorded`.

FlowRoutes should not add producer-specific listeners for Webinars, Tasks, Mortgage, Campaigns, Broadcasts, or other vertical modules.

Core should not call FlowRoutes.

Workflow should not call FlowRoutes directly.

Expected status-triggered direction:

1. Contact status/profile changes through Workflow.
2. Workflow records the change.
3. Workflow emits an event.
4. FlowRoutes listens/reacts.
5. FlowRoutes supersedes active route progress if needed.
6. FlowRoutes evaluates whether the new ContactStatus has a FlowRoute.
7. FlowRoutes starts or advances route execution if appropriate.

Expected automation-event-triggered direction:

1. Producer module records its own domain state.
2. Producer module emits `AutomationEventRecorded`.
3. FlowRoutes maps that event to `FlowRouteExternalEvent`.
4. FlowRoutes starts matching active routes whose preset trigger is the automation event.
5. FlowRoutes also resumes any existing progress waiting at matching `event_wait` points.
6. FlowRoutes executes route points through DB-owned definitions.

Examples:

    webinar.attended -> FlowRoutes event-triggered route -> enroll_campaign(webinar_attended_nurture)
    webinar.missed -> FlowRoutes event-triggered route -> enroll_campaign(webinar_missed_nurture)
    task.completed -> FlowRoutes event_wait resume, if configured

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

A `ContactStatus` may have at most one active status-triggered FlowRoute.

Automation-event-triggered FlowRoutes do not require `contact_status_id`.

Runtime meaning:

    ContactStatus may have one status-triggered FlowRoute
    Automation event keys may trigger active event-triggered FlowRoutes
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

FlowRoutes may create Tasks through public task-facing services/contracts.

FlowRoutes `create_task` points may create assigned or unassigned tasks.

FlowRoutes should pass task responsibility fields through `CreateTaskAction` rather than encoding responsibility only in FlowRoute metadata.

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

`contact_flow_route_progress` may store denormalized `contact_id` and nullable `contact_status_id` values for query/runtime convenience.

Those fields do not make FlowRoutes the owner of Contact or ContactStatus.

Canonical ownership remains:

- Contact belongs to Core.
- ContactStatus belongs to Core.
- ContactWorkflowProfile belongs to Workflow.
- ContactFlowRouteProgress belongs to FlowRoutes.

For automation-event-started routes, `contact_status_id` and `contact_workflow_profile_id` may be null on `contact_flow_route_progress`.

That is expected.

It means the route started from an automation event rather than a Workflow status transition.

## Campaigns Module

Campaigns is optional.

Campaigns owns enrolled, multi-step campaign journeys.

Campaigns are outbound conversion, nurture, and re-engagement message journeys.

Campaigns are not general workflows.

Campaigns should not model every business process, task dependency, status transition, or automation decision.

Use FlowRoutes for automation/control flow.

Use Tasks for manual human actions/dependencies.

Use Messaging for delivery.

Use Broadcasts for one-time or audience-wide announcements.

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

## Broadcasts Module

Broadcasts is optional.

Broadcasts owns one-time and batch audience sends.

Broadcasts owns:

- broadcasts
- broadcast recipients
- broadcast audience metadata
- broadcast recipient state
- ad hoc one-time message payload/copy
- broadcast scheduling/orchestration behavior later
- broadcast-specific metadata
- broadcast delivery bookkeeping

Broadcasts does not own:

- enrolled multi-step campaign journeys
- campaign definitions
- campaign steps
- campaign enrollments
- reusable message templates
- scheduled message delivery infrastructure
- message consent/suppression infrastructure
- message gates
- send jobs

Campaigns and Broadcasts are separate concepts.

Campaigns are enrolled, multi-step journeys with lifecycle/progression.

Broadcasts are one-time or batch sends to an audience.

Broadcasts may store ad hoc payload/copy because broadcasts are not reusable Campaign journeys.

Messaging owns reusable delivery infrastructure, scheduled messages, consent, suppression, gates, payload classes, queues, and send jobs.

Broadcasts may depend on:

- Core
- Messaging

Broadcast delivery metadata should be first-class on `broadcasts`:

    dispatch_key
    message_type
    payload_class
    queue

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

BroadcastRecipient records are Broadcast bookkeeping.

BroadcastRecipient records may store scheduled message IDs for visibility/audit, but they do not own the scheduled delivery lifecycle.

Expected future runtime direction:

    Broadcast UI/action creates Broadcast
    Broadcast audience resolver creates BroadcastRecipients
    Broadcast send/schedule action calls Messaging public action/service
    Messaging creates ScheduledMessages
    BroadcastRecipient stores resulting scheduled_message_ids/status bookkeeping

Broadcasts should remain simpler than Campaigns.

Do not add multi-step progression, enrollment lifecycle, or journey logic to Broadcasts.

If a send needs multiple sequenced steps, it belongs in Campaigns, not Broadcasts.

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

Webinars may use Messaging to send registration confirmations, reminders, opt-ins, and post-webinar transactional follow-ups.

Post-webinar transactional follow-ups are not campaign nurture.

They may contain replay/recording links and should use:

    purpose = transactional
    scope = webinar
    dispatch_key = webinar_ended

Post-webinar nurture campaigns are marketing journeys and should be handled through Campaigns after FlowRoutes enrollment.

They should use:

    purpose = marketing
    scope = webinar_nurture


Webinars should not directly own Campaign enrollment routing.

Webinars should not directly create CampaignEnrollment records.

Webinars should not transition Workflow status solely to trigger Campaign enrollment.


Current outcome direction:

1. Webinars records webinar registration/attendance/outcome state.
2. Webinars uses Messaging for webinar-owned transactional messages such as confirmations, reminders, and replay follow-ups.
3. Webinars emits `AutomationEventRecorded` for automation-worthy outcomes.
4. FlowRoutes listens to the generic automation event seam.
5. FlowRoutes maps generic automation events into `FlowRouteExternalEvent` internally.
6. FlowRoutes starts matching event-triggered routes or resumes matching `event_wait` points.
7. FlowRoutes decides whether to create tasks, change status, enroll Campaigns, cancel Campaigns, or send messages.
8. Campaigns owns Campaign enrollment/progression.
9. Messaging owns delivery/scheduling.

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
4. Phase 19 — Default presets
5. Phase 20 — Tasks and digest verification
6. Phase 21 — Minimal contact visibility/debug
7. Phase 22 — Client MVP smoke test
8. Phase 22.5 — Canonical message config and post-webinar follow-up cleanup

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

FlowRoutes may:

- start matching event-triggered routes
- resume matching existing `event_wait` progress

Webinars emits:

- `webinar.registered`
- `webinar.cancelled`
- `webinar.attended`
- `webinar.missed`
- `webinar.ended`

Tasks emits:

- `task.completed`

Remove direct producer-specific FlowRoutes listeners where the outcome is an external event wait/resume/start trigger.

Keep Workflow status changes direct because they are FlowRoute lifecycle behavior, not generic producer outcomes.

FlowRoutes should decide reactions through:

- automation-event route triggers
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
- FlowRoutes listens to `AutomationEventRecorded` for generic automation-event route starts and external event waits.
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

### Phase 19 — Default presets

Add default preset definitions and sync tooling for:

- ContactStatus presets
- Task template presets
- Campaign presets
- FlowRoute presets

Preset config should create/update DB-owned definitions.

Runtime behavior should remain DB-driven.

Preset config should not become runtime business logic.

Default presets should stay small, understandable, and client-safe.

The normal setup path should use the app-level preset sync command:

    php artisan presets:sync

That command should prompt for or accept a preset package key, inspect the selected preset, and run the required module preset syncs in dependency-safe order.

Current dependency-safe order:

1. ContactStatus presets
2. Task template presets
3. Campaign presets
4. FlowRoute presets

Module-specific sync commands may remain available as lower-level operator/debugging tools, but new project setup should use `presets:sync`.

Do not add large workflow builders or admin editors in this phase.

### Phase 20 — Task UX and digest verification

Confirm manually-created and FlowRoutes-created tasks behave correctly.

Confirm Tasks remain standalone-capable.

Confirm tasks may be contact-related but are not contact-required.

Confirm assigned and unassigned tasks are valid.

Confirm `assigned_to` and `responsible_party` remain separate concepts.

Confirm `responsible_party` is displayed clearly enough for mortgage/manual dependency tracking.

Confirm task templates are DB-owned definitions only and preset sync does not create live tasks.

Confirm FlowRoutes-created tasks use `CreateTaskAction`.

Confirm `task.completed` is emitted through `AutomationEventRecorded`, not direct FlowRoutes listeners.

Confirm daily and weekly digests are assignment-driven.

Confirm task digest and assignment notification behavior is gated behind InternalNotifications.

Confirm reusable task components live under the Tasks component namespace and can be attached to contact pages without becoming contact-specific.

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
3. Confirm Webinars records attendance/playback.
4. Confirm webinar-owned transactional post-event follow-ups are scheduled/sent.
5. Confirm Webinars emits automation events.
6. Confirm FlowRoutes starts event-triggered routes from `AutomationEventRecorded`.
7. Confirm Campaign enrollment/cancellation.
8. Confirm scheduled campaign messages.
9. Confirm task creation/digest when configured.

### Phase 22.5 — Canonical message config and post-webinar follow-up cleanup

Current cleanup target:

- make message configs use one consistent canonical definition shape
- keep transactional webinar follow-ups separate from marketing nurture campaigns
- make post-webinar transactional follow-ups dispatch through Messaging definitions
- make campaign steps resolve Messaging templates by campaign key and step number
- keep CampaignStep message template references in first-class `channel`, `purpose`, and `scope` fields, not `meta.message`
- keep Campaign presets focused on journey orchestration, not reusable message copy
- prevent Campaign presets from defining or overriding payloads
- keep default webinar copy vertical-neutral
- keep mortgage-specific copy in mortgage-specific scopes such as `mortgage_homebuyer_nurture`
- grant/check consent using the correct purpose/scope pair

Purpose/scope decisions:

    transactional:webinar
        confirmations
        reminders
        live join reminders
        replay/recording follow-ups

    marketing:webinar_nurture
        attended nurture campaign
        missed nurture campaign
        long-term post-webinar nurture

Implementation direction:

1. Webinars post-event action should dispatch transactional follow-ups with:
       dispatch_key = webinar_ended
       purpose = transactional
       scope = webinar

2. Webinars should emit automation events after recording domain state:
       webinar.attended
       webinar.missed
       webinar.ended

3. FlowRoutes should start event-triggered routes from contact-scoped automation events:
       webinar.attended -> webinar_attended_nurture campaign
       webinar.missed -> webinar_missed_nurture campaign

4. Campaigns should schedule nurture steps through Messaging public actions.

5. Campaign message templates should resolve from Messaging using:
       messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}

6. Campaign preset steps should store template references as first-class fields:
       channel
       purpose
       scope

   New configs should not use `meta.message` for CampaignStep template references.

7. Campaign step timing may be authored in days/hours/minutes but must normalize before Messaging dispatch.

8. Messaging should own the canonical delivery template shape, payload/copy, and delivery gating.

9. Registration consent should clearly distinguish:
       transactional:webinar
       marketing:webinar_nurture

10. Mortgage-specific long-term nurture should use:
       marketing:mortgage_homebuyer_nurture

Do not collapse `webinar_nurture` into `webinar` unless the product decision is that all webinar-related marketing uses a single broad consent scope.