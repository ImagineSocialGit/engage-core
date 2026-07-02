# Engage Core Module Boundaries

Engage Core is a modular contact engagement platform.

The goal is to let each client enable only the capabilities they need without forcing every client into CRM, sales, webinar, marketing, internal notifications, automation, or vertical-specific workflows.

This document defines module ownership, dependency direction, and the architectural rules that should guide future implementation. Actionable implementation backlog belongs in TODO.md, not this file.

## Product Capability Barometer

Module boundaries should preserve a simple product standard:

```text
If a client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

Instead, it should be developer/operator work, automated, preconfigured, preset-driven, hidden behind a simpler action, or split into a guided workflow that only asks the client for business decisions they are qualified to make.

This barometer applies to feature/module capability decisions.

Good client-facing capabilities are action-oriented and fast:

```text
Draft a Broadcast message.
Select who receives it.
Schedule an appointment for a person on a known day.
Send an existing form.
Review a submission.
Request documents.
Mark a task complete.
```

Developer/operator-facing capabilities may be more complex because they encode the reusable system:

```text
Build a form.
Design a Campaign.
Configure a FlowRoute.
Define document requirements.
Map vertical-specific answers.
Wire integrations.
Create client-specific presets.
```

This does not mean Engage Core avoids powerful modules. It means powerful modules should expose simple runtime actions to clients and keep system design work with the developer/operator.

Use `product-principles.md` for the fuller product posture.

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

During pre-rollout branch work, replace current branch migrations when a table shape changes instead of adding modify-table migrations. Once a migration has shipped to a real environment that must be preserved, use normal append-only migrations.

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

SMS code may exist even when SMS UI is hidden. SMS provider integrations, consent handling, STOP/HELP behavior, and runtime gates may remain available while config hides SMS options from Broadcast, Campaign, permission-invitation, or other client/admin builders.

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
| contact_import_batches | Core |
| contact_tags | Core |
| notes | Core |
| bookable_services | Scheduling |
| scheduling_availability_windows | Scheduling |
| appointments | Scheduling |
| appointment_attendees | Scheduling |
| portal_users | Portal |
| portal_contact_links | Portal |
| portal_invitations | Portal |
| portal_access_grants | Portal |
| form_definitions | Forms |
| form_versions | Forms |
| form_submissions | Forms |
| form_submission_values | Forms |
| document_requirement_definitions | Documents |
| document_requests | Documents |
| document_uploads | Documents |
| document_review_events | Documents |
| commerce_customers | Commerce |
| commerce_products | Commerce |
| commerce_orders | Commerce |
| commerce_order_items | Commerce |
| commerce_order_events | Commerce |
| locations | Location |
| contact_locations | Location |
| location_areas | Location |
| location_area_assignments | Location |
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
| contact_permission_invitations | Messaging |
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
- contact_import_batches
- contact_tags
- notes

App-global schema:

- users
- cache
- jobs

Everything else belongs to a first-party module, vertical module, or app-global infrastructure.

A table should not move ownership after client rollout unless there is a clear architectural mistake.

## Module Tiers

Engage Core should be organized in four layers:

1. Core
2. Universal modules
3. Vertical modules
4. Integrations/adapters

Core is the minimal identity/contact foundation. It should almost never change unless a new universal capability requires a genuinely generic Core seam. When Core does change, prefer adding a module-neutral extension point, contract, or registry rather than storing new domain state on Core models.

Universal modules are reusable capability modules. They may not be enabled for every client, but they are not tied to one business vertical. Universal modules own generic capabilities such as messaging, tasks, scheduling, forms, documents, portal access, commerce, webinars, reporting, and automation.

Vertical modules compose Core and universal modules into a business-specific product. Vertical modules own domain language, domain records, vertical-specific workflow meaning, and vertical-specific integrations or mappings.

Integrations/adapters connect modules to external providers. They are not modules. They live behind module-owned contracts, managers, services, or provider abstractions.

Decision rule:

    Core = required identity/contact foundation.
    Universal module = reusable capability many verticals can use.
    Vertical module = business-domain-specific concepts/rules/language.
    Integration = external provider adapter behind the owning module.

## Current Module Layout

Primary application modules live under:

    app/Modules

Current Core module:

- `Core`

Current universal modules include:

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
- `Scheduling`
- `Portal`
- `Forms`
- `Documents`
- `Commerce`
- `Location`

Planned universal modules include:

Current vertical modules include:

- `Mortgage`

Planned vertical modules include:

- `PetServices`
- `Music`

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

## How to Add a Universal Module

Use this process when adding a reusable capability module such as Scheduling, Portal, Forms, Documents, Commerce, or Location.

The goal is to establish durable ownership and dependency direction without forcing Core to understand the new module or adding speculative vertical behavior.

### 1. Classify the module

Confirm the capability is truly universal rather than Core, vertical, or integration code.

A universal module should be reusable across multiple verticals and should own a capability rather than a business-specific meaning.

Examples:

```text
Scheduling = universal appointment/booking capability.
Portal = universal external/customer account capability.
Forms = universal configurable form/submission capability.
Documents = universal document request/upload/review capability.
Commerce = universal normalized product/order/purchase capability.
Location = universal geographic/address/radius capability.
```

Do not create a universal module when the behavior is only vertical-specific. Vertical-specific interpretation belongs to a vertical module.

### 2. Decide ownership before schema

Before adding migrations, write down:

```text
- tables the module owns
- models the module owns
- public actions/services/contracts the module will expose
- modules it may depend on
- modules that may consume it later
- Core seams it needs, if any
```

Prefer module-owned tables linked to Contact over new Core columns.

Good:

```text
Scheduling owns appointments linked to contacts.
Documents owns document requests linked to contacts or other subjects.
Commerce owns orders linked to contacts.
Location owns contact locations linked to contacts.
```

Bad:

```text
contacts.appointment_status
contacts.portal_account_state
contacts.latest_form_submission
contacts.document_review_status
contacts.purchased_product_ids
contacts.latitude / contacts.longitude by default
```

### 3. Add the module shell

Create the standard module directories, even if some are initially empty:

```text
app/Modules/{ModuleName}/Actions
app/Modules/{ModuleName}/Contracts
app/Modules/{ModuleName}/Controllers
app/Modules/{ModuleName}/Data
app/Modules/{ModuleName}/Models
app/Modules/{ModuleName}/Providers
app/Modules/{ModuleName}/Requests
app/Modules/{ModuleName}/Services
app/Modules/{ModuleName}/Support
```

Create a module service provider:

```text
app/Modules/{ModuleName}/Providers/{ModuleName}ModuleServiceProvider.php
```

The provider should be safe to load when the module is installed but not visible. Avoid registering routes, navigation, jobs, or UI unless the feature is intentionally enabled.

### 4. Register the module in `config/modules.php`

Add the module with:

```text
name
enabled
provider
depends_on
```

Keep dependencies one-way and minimal. Use explicit module enablement for feature visibility. Provider loading for dependencies must not accidentally expose UI.

Typical planned universal dependencies:

```text
Scheduling -> Core
Portal -> Core, optionally Messaging
Forms -> Core when contact-linked
Documents -> Core when contact-linked
Commerce -> Core
Location -> Core
```

Optional integrations with Messaging, Tasks, InternalNotifications, Portal, Campaigns, Broadcasts, FlowRoutes, Reporting, or adapters should go through public services/contracts/events, not direct writes into private internals.

### 5. Add migrations only for durable ownership

Add migrations when the ownership is clear enough that future UI/workflows are unlikely to invalidate the table.

Use boring, generic fields first:

```text
id
contact_id nullable where contact-linked
subject_type / subject_id where the record may relate to multiple module subjects
status
source
provider nullable
external_id nullable
starts_at / ends_at / occurred_at / submitted_at where obvious
meta json
timestamps
```

Avoid speculative columns that encode vertical meaning, unfinished UI assumptions, or provider-specific details. Put uncertain details in `meta` until the runtime behavior deserves first-class fields.

During pre-rollout branch work, replace current branch migrations when table shapes change instead of adding modify-table migrations. After rollout, use append-only migrations.

### 6. Add models/factories/tests with ownership assertions

For each new table, add:

```text
model
factory when tests need records
focused model/schema test
boundary test if dependency direction could regress
```

Schema tests should verify durable fields and relationships, not UI behavior.

Boundary tests should protect:

```text
Core does not import the new module.
The new module does not import higher-level or unrelated modules.
Consumers use public actions/services/contracts/events.
Feature visibility follows explicit module enablement.
```

### 7. Add public seams before consumers depend on internals

If another module needs this module, expose a public seam first:

```text
action
service
contract
DTO/data object
event
registry/provider extension point
read/query service
```

Good:

```text
FlowRoutes -> Scheduling public action
Documents -> Tasks public CreateTaskAction
Commerce -> AutomationEventRecorded(commerce.order_created)
Broadcasts -> Core contact filter seam
```

Bad:

```text
FlowRoutes creates appointment rows directly.
Documents mutates task internals directly.
Music imports Shopify adapter directly for purchase history.
Core imports module models for contact pages.
```

### 8. Keep UI, provider adapters, and vertical meaning separate

Adding a module foundation does not require adding full UI, provider sync, or vertical behavior.

Module foundation may include:

```text
provider
config/modules entry
models
migrations
factories
public actions/services/contracts
boundary/schema tests
```

It should not automatically include:

```text
admin builders
portal screens
provider sync engines
full customer-facing UI
vertical-specific fields
vertical-specific workflow decisions
```

Vertical modules may later interpret or extend universal module records through their own tables, configs, presets, and public seams.

### 9. Update docs and tree after the slice

After adding a module foundation, update only the durable docs that changed:

```text
docs/module-boundaries.md for ownership/dependency/process changes
docs/project-organization.md for module classification changes
docs/TODO.md for remaining implementation backlog
core-project-tree.txt after regenerating from the repo
```

Do not turn `module-boundaries.md` into a backlog. Actionable implementation steps belong in `TODO.md`.

### 10. Run focused tests

Run focused tests for the touched module plus boundary/module tests. When Core seams or contact filters are involved, also run Core contact-filter tests.

Example:

```bash
php artisan test tests/Feature/Modules tests/Feature/Core
```

Adjust the command to the actual test locations added by the slice.

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
- Scheduling -> Core
- Scheduling may optionally use Messaging, Tasks, InternalNotifications, Portal, and Integrations through public services/contracts when those modules are enabled
- Portal -> Core
- Portal may optionally use Messaging for account invitations/notifications
- Forms -> Core, when submissions are contact-linked
- Forms may optionally use Portal for customer-submitted forms
- Documents -> Core, when documents are contact-linked
- Documents may optionally use Portal, Tasks, and Messaging through public services/contracts when those modules are enabled
- Commerce -> Core, when commerce customers/orders are contact-linked
- Commerce may optionally use Messaging, Broadcasts, Campaigns, FlowRoutes, Portal, and Reporting through public services/contracts when those modules are enabled
- Commerce may use Integrations through provider contracts/managers such as a Shopify adapter
- Location -> Core
- PetServices may consume Core, Scheduling, Portal, Forms, Documents, Tasks, Messaging, Campaigns, FlowRoutes, Reporting, and Integrations as needed
- Music may consume Core, Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Reporting, Scheduling, Portal, and Integrations as needed
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
- Core -> Scheduling
- Core -> Portal
- Core -> Forms
- Core -> Documents
- Core -> Commerce
- Core -> Location
- Core -> PetServices
- Core -> Music
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
    consent_policy
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
- contact import batches
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

Core owns generic contact lookup behavior used by CRM modules.

Generic contact lookup may search by:

- name
- first name
- last name
- email
- phone
- explicit contact IDs

Core contact lookup should return compact contact option payloads suitable for reusable CRM components such as contact pickers.

Core contact lookup should remain module-neutral.

It must not know why another module is selecting contacts.

Good:

    ContactLookupController
    route('crm.contacts.lookup')
    <x-crm.contact-picker />

Bad:

    BroadcastRecipientContactSearchController
    TaskSpecificContactLookupController
    WebinarSpecificContactPicker

Core owns the generic contact filter resolver used by modules for stable Contact-owned filter facts.

That resolver should understand stable Core-owned contact facts such as contact IDs, tags, imported/source fields, statuses, and generic timestamps.

Modules may consume resolved contact sets, but Core should not absorb module-specific business rules by default.

Future module-specific contact filters should be contributed through explicit provider/registry seams rather than hard-coded into Core.

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
- contact permission invitations
- imported-contact one-time opt-in invitation records
- public preference confirmation pages for Messaging consent
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

### Messaging channel availability

Messaging owns the canonical channel availability seam.

Channel availability answers whether a channel is:

- runtime-supported
- provider-enabled
- visible for a specific client/admin surface
- allowed for a specific purpose/scope
- explicit-opt-in only

Client/admin surfaces should not read raw SMS/provider config directly.

Surfaces such as Broadcasts, Campaign builders, webinar registration, permission invitation pages, internal notifications, and Route send-message points should ask Messaging’s channel availability service which channels are available for that surface.

Canonical channel availability surface keys are:

- `broadcasts`
- `campaigns`
- `permission_invitations`
- `webinar_registrations`
- `webinar_waitlists`
- `internal_notifications`
- `route_send_message_points`

Surface keys describe UI/admin/client channel-choice surfaces.

They should not replace singular Messaging scopes, sources, message types, consent policy keys, token contexts, or payload/context keys.

Hiding SMS from a surface does not disable SMS runtime safety behavior.

SMS provider integrations, consent gates, revocations, suppressions, STOP/HELP handling, and send guards remain backend/runtime concerns.

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

Scheduled message domain context lives in `scheduled_messages.context_type` / `scheduled_messages.context_id`.

Meaning:

```text
recipient_type / recipient_id
    Who receives the scheduled message.

