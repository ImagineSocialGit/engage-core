# Engage Core Module Boundaries

Engage Core is a modular contact engagement platform.

The goal is to let each client enable only the capabilities they need without forcing every client into CRM, sales, webinar, marketing, internal notifications, automation, or vertical-specific workflows.

This document defines module ownership, dependency direction, and the architectural rules that should guide future implementation. Actionable implementation backlog belongs in TODO.md, not this file.

## Product Capability Barometer

Module boundaries should preserve the product standard defined in `product-principles.md`:

```text
If a client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

This matters architecturally because powerful modules should expose simple runtime actions to clients while keeping system design work in developer/operator-authored setup, presets, templates, public seams, and guided admin workflows.

Use `ui-ux-guide.md` for client/operator-facing language, screen patterns, and UI review standards.

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

Optional schema relationships are allowed when they make a future workflow simpler, but they do not automatically create feature visibility dependencies. For example, `appointments.location_id` may reference a saved Location record while Scheduling still depends only on Core for normal feature visibility. If Location is not enabled or visible, Scheduling can still use its freeform location fields.

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


## CRM orientation surfaces

The CRM dashboard and contact show page are shared orientation surfaces.

They may display module-owned information, but they must not become places where Core or the dashboard directly import module internals.

### Dashboard

The dashboard is app-level CRM UI.

Current durable direction:

```text
Dashboard layout is config-driven.
Modules contribute panels through a DashboardPanelProvider-style seam.
Dashboard slots and preset priorities decide what appears first.
Enabled module visibility controls whether a module contributes anything.
Actionable work panels may show calm empty states.
Passive context panels should hide when empty.
```

A table existing, provider being loadable, or a future module appearing in dashboard config does not make that panel visible. Visibility still follows explicit module enablement and provider registration.

### Contact show

Core owns the contact show shell and generic contact identity/details.

Modules contribute contact-specific data and UI through Core-owned extension points:

```text
ContactPanelProvider
ContactPanelRegistry
ContactShowDataProvider
ContactShowDataRegistry
```

Module-contributed contact sections should stay useful and business-facing. They should summarize what happened, what needs attention, what is already handled, and what the next safe action is.

Core must not import module models such as Task, ScheduledMessage, WebinarRegistration, CampaignEnrollment, ContactFlowRouteProgress, or TeamMember just to render a contact page.

## Runtime-Selectable Definitions

Preset/config sync should create or update available definitions.

Runtime behavior should be selected separately through DB-owned assignments or bindings.

This prevents the system from treating "whatever config was synced last" as the active client behavior.

Preferred shape:

```text
Config files define available options.
Sync imports or updates available DB-owned options.
CRM/admin selections assign the active option for a context.
Runtime resolvers read the selected DB-owned option.
```

This pattern applies to:

- FlowRoute trigger selection.
- Messaging template/message preset selection.
- Webinar confirmation, reminder, and post-event schedule selection.
- Campaign/channel strategy selection when campaign step variants are implemented.

Do not use destructive config swapping, temporary smoke-test keys, or broad route activation toggles as the long-term mechanism for choosing client runtime behavior.

### FlowRoute trigger bindings

FlowRoutes should use DB-owned trigger bindings to decide which route is selected for a trigger/context.

`FlowRoute.is_active` means the route is available and allowed to run.

A trigger binding means the route is selected for that trigger/context.

Contact-status triggers should normally have one selected FlowRoute binding per context.

Automation-event triggers may have multiple selected FlowRoute bindings per context when multiple independent actions should run from the same event. For example, `webinar.attended` may select one route that changes contact status and another route that enrolls the attended nurture Campaign.

Example:

```text
contact_status:prospect
    selected route = Prospect Sales Follow-Up

automation_event:webinar.attended
    selected routes = Attended Status Transition + Attended Nurture Enrollment