context_type / context_id
    What domain record the scheduled message is about or attached to.
```

Examples:

```text
Appointment reminder
    recipient = Contact
    context = Appointment

Document request reminder
    recipient = Contact
    context = DocumentRequest

Webinar reminder
    recipient = Contact
    context = WebinarRegistration or Webinar

Campaign step message
    recipient = Contact
    context = CampaignEnrollment

Task digest
    recipient = TeamMember
    context = TaskDigest or null batch context
```

Do not add separate `subject_type` / `subject_id` columns to `scheduled_messages` unless there is a deliberate future decision to replace the existing `context` morph. The existing `context` morph is the canonical scheduled-message "about this record" relationship.

Messaging may schedule messages for non-Contact recipients through recipient payload/gate extension points, but it should not own those recipient models or their preferences.


### Imported-contact permission invitations

Messaging owns the one-time imported-contact permission invitation capability.

This includes:

- `contact_permission_invitations`
- invitation token generation
- one-time invitation enforcement
- public preference confirmation route/controller
- public preference page consent recording
- accepted channel tracking
- runtime injection of preference URLs into invitation email payloads

The invitation is not a general consent bypass.

Rules:

- The bypass applies only to the canonical imported-contact permission invitation message.
- The invitation send is email-only.
- The recipient must be an imported Contact.
- The invitation must use `message_type = imported_contact_permission_invitation`.
- The invitation must carry a `consent_policy.permission_invitation` payload with `source = imported_contact` and `one_time = true`.
- The system must refuse repeat invitations once a `contact_permission_invitations` row exists for the same contact/channel/source.
- Accepted public preferences create normal Messaging `MessageConsent` records for configured scopes.
- SMS consent must be explicitly selected by the contact and must not be inferred from email consent.

Other modules may request this flow through Messaging public services/actions, but they must not create invitation records directly.

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

## Broadcasts Module

Broadcasts is optional.

Broadcasts owns one-time and batch recipient sends.

Broadcasts owns:

- broadcasts
- broadcast recipients
- broadcast recipient filter metadata
- broadcast recipient state
- ad hoc one-time message payload/copy
- broadcast scheduling/orchestration behavior
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

Broadcasts are one-time or batch sends to recipients.

Broadcasts may store ad hoc payload/copy because broadcasts are not reusable Campaign journeys.

Messaging owns reusable delivery infrastructure, scheduled messages, consent, suppression, gates, payload classes, queues, and send jobs.

Broadcasts may depend on:

- Core
- Messaging

Broadcast recipient selection should use recipient-oriented terminology.

Use:

    recipient_filter
    BroadcastRecipientResolver
    BroadcastRecipient
    recipients

Avoid:

    audience
    audience_filter
    BroadcastAudienceResolver

`broadcasts.recipient_filter` is the canonical storage for recipient selection metadata.

Current supported recipient filter shapes include:

    {
      "type": "all"
    }

    {
      "type": "tag",
      "tags": ["homebuyer"]
    }

    {
      "type": "contact_ids",
      "contact_ids": [1, 2, 3]
    }

    {
      "type": "imported"
    }

    {
      "type": "import_batch",
      "import_batch_ids": [1, 2, 3]
    }

`imported` is a Core-owned contact filter for contacts imported from another system. Current imported detection includes `source = import`, `meta.imported = true`, `meta.imported_at`, or a present `contact_import_batch_id`.

`import_batch` is a Core-owned contact filter for contacts from specific first-class `contact_import_batches` records. Use it when the operator needs to target one exact import file/run instead of all imported contacts.

Recipient filters may also include Broadcast-owned exclusions:

    {
      "type": "all",
      "exclude": {
        "broadcast_ids": [12, 13],
        "statuses": ["scheduled", "sent"]
      }
    }

`exclude.broadcast_ids` removes contacts that already have matching `BroadcastRecipient` rows for those prior Broadcasts.

`exclude.statuses` currently supports:

    scheduled
    sent

This is Broadcast-owned duplicate-send protection. It lets operators send separate single-channel Broadcasts, such as SMS first and email second, without hitting the same contact twice.

Core does not own prior-Broadcast exclusion logic.

Messaging does not own prior-Broadcast exclusion logic.

Broadcasts owns this because it is based on BroadcastRecipient bookkeeping.

Broadcasts may store recipient filter definitions, but Core owns generic contact lookup and generic contact filter resolution.

Broadcasts should not become the app-wide contact query engine.

Broadcasts may use Core-owned contact lookup/picker functionality for individual contact selection.

Good:

    route('crm.contacts.lookup')
    <x-crm.contact-picker />
    BroadcastRecipientResolver

Bad:

    BroadcastRecipientContactSearchController
    Broadcast-specific contact picker components
    duplicated contact lookup logic inside Broadcasts

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

Current runtime direction:

    Broadcast UI/action creates or edits draft Broadcast
    Broadcast stores recipient_filter metadata
    Broadcast recipient resolver resolves Contacts from recipient_filter
    Broadcast schedule action creates BroadcastRecipients
    Broadcast schedule action calls Messaging public action/service
    Messaging creates ScheduledMessages
    BroadcastRecipient stores resulting scheduled_message_ids/status bookkeeping
    Broadcast listeners record sent/skipped/failed Messaging lifecycle events
    Broadcast completes when every BroadcastRecipient is terminal


### Broadcast opt-in invitations

Broadcasts may provide a UI entry point for the imported-contact opt-in invitation, but the permission-invitation rules are Messaging-owned.

A permission invitation Broadcast should:

- use `channel = email`
- use `purpose = transactional`
- use `scope = permission_invitation`
- use `dispatch_key = imported_contact_permission_invitation`
- use `message_type = imported_contact_permission_invitation`
- use `recipient_filter = {"type":"imported"}` or a narrower Core-owned imported-contact filter such as `{"type":"import_batch","import_batch_ids":[...]}`

A normal Broadcast must not receive the imported-contact bypass.

Normal Broadcasts remain consent-gated by Messaging.

Broadcast cancellation should use Messaging-owned skip behavior for pending scheduled messages rather than mutating Messaging internals directly.

Good:

    CancelBroadcastAction -> SkipScheduledMessagesAction

Broadcasts should remain simpler than Campaigns.

Broadcasts are single-channel sends.

A single Broadcast should represent one channel and one payload shape.

Examples:

    Email Broadcast -> channel=email, payload.subject, payload.body
    SMS Broadcast -> channel=sms, payload.message

Do not make a normal Broadcast default to both email and SMS.

Multi-channel fallback or “use SMS if available, otherwise email” is future channel-strategy work and should be modeled deliberately if needed.

For now, operators should create separate single-channel Broadcasts and use prior-Broadcast recipient exclusions to avoid duplicate outreach across channels.

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

## Scheduling Module

Scheduling is a current universal module.

Scheduling should own simple, intuitive appointment and booking behavior that can be reused by multiple verticals.

Scheduling may be used for:

- dog training sessions
- consultations
- coaching calls
- music lessons
- studio bookings
- internal or customer-facing appointments

Scheduling owns:

- bookable services
- appointment records
- appointment attendees
- availability windows
- scheduling rules
- cancellation and rescheduling rules
- appointment status/lifecycle behavior
- customer-facing booking pages, if generic enough
- appointment reminder orchestration through Messaging
- appointment-related task creation through Tasks

Scheduling should not own:

- pet-specific training goals
- dog behavior notes
- music-specific lesson curriculum
- mortgage-specific consultation outcomes
- external customer identity/auth
- file uploads or intake submissions

Scheduling may depend on:

- Core, for contact-linked appointments
- Messaging, for appointment reminders
- Tasks, for appointment-related manual work
- InternalNotifications, for team-facing schedule alerts
- Portal, for customer self-booking
- Integrations, for external calendar sync adapters if added later

Vertical modules may add domain-specific metadata or workflows around Scheduling records, but Scheduling should keep the core booking model vertical-neutral.

Good:

    PetServices -> Scheduling appointment for dog training session
    Music -> Scheduling appointment for lesson or studio booking
    Scheduling -> Messaging appointment reminder

Bad:

    Scheduling owns dog training plan state
    Scheduling owns fan purchase history
    Scheduling stores vertical-specific business rules directly on appointments

## Portal Module

Portal is a current universal module.

Portal should own external/customer account access. It is separate from internal app users.

Portal may be used for:

- customer self-booking
- customer intake forms
- document uploads
- account invitations
- self-service profile/preferences
- customer-facing dashboards

Portal owns:

- portal users or customer account identities
- contact-to-portal-user links
- portal invitations
- portal authentication and account lifecycle
- customer-facing access permissions
- generic portal dashboard shell

Portal should not own:

- internal team users
- Contact records
- appointment scheduling rules
- form definitions
- document review rules
- vertical-specific customer profile fields

Portal may depend on:

- Core, for linking portal users to contacts
- Messaging, for invitations and customer account notifications

Scheduling, Forms, Documents, Commerce, PetServices, Music, Mortgage, or other modules may expose portal-facing functionality through Portal extension points.

Good:

    Portal owns customer login
    Scheduling contributes customer booking UI
    Documents contributes customer upload UI

Bad:

    Core users table doubles as customer portal accounts
    Portal owns dog profiles or music purchases

## Forms Module

Forms is a current universal module.

Forms should own configurable forms, intake flows, submissions, and review state.

Forms may be used for:

- dog training intake forms
- mortgage lead/application intake
- music booking inquiries
- webinar questionnaires
- general client questionnaires

Forms owns:

- form definitions
- form versions
- form schemas
- form submissions
- submission values
- submission review state

Forms may later support optional mappings from submitted values into Contact or module-specific records through public actions/services.

Forms should not own:

- uploaded document storage/review, except simple form attachments if explicitly designed
- appointment booking
- vertical-specific profile meaning
- contact identity itself

Forms may depend on:

- Core, when submissions are contact-linked
- Portal, when customers submit forms from portal accounts
- Messaging, for form-submission notifications or confirmations if needed
- Tasks, for follow-up review tasks if needed

Vertical modules should own interpretation of vertical-specific answers.

Good:

    Forms stores submitted dog intake form answers
    PetServices interprets those answers into pet/training data

Bad:

    Forms owns pet behavior models
    Forms owns mortgage underwriting state

## Documents Module

Documents is a universal module.

Documents should own document requests, uploaded files, document review status, and document-related audit trails. Raw file storage remains infrastructure/provider behavior; Documents owns the domain records around requested or submitted files.

Documents may be used for:

- dog vaccination records
- waivers and agreements
- mortgage income/asset documents
- music contracts or assets
- general customer uploads

Documents should own, when implemented:

- document requests
- uploaded document records
- document categories/types, if generic
- document review events
- document status/lifecycle behavior
- links to related subjects through morphs where appropriate

Documents should not own:

- pet vaccination policy meaning, if domain-specific
- mortgage underwriting/doc collection state, if domain-specific
- customer authentication
- generic task assignment semantics

Documents may depend on:

- Core, when documents are contact-linked
- Portal, for customer uploads
- Tasks, for review/follow-up tasks
- Messaging, for document request/reminder messages

Vertical modules may define vertical-specific document types, requirements, and interpretation rules while Documents owns the reusable upload/request/review capability.

Good:

    Documents owns uploaded vaccination record file
    PetServices owns whether that vaccination record satisfies dog-training requirements

Bad:

    Documents owns full mortgage loan document underwriting state

## Commerce Module

Commerce is a planned universal module.

Commerce should own normalized purchase/order/product facts that can be used by multiple verticals.

Commerce may be used for:

- Shopify purchase history
- product-based contact filters
- fan/customer segmentation
- post-purchase campaigns
- purchase-triggered automation events

Commerce should own, when implemented:

- commerce products
- commerce orders
- commerce order items
- customer/contact links
- external commerce IDs and sync metadata
- normalized purchase events
- commerce provider sync state

Commerce should not own:

- Shopify adapter internals directly in module business logic
- music-specific fan/release strategy
- pet-service package fulfillment rules, unless modeled generically
- Contact identity itself

Commerce may depend on:

- Core, for linking purchases to contacts
- Messaging, Campaigns, or FlowRoutes through public actions/events when purchase behavior triggers communication or automation
- Integrations, through provider contracts/managers such as Shopify

Shopify should be an adapter behind Commerce, not the module itself.

Good:

    Shopify adapter -> Commerce normalized order records
    Music -> Commerce read service: has purchased product X
    Commerce -> AutomationEventRecorded(commerce.order_created)

Bad:

    Music imports Shopify adapter directly for general purchase lookup
    Core stores purchased product IDs on contacts

## Location Module

Location is a universal module.

Location owns reusable address, contact-location, geocoding-result, market, region, and service-area capability.

Location exists to make admin/client work easier, not to replace map providers, route optimizers, or GIS products.

Location owns:

- locations
- contact_locations
- location_areas
- location_area_assignments
- normalized address/location records
- contact-location links
- geocoding result metadata
- markets, regions, territories, zones, and service areas
- optional area assignment records

Location does not own:

- Core contacts
- Scheduling appointment lifecycle
- Portal accounts
- Messaging delivery
- Commerce orders
- Documents uploads
- vertical-specific territory strategy
- route optimization
- full GIS editing UX
- provider adapter internals

Do not add latitude, longitude, address, market, or service-area fields directly to `contacts` by default.

Location may depend on:

- Core, for contact-linked locations
- Integrations later, for geocoding/address-normalization providers behind Location-owned contracts/managers

Future consumers may use Location through public actions/services/contracts/events/read services or a future location-aware contact filter provider when a real workflow needs it.

Do not add the filter seam until a consuming workflow needs it, unless the future seam exposes a schema gap that must be fixed pre-rollout.

Good:

    Broadcasts asks a future Core/Location filter seam for contacts in a service area
    Scheduling asks Location whether a contact is inside a service area
    Music targets contacts near an upcoming show through Location-provided filters

Bad:

    Core contacts become the permanent home for every location/address use case by default
    Location owns route optimization or map rendering
    A vertical module stores general contact addresses in vertical-specific tables by default

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

## PetServices Module

PetServices is a planned vertical module.

PetServices should own pet-service and dog-training-specific business meaning.

PetServices may own, when implemented:

- pets/dogs
- pet profiles
- dog training programs
- training goals
- behavior notes
- trainer assignments, if domain-specific
- vaccination requirement rules, if domain-specific
- pet-service-specific workflow definitions
- pet-service-specific FlowRoute definitions
- pet-service-specific form/document templates and interpretation rules

PetServices may consume:

- Core
- Scheduling
- Portal
- Forms
- Documents
- Tasks
- Messaging
- Campaigns
- Broadcasts
- FlowRoutes
- Reporting
- Integrations

PetServices must not push pet-specific state into Core contacts.

Vertical-specific migrations should live in:

    database/migrations/verticals/pet-services

Good:

    PetServices owns DogProfile
    Scheduling owns appointment time/status
    Documents owns uploaded vaccination record
    PetServices decides whether that record satisfies a dog-training requirement

Bad:

    Core contacts get dog_name, breed, vaccination_status, or training_goal columns
    Scheduling owns dog behavior/training data

## Music Module

Music is a planned vertical module.

Music should own music-specific business meaning and fan/customer strategy.

Music may own, when implemented:

- artist/fan-specific profile data, if needed
- release campaign configuration/meaning
- music product interest categories
- fan segmentation rules that are music-specific
- show/event interest behavior that is not generic Scheduling or Location
- music-specific Commerce mappings, if generic Commerce records are not enough
- music-specific FlowRoute/Campaign presets

Music may consume:

- Core
- Commerce
- Messaging
- Campaigns
- Broadcasts
- FlowRoutes
- Scheduling
- Portal
- Location
- Reporting
- Integrations

Music must not push music-specific state into Core contacts.

Vertical-specific migrations should live in:

    database/migrations/verticals/music

Good:

    Commerce owns normalized Shopify orders
    Music decides what buying vinyl, merch, or tickets means for fan segmentation
    Location provides show-radius contact filtering when needed

Bad:

    Core contacts store purchased_shopify_product_ids
    Music imports Shopify adapter directly for generic order sync

## Adapters / Integrations

Adapters are not modules.

Examples:

- Resend powers email
- Telnyx/Twilio power SMS
- Zoom powers webinar behavior
- Shopify adapter powers Commerce
- External calendar adapters may power Scheduling sync later
- Geocoding/address providers may power Location later
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
    app/Integrations/Commerce/Shopify, later
    app/Integrations/Scheduling, later
    app/Integrations/Location, later

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