```

This intentionally supersedes the older interpretation where matching active FlowRoutes were the selected runtime behavior.

### Messaging template presets

Messaging should store reusable synced/editable message copy as DB-owned template presets.

Message template catalog entries should organize synced/editable templates for browsing and copy review. Catalog entries are read-organization records only; they do not own campaign timing, webinar schedules, FlowRoute trigger behavior, skip rules, or runtime selection.

Message template assignments should choose which preset is active for a channel/purpose/scope/surface/message context.

Campaigns, Webinars, and FlowRoutes should reference Messaging template keys or assignments. They should not embed reusable subject/body/message copy in their own presets.

### FlowRoute ownership

FlowRoutes may have an owner morph for operational ownership:

```text
owner_type nullable
owner_id nullable
owner_group nullable
```

Use `owner_group` for semantic grouping such as `sales`, `ops`, `compliance`, or `system`.

Do not use `responsible_party` for FlowRoute ownership. `responsible_party` is already a Task-owned concept meaning who or what must perform a manual task action.


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
| flow_route_trigger_bindings | FlowRoutes |
| points | FlowRoutes |
| flow_route_points | FlowRoutes |
| contact_flow_route_progress | FlowRoutes |
| contact_flow_route_plans | FlowRoutes |
| contact_flow_route_plan_items | FlowRoutes |
| contact_flow_route_progress_items | FlowRoutes |
| flow_route_capabilities | FlowRoutes |
| flow_route_capability_bindings | FlowRoutes |
| tasks | Tasks |
| task_templates | Tasks |
| message_consents | Messaging |
| consent_revocations | Messaging |
| scheduled_messages | Messaging |
| contact_permission_invitations | Messaging |
| message_suppressions | Messaging |
| message_template_presets | Messaging |
| message_template_catalog_entries | Messaging |
| message_template_preset_assignments | Messaging |
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

- None currently documented.

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

Each current universal module with a foundation doc should keep module-specific details in `docs/modules/{module}.md`. This document should keep only durable global rules, ownership freezes, and dependency direction.

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
- Scheduling may optionally use Messaging, Tasks, InternalNotifications, Portal, Location, and Integrations through public services/contracts when those modules are enabled
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

Core owns import batch records and import batch CRM visibility.

Core may expose module-owned actions on Core pages when the owning module is enabled. For example, the import batch detail page may show a Messaging-owned permission invitation action when Messaging is enabled. Core must not directly import Messaging actions, services, models, or scheduled-message internals to support that UI.

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

Messaging template presets are DB-owned reusable message definitions created from config and optionally edited through CRM/admin UI.

Messaging owns:

```text
message_template_presets
message_template_catalog_entries
message_template_preset_assignments
```

`MessageTemplatePreset` owns the reusable payload/copy.

`MessageTemplateCatalogEntry` owns Messaging template catalog/read organization for browsing grouped templates by channel, purpose, module/surface, group, and item. It does not own runtime behavior.

`MessageTemplatePresetAssignment` owns which preset is selected for a runtime message context, such as:

```text
channel + purpose + scope + surface + message_type
channel + purpose + scope + campaign_key + campaign_step
channel + purpose + scope + webinar schedule/reminder type
```

Runtime resolvers may read config during a transition period, but the target architecture is DB-first resolution from selected Messaging template assignments.


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

The `broadcasts` surface controls whether regular Broadcast authoring exposes SMS as a selectable channel. It does not make permission invitations SMS-capable, and it does not change runtime SMS consent, suppression, revocation, STOP/HELP, or send-guard behavior.

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
- import-batch permission invitation scheduling action
- import-batch permission invitation eligibility checks
- duplicate pending/sent scheduled invitation protection

The invitation is not a general consent bypass.

Rules:

- The bypass applies only to the canonical imported-contact permission invitation message.
- The invitation send is email-only.
- The recipient must be an imported Contact.
- The invitation must use `message_type = imported_contact_permission_invitation`.
- The invitation must carry a `consent_policy.permission_invitation` payload with `source = imported_contact` and `one_time = true`.
- The system must refuse repeat invitations once a `contact_permission_invitations` row exists for the same contact/channel/source.
- The system must also refuse repeat scheduling when a pending or sent imported-contact permission invitation scheduled message already exists for the contact.
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

A ContactStatus trigger should normally have one selected FlowRoute binding per context.

An automation event trigger may have multiple selected FlowRoute bindings per context when multiple independent actions should run from the same event, such as one route changing status and another route enrolling a Campaign.

`FlowRoute.is_active` means the route is available and allowed to run. It does not by itself mean every matching route should execute.

FlowRoute trigger bindings own runtime selection.

Automation-event-triggered FlowRoutes do not require `contact_status_id`.

Runtime meaning:

    ContactStatus may have one selected status-triggered FlowRoute binding per context
    Automation event keys may have one or more selected event-triggered FlowRoute bindings per context
    FlowRoute has many FlowRoutePoints
    FlowRoutePoint belongs to Point
    ContactFlowRouteProgress records active/waiting/completed/cancelled execution state

FlowRoutes runtime behavior should read DB-owned route/point definitions.

Preset config may create/update DB-owned FlowRoute definitions, but runtime execution should not depend directly on config definitions.

Manual contact-status changes may trigger selected status-based FlowRoutes. The CRM should warn the operator/client before applying a manual status change when a selected FlowRoute will run. This is a UI/awareness guardrail, not a ContactStatus schema distinction. Do not split ContactStatus into manual-only or automation-only categories unless a future workflow proves that policy is needed.

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


## FlowRoutes route instance and capability hardening

Before production, FlowRoutes should use first-class schema for route instances, route plans, route plan items, progress/execution items, and capability discovery instead of pushing these concepts into `meta`.

The durable layer split is:

```text
FlowRoute / FlowRoutePoint / Point
    Reusable template/default route definition.

ContactFlowRouteProgress
    Live route instance for one contact and optional subject.

ContactFlowRoutePlan
    Instance-specific plan seeded from the reusable route template.

ContactFlowRoutePlanItem
    Ordered item in one route instance plan. It may come from template, manual insertion, automation, vertical preset, or later operator adjustment.

ContactFlowRouteProgressItem
    Execution attempt/result for a route plan item. It owns waiting state, resume/correlation data, result data, and created-artifact linkage.

FlowRouteCapability / FlowRouteCapabilityBinding
    Durable capability catalog and context/client/module binding layer for available actions, waits, conditions, events, labels, input schema, output context, and supported subject types.
```

`contact_flow_route_progress` should support:

```text
subject_type nullable
subject_id nullable
```

Subject scoping lets one contact have separate live route instances for records such as appointments, document requests, form submissions, portal invitations, commerce orders, mortgage files, pets/dogs, or music-specific subjects.

A reusable route template may change over time. A live route instance should execute from its instance plan so template edits do not unexpectedly mutate active route paths. Operators may later insert, repeat, skip, cancel, delay, or replace plan items for one contact/subject without changing the reusable template.

FlowRoutes-created artifacts should use the same first-class provenance shape across modules:

```text
flow_route_progress_id
flow_route_plan_id
flow_route_plan_item_id
flow_route_progress_item_id
flow_route_id
flow_route_point_id
flow_route_capability_id
```

This applies first to:

```text
tasks
scheduled_messages
campaign_enrollments
```

Future modules should follow the same pattern when FlowRoutes creates module-owned records, such as:

```text
appointments
document_requests
form submissions or requests
portal invitations/access grants
commerce records when applicable
vertical-owned records
```

The owning module still owns its business rules and lifecycle. FlowRoutes stores provenance/correlation and calls the owning module through public actions/services/contracts.

Good:

```text
FlowRoutes create_task point → CreateTaskAction → Task with FlowRoutes provenance
FlowRoutes create_appointment point → Scheduling public action → Appointment with FlowRoutes provenance
FlowRoutes request_document point → Documents public action → DocumentRequest with FlowRoutes provenance
```

Bad:

```text
FlowRoutes writes directly to another module's private tables.
Future modules create one-off FlowRoutes metadata shapes instead of the shared provenance columns.
Producer modules import FlowRoutes to resume progress directly.
```

Task-completed resume should match a specific route progress/plan/progress item and task identity. Broad contact-only `task.completed` waits are unsafe when a contact may have multiple active tasks or multiple active subject-scoped route instances.

Capability records do not replace point handlers or public actions. They describe and bind what is available for authoring, validation, labels, supported subjects, and runtime compatibility. Runtime execution still goes through registered handlers and public module actions/services.

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

Campaign step variants are the planned shape for multi-channel campaign coordination.

A Campaign enrollment is the lifecycle.

A Campaign step is the business moment.

A Campaign step variant is a channel-specific delivery option for that moment.

Campaign step variants may use strategies such as:

```text
first_available
send_all_eligible
dependency_aware
```

Variants must reference Messaging-owned template presets or message template assignments. Variants must not own reusable payload copy.


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

Broadcast detail visibility should expose operator-useful recipient outcomes without making Broadcasts own delivery infrastructure:

```text
recipient_count
scheduled_count
sent_recipients_count
skipped_recipients_count
failed_recipients_count
broadcast_recipients.status
broadcast_recipients.skip_reason
broadcast_recipients.meta.delivery.failure_reason
scheduled_messages attached through context
```

Common skipped states include:

```text
broadcast_channel_unavailable
not_scheduled_by_messaging
```

`broadcast_channel_unavailable` means the channel is not visible/allowed for the Broadcast surface at schedule time.

`not_scheduled_by_messaging` means Messaging declined to schedule a recipient, such as when an SMS Broadcast recipient has no usable phone destination or Messaging planning gates reject the recipient.

Broadcasts may display these outcomes for operator troubleshooting, but Messaging still owns the scheduled delivery lifecycle and final send-time gates.

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

Webinar reminder, confirmation, and post-event schedules may become selectable DB-owned schedule profiles.

Webinars should own when webinar lifecycle messages are scheduled.

Messaging should own what those messages say through Messaging template presets and assignments.

A webinar series or webinar may later select schedule profiles for:

```text
registration confirmations
reminders
post-event transactional follow-ups
```

Those profiles should reference Messaging template assignments instead of embedding reusable copy.


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

## Current universal module docs

Detailed foundation docs for the current universal modules live in:

```text
docs/modules/scheduling.md
docs/modules/portal.md
docs/modules/forms.md
docs/modules/documents.md
docs/modules/commerce.md
docs/modules/location.md
```

This file should not duplicate those module-specific docs. Keep module-specific schema notes, FOSS feature-shape notes, table notes, deferred work, and open questions in the module docs.

Global rules from those modules that belong here:

- Core stays minimal; do not add appointment, portal, form, document, commerce, or location state to `contacts`.
- Universal module tables may exist in every install even when the feature is not enabled or visible.
- Optional schema relationships between universal modules do not automatically change `config/modules.php` feature visibility dependencies.
- Public seams should be added before a consumer directly mutates another module's internals.
- Do not add public seams, filters, builders, provider adapters, or vertical behavior until a concrete workflow needs them, unless a schema gap must be fixed pre-rollout.
- Scheduling can optionally reference saved Location records for appointments, while still using freeform location fields when Location is not enabled.
- Commerce is purchase-history/intelligence first, not a storefront, checkout, payment, fulfillment, or inventory engine.
- Location is address/location intelligence first, not GIS, route optimization, or map-provider replacement.

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
    Core-owned models defining hardcoded Messaging relationships
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
- Dashboard panel visibility respects explicit module enablement and preset/slot config.
- Contact show module visibility respects enabled modules.
- Producer modules do not import FlowRoutes.
- `FlowRouteExternalEvent::make(...)` is only called from FlowRoutes-owned code.
- FlowRoutes does not listen directly to producer-specific events such as TaskCompleted or Webinar-specific outcomes.
- Automation-worthy producer outcomes use `AutomationEventRecorded`.

When a boundary test fails, prefer improving the architecture over whitelisting the violation.

A whitelist should only be used for a deliberate, documented exception.

## Current Implementation Direction

The current architecture has completed the universal schema foundation run for:

```text
Scheduling
Portal
Forms
Documents
Commerce
Location
```

The next stage should keep the platform moving toward client rollout without adding speculative module internals.

Recommended direction:

1. Keep module-specific docs in `docs/modules/{module}.md` and keep this file focused on global architecture.
2. Keep config templates aligned with the canonical Messaging/Campaign/FlowRoute/Preset shapes.
3. Add public seams only when a concrete consumer/workflow needs them.
4. Add contact filters for Commerce or Location only when Broadcasts, Campaigns, Reporting, or another consuming surface needs them.
5. Treat the dashboard and contact show page as shared orientation surfaces with module-contributed summaries, not module inventories.
6. Continue the runtime-selectable setup-surface path with Webinars message/template/schedule setup first.
7. After the Webinars setup slice, improve Automatic Follow-ups / FlowRoutes UX around business-language selection, consequence previews, and diagnostic detail.
8. Continue the remaining client-readiness path with permission-invitation accepted-event decisions and config validation guidance.
9. Regenerate `core-project-tree.txt` from the repo after each structural batch.

Do not use this section as a backlog. Actionable items belong in `TODO.md`.


## FlowRoutes capability and producer boundary standard

FlowRoutes is the automation/control-flow consumer. Producer modules should not import FlowRoutes, and FlowRoutes should not import producer-module private internals.

Producer modules own their domain state first. Automation-worthy producer outcomes should emit neutral `AutomationEventRecorded` events after the owning module records its own state.

Good producer direction:

```text
Webinars records attendance → emits AutomationEventRecorded(webinar.attended)
Tasks records completion → emits AutomationEventRecorded(task.completed)
Documents records upload/review state → emits AutomationEventRecorded(document.uploaded/document.approved/etc.)
Scheduling records appointment state → emits AutomationEventRecorded(appointment.completed/etc.)
```

Bad producer direction:

```text
Webinars imports FlowRoutes
Tasks completion listener lives inside FlowRoutes
Documents directly creates FlowRouteExternalEvent
Scheduling directly mutates contact_flow_route_progress
```

FlowRoutes may react to generic automation events internally by:

```text
AutomationEventRecorded
→ FlowRoutes listener
→ FlowRouteExternalEvent mapping/internal DTO
→ selected route start or event_wait resume
```

FlowRoutes may call other modules only through public actions/services/contracts, such as:

```text
CreateTaskAction
DispatchMessageAction
TransitionContactWorkflowStatusAction
EnrollContactInCampaignAction
CancelCampaignEnrollmentAction
```

FlowRoutes must not directly create another module's private records when a public action/service exists.

Good FlowRoutes point direction:

```text
create_task point → Tasks public task creation action
send_message point → Messaging public dispatch action
change_status point → Workflow public transition action
enroll_campaign point → Campaigns public enrollment action
cancel_campaign point → Campaigns public cancellation action
```

Bad FlowRoutes point direction:

```text
Task::create(...)
ScheduledMessage::create(...)
CampaignEnrollment::create(...)
ContactWorkflowProfile::update(...)
```

## FlowRoutes capability catalog standard

FlowRoutes should have DB-owned capability and capability binding records before production.

The capability catalog exists because route authoring and validation must eventually be dynamic across universal and vertical modules. Operators should be able to see which actions/events/conditions/waits are available for a client, a module set, and a subject type without FlowRoutes importing private module internals.

The runtime path remains:

```text
FlowRoutes capability
→ point type / handler registry
→ public action/service/contract in the consuming module
```

Capability records should describe:

```text
module_key
capability key
capability kind: trigger/event/action/wait/condition/branch
point_type / handler key
label and help text
supported subject types
required modules
input schema
output context / available fields
default definition/settings
client/operator visibility
active/customized/source metadata
```

Capability binding records should decide availability/visibility for a client, module, owner group, context, or vertical.

Vertical modules such as PetServices, Music, and Mortgage may contribute capabilities, route presets, task templates, labels, public actions/services/contracts, event producers, and subject records through public seams. They should not force FlowRoutes, Core, or Tasks to know vertical private internals.

Vertical modules such as PetServices, Music, and Mortgage may contribute:

```text
route presets
task templates
labels/copy/capability metadata
public actions/services/contracts
event producers
subject records through morphs
```

They should not force FlowRoutes, Core, or Tasks to know vertical internals.

## Route template vs route instance planning

A reusable FlowRoute definition is the route template/default automation plan.

A live contact/subject moving through that route is a route instance.

Before production, FlowRoutes needs an explicit schema decision for whether active route instances execute directly from mutable route templates or from contact/subject-specific plan snapshots.

The motivating case:

```text
PetServices installs a Dog Behavior Training Route template.
The route applies to a specific contact/dog combo.
The dog’s training takes longer than expected.
The operator needs to add more appointments or repeat a final behavior check.
That adjustment must affect only this contact/dog route instance, not the reusable template.
```

Durable concepts to audit:

```text
FlowRoute template/definition = reusable default automation plan.
ContactFlowRouteProgress / route instance = this contact/subject moving through that plan.
Route instance plan/adjustment = contact-specific or subject-specific modification without changing the template.
```

Possible names/concepts:

```text
FlowRoutePlan
FlowRoutePlanItem
ContactFlowRouteProgressItem
ContactFlowRouteProgressAdjustment
Route instance snapshot
```

Schema questions for the FlowRoutes audit:

```text
Should ContactFlowRouteProgress support subject_type / subject_id?
Should route execution snapshot route points into progress items when a route starts?
Should live route instances execute from the mutable template, or from a plan snapshot?
Should operators be able to insert/repeat/skip/cancel specific plan items for one contact/subject?
Should each plan item track source = template/manual/vertical/automation?
Should plan items store config_snapshot json so template changes do not unexpectedly mutate active instances?
Should event waits and task completion resume a specific plan item rather than only a raw route point?
Should appointments/tasks/messages created by route points attach back to the specific progress item?
```

The Phase 4A audit proved that route instance plan tables are required before production. Implement this schema before Phase 5 task-completed resume so runtime correlation does not become meta-heavy.

## Shared available-field/token registry direction

Many modules author reusable copy, task descriptions, instructions, route send-message points, or other text that may include dynamic fields.

The long-term source of truth for available fields should be provider/registry based rather than hardcoded separately in every UI.

Potential consumers:

```text
Messaging templates
Broadcast authoring
Campaign message templates
Webinar message setup
Task templates
FlowRoute send-message points
Forms confirmations
Document requests/reminders
Permission invitations
Vertical modules
```

The registry should preserve module ownership:

```text
Messaging owns universal Contact/recipient message fields.
Producer modules own their context-specific fields.
Vertical modules own vertical-specific subject fields.
Campaigns may pass start/enrollment context but should not invent producer tokens.
```

Good:

```text
Webinars contributes webinar_title and webinar_start_time for webinar message contexts.
Tasks contributes task_title and task_due_date for task notification contexts.
PetServices contributes pet_name only for pet-scoped contexts.
```

Bad:

```text
Core hardcodes every module and vertical token.
Messaging guesses provider/module fields that the runtime cannot supply.
Campaigns invents webinar URL fields without the enrollment caller supplying them.
```

Treat available-field validation as setup/config-validation work before every editor receives polished autocomplete.
