# Engage Core Module Boundaries

## Executable config and token contract ownership

`ConfigContractRegistry` and `TokenContractRegistry` are shared coordination seams. They do not
transfer domain ownership to Core or Support.

- Support owns neutral schema nodes, violations, definitions, provider interfaces, and registries.
- Each module owns and registers the config contracts for definitions it parses or syncs.
- The module that supplies a model or runtime payload owns its token source.
- The producer of a dispatch path owns the token context that states which sources are available.
- `MessageTemplateTokenValidator` is the shared Messaging validation consumer for authorable template tokens; config/setup validation, MessageTemplatePreset sync, and CRM template editing reuse it against the exact registered context.
- Computed fields require an explicit value provider; arbitrary `meta`, raw payloads, and secrets
  are not implicit token namespaces.
- Setup validation and future export must consume these registrations rather than copying field
  lists into app-level code.

Current registered config domains include foundational module/package definitions plus Core,
Tasks, Messaging, Campaigns, FlowRoutes, and Webinars definitions. See
[`configuration/config-contracts.md`](configuration/config-contracts.md) for the concrete registry and extension rules.

Engage Core is a modular contact engagement platform.

The goal is to let each client enable only the capabilities they need without forcing every client into CRM, sales, webinar, marketing, internal notifications, automation, or vertical-specific workflows.

This document defines module ownership, dependency direction, and the architectural rules that should guide future implementation. Actionable module backlog belongs in `docs/modules/<module>/TODO.md`; configuration backlog belongs in `docs/configuration/TODO.md`; root `docs/TODO.md` is reserved for work with no single owner.

## Product Capability Barometer

Module boundaries should preserve the product standard defined in `product-principles.md`:

```text
If a client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

This matters architecturally because powerful modules should expose simple runtime actions to clients while keeping system design work in developer/operator-authored setup, presets, templates, public seams, and guided admin workflows.

Use `ui-ux-guide.md` for client/operator-facing language, screen patterns, and UI review standards.


## Module Product Surfaces

Architectural ownership and product visibility are separate classifications.

Feature modules are either:

```text
loud
    a recognizable client/operator/public workflow

silent
    a supporting capability normally encountered through another workflow, a Contact panel, or shared settings
```

Silent does not mean optional, trivial, stateless, or unimportant. A silent module may own substantial schema and runtime behavior while intentionally having no standalone navigation or client-facing builder.

Core is the platform foundation rather than a normal feature-surface classification. Integrations/adapters are not modules and remain behind the owning module's product surface.

Use [`module-surfaces.md`](module-surfaces.md) for the complete classification registry, navigation rules, module-definition template, and the rule that FOSS/competitive audits are possibility inventories rather than requirements.

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

## Universal Internal Terminology

The universal internal person concept is `Contact`.

Internal/runtime identifiers must use `contact`, not `lead`, unless a vertical genuinely owns a distinct domain concept named lead. This rule applies to keys, preset definitions, task-template identifiers, route/point identifiers, event keys, triggers, registries, payload/context fields, and generic services/actions/DTOs.

Client-facing UI and copy may use a configured business noun such as Lead, Customer, Fan, Borrower, Owner, or Member. Presentation terminology must not redefine the underlying universal identifier.

Good:

```text
contact
call_contact
review_contact_notes
contact_follow_up_route
Client UI label: Lead
```

Avoid:

```text
new_lead
call_lead
review_lead_notes
lead_follow_up_route
```

Vertical modules may use vertical-specific nouns for real vertical-owned records and behaviors. They should not rename Core Contact inside generic config/runtime identifiers.

## Contact identity vs business relationships

Core `Contact` is the canonical person identity. Distinct business contexts for that person belong to the Relationships module rather than separate duplicate person records or an ever-growing set of Core Contact columns.

A Contact may participate in several relationships at once, for example consumer/customer plus collaborator/Realtor/partner.

Normal CRM operating views must be relationship-scoped. Materially different relationship populations should not be mixed into the same default Contact list merely because they share Core identity storage. A Contact with multiple relationships may appear in each relevant workspace. A mixed all-Contacts view is primarily an administrative/export/debug/identity-management surface.

Relationship-specific stage/source state belongs to the relationship context. Vertical modules may specialize a relationship through their own records, but should not duplicate generic relationship stage/classification fields merely to attach vertical data.

Preferred shape:

```text
Core Contact
    -> Relationships ContactRelationship
        -> optional vertical specialization
```

Not:

```text
separate Lead and Collaborator person tables for the same human
vertical profile duplicating generic relationship stage
one default CRM list mixing unrelated relationship populations
```

## Installed Schema vs Enabled Features

Shared platform, core, and reusable capability-module tables may exist in every install.

A table existing does not mean the feature is enabled.

Feature availability is controlled through:

Installed module definitions:
config/modules.php

Selected-client runtime module authority:
client/{CLIENT_KEY}/config/modules.php
-> config('modules.enabled')
-> ModuleManager

Provider loading may additionally include dependency modules without making those modules explicitly enabled.

Explicit enablement means the runtime capability is available to the selected client. It does not by itself require a top-level navigation item or standalone product workspace. Product visibility also depends on the module's loud/silent classification and deliberate surface contribution.

Do not put module-enabled conditionals inside normal shared migrations.

During pre-rollout branch work, replace current branch migrations when a table shape changes instead of adding modify-table migrations. Once a migration has shipped to a real environment that must be preserved, use normal append-only migrations.

The database can contain reusable capability tables even when a module is not explicitly available or independently visible to the current client.

Optional schema relationships are allowed when they make a real workflow simpler, but they do not automatically create navigation or standalone-surface dependencies. For example, Scheduling may optionally reference a saved Location record while keeping the entire address/travel experience inside Scheduling. Location remains a silent supporting module.

## Module Enabled vs Provider Loaded

There is an important distinction:

- `module_enabled('x')` means the capability is explicitly enabled for the client.
- Provider loading may include dependency modules needed by explicitly enabled modules.
- Product visibility is a separate deliberate decision governed by loud/silent surface mode and the actual surface contribution.

Example:

- If `inbound_messaging` depends on `messaging`, the Messaging provider may need to load.
- Explicitly enabling Messaging still does not require a primary Messaging sidebar item because Messaging is a silent module.
- Messaging templates or delivery settings may appear contextually or inside shared settings when their workflows require them.

Provider availability may include dependencies. Navigation must not be inferred mechanically from provider loading or the enabled-module list.

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

FlowRoutes also owns read-only consequence preview for manual ContactStatus changes.

Current backend seam:

```text
FlowRouteTriggerBindingResolver::selectedFlowRoutesForContactStatus(...)
ContactStatusAutomationImpactResolver::forContactStatus(...)
```

The preview returns compact selected-route impact data and must not mutate Contact/Workflow state or start FlowRoute progress.

Core and Workflow should not duplicate FlowRoute trigger-binding queries or import FlowRoutes internals to compute this impact. The eventual CRM warning UX should consume the FlowRoutes-owned read seam through an appropriate integration boundary.

No persisted acknowledgement/warning state is required unless a later operator workflow proves a durable audit need.

FlowRoute logical identity is versioned by stable `key` plus `version`.

`is_current_version` selects the logical revision; `is_active` determines whether that selected revision is enabled.

New starts use the current active revision. Active/waiting instances on older revisions reconcile to a newly current revision by durable FlowRoutePoint key, creating a new route-plan revision and preserving historical plans. Unmappable current/waiting points are hard reconciliation conflicts; runtime must not guess, silently skip, restart, or cancel them.

### Versioned Messaging templates, chains, and compact execution

Messaging's approved target separates stable identity, immutable authored behavior, runtime progression, and provider execution.

```text
MessageTemplate
    stable editable identity

MessageTemplateVersion
    immutable tokenized content and renderer identity

MessageChain
    stable reusable sequence identity

MessageChainVersion
    immutable timing/condition/exit behavior

MessageChainStep / MessageChainStepVariant
    ordered moments and channel-specific template-version references

MessageChainEnrollment
    one recipient progressing through one immutable chain version

ScheduledMessage
    one compact delivery execution

ScheduledMessageRenderContext
    lazily frozen token values only when rendering begins

ScheduledMessageDeliveryAttempt
    one provider claim/submission/outcome attempt
```

Config sync may seed templates and chains, but runtime selection is DB-owned through current versions and module-owned bindings.

Existing scheduled work never references mutable current definitions without pinning an immutable version.

A chain does not own its business trigger.

Examples:

```text
Webinars
    owns registration/waitlist/attended/missed -> MessageChain bindings

Campaigns
    owns Campaign identity and references a MessageChain

FlowRoutes
    may send one template or start one MessageChain

Broadcasts
    owns recipient selection and pins one private/reusable MessageTemplateVersion
```

Do not preserve the current preset/assignment/catalog/profile/step tables as parallel permanent engines merely because they exist today.

Target ownership:

```text
template copy
    Messaging template versions

sequence/timing/variant dependency
    Messaging chain versions

business trigger/binding
    consuming module or FlowRoutes

recipient progression
    Messaging chain enrollment

provider delivery
    Messaging scheduled message and attempts
```

High-volume runtime rows must be narrow.

The target `scheduled_messages` table has no payload or generic metadata JSON. It pins immutable template content by FK and stores small operational routing/status columns needed for hot queries.

Do not over-normalize small hot-path values merely to remove every repeated string. Channel, purpose, scope, message type, queue, and status may remain first-class on ScheduledMessage because gate/claim/queue queries use them directly.

Do not under-normalize large reusable content, conditions, labels, or provenance into every delivery row.

Logical occurrence identity remains separate from scheduled time. Retrying or recalculating one occurrence must not create a second delivery solely because `send_at` changed.

The complete target and anti-shuffling rules are defined in `docs/modules/messaging/persistence-architecture.md`.

### FlowRoute ownership

FlowRoutes may have an owner morph for operational ownership:

```text
owner_type nullable
owner_id nullable
owner_group nullable
```

Use `owner_group` for semantic grouping such as `sales`, `ops`, `compliance`, or `system`.

Do not use `responsible_party` for FlowRoute ownership. `responsible_party` is already a Task-owned concept meaning who or what must perform a manual task action.


## Selected preset contribution architecture

The module-first preset contribution architecture is implemented.

Current examples:

```text
config/presets/modules/core/contact-statuses.php
config/presets/modules/tasks/tasks.php

config/presets/modules/webinars/contact-statuses.php
config/presets/modules/webinars/tasks.php
config/presets/modules/webinars/campaigns.php
config/presets/modules/webinars/flow-routes.php

client/{client-key}/config/presets/modules/client/contact-statuses.php
```

The shared infrastructure is:

```text
PresetContributionRegistry
    aggregates explicitly registered contributor groups/definitions by preset domain

PresetPackageResolver
    resolves selected package and selected groups

PresetCompositionResolver
    produces ResolvedPresetDomain for one selected package/domain

Domain sync actions
    persist exactly the selected resolved composition
```

Keep separate:

Preset packages never declare or override runtime module availability. The selected client's `config/modules.php` is the sole authority.

```text
module availability
preset availability/contribution
client package selection
runtime activation/binding
```

Enabling a module must not automatically activate every preset it contributes.

Core should keep a small generic package surface. Rich vertical/client packages belong in `client/{client-key}/config/presets.php`. Any `client.preset` key must exist in the effective merged `presets.packages` configuration.

## DB-owned definition sync and customization contract

Config and preset files define reusable package-owned definitions. Sync actions materialize them into DB-owned records. Runtime should execute from DB state and selected DB-owned bindings/assignments rather than reading raw config as the only source of truth.

Current durable sync behavior:

```text
ContactStatus
    normal sync preserves customized rows
    force sync may overwrite and clear customization

TaskTemplate
    normal sync preserves customized rows
    force sync may overwrite and clear customization

MessageTemplatePreset
    normal sync preserves customized rows
    force sync may overwrite and clear customization
    stale config-owned non-customized presets are removed
    customized/manual presets are preserved

WebinarScheduleProfile / WebinarScheduleProfileItem
    normal sync preserves customized rows
    force sync may overwrite and clear customization
    stale non-customized items are deactivated
    stale customized items are preserved

Campaign / CampaignStep / CampaignStepVariant
    normal sync preserves customized rows
    stale non-customized nested steps/variants may be removed
    no force mode is currently supported

FlowRouteCapability
    normal sync preserves customized capability rows

FlowRoute / FlowRoutePoint
    normal sync preserves customized definitions according to route sync semantics
    explicit FlowRoute force behavior is supported
```

Do not assume every definition family has identical force semantics.

Do not add force mode merely for symmetry.

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
| flow_route_points | FlowRoutes |
| contact_flow_route_progress | FlowRoutes |
| contact_flow_route_plans | FlowRoutes |
| contact_flow_route_plan_items | FlowRoutes |
| contact_flow_route_progress_items | FlowRoutes |
| flow_route_capabilities | FlowRoutes |
| flow_route_capability_bindings | FlowRoutes |
| tasks | Tasks |
| task_links | Tasks |
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
| campaign_step_variants | Campaigns |
| campaign_enrollments | Campaigns |
| broadcasts | Broadcasts |
| broadcast_recipients | Broadcasts |
| webinar_series | Webinars |
| webinars | Webinars |
| webinar_registrations | Webinars |
| webinar_waitlist_signups | Webinars |
| webinar_schedule_profiles | Webinars |
| webinar_schedule_profile_items | Webinars |
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

Reserved ownership for approved but not-yet-created schema:

```text
events                         Events
event_external_references      Events
event_stakeholders             Events
event_attendances              Events
commerce_product_variants      Commerce
commerce_offers                Commerce
commerce_offer_variants        Commerce
Experience-owned tables        Experiences, after its schema is approved
```

Reserved ownership does not mean the tables currently exist. Add each table to the executable schema ownership and Project State coverage contracts in the same implementation workstream that creates it.

No new durable feature table may become operational while Project State still treats it as unclassified or must-be-empty. Credentials, secrets, ephemeral carts/checkouts, signed URLs, caches, and other reconstructible runtime artifacts should remain outside durable transfer.

## Module Tiers

Engage Core should be organized in four layers:

1. Core
2. Universal modules
3. Vertical modules
4. Integrations/adapters

Core is the minimal identity/contact foundation. It should almost never change unless a new universal capability requires a genuinely generic Core seam. When Core does change, prefer adding a module-neutral extension point, contract, or registry rather than storing new domain state on Core models.

Universal modules are reusable capability modules. They may not be enabled for every client, but they are not tied to one business vertical. Universal modules own generic capabilities such as messaging, tasks, scheduling, forms, documents, portal access, commerce, Events, webinars, reporting, and automation.

Vertical modules compose Core and universal modules into a business-specific product. Vertical modules own domain language, domain records, vertical-specific workflow meaning, and vertical-specific integrations or mappings.

Integrations/adapters connect modules to external providers. They are not modules. They live behind module-owned contracts, managers, services, or provider abstractions.

Architecture tier is independent from product surface mode. A universal or vertical module may be loud or silent. Core is the platform exception; integrations/adapters remain silent implementation details rather than modules.

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

- `Events`

Current vertical modules include:

- `Mortgage`

Planned vertical modules include:

- `PetServices`
- `Music`
- `Experiences`

Blade views intentionally remain under:

    resources/views

External provider adapters intentionally remain outside feature-module internals.

The long-term default for new third-party/vendor implementations is a separate private
Composer package/repository installed only for clients that need that provider.

Examples:

- `engage-integration-arive`
- `engage-integration-[commerce-provider]`
- future calendar, geocoding, payment, webinar, email, SMS, POS, inventory, or fulfillment-facing provider packages

Existing provider implementations under `app/Integrations/**` may remain in Engage Core
until there is a concrete reason to extract them. Do not move Resend, Telnyx, Twilio,
Zoom, or another existing adapter merely for directory symmetry.

Adapters are not modules. The owning module keeps provider-neutral contracts, managers,
resolvers, DTOs, domain state, and public outcomes. A provider package implements those
seams and registers itself through the shared integration-registration/bootstrap layer.

## How to Add a Universal Module

Use this process when adding a reusable capability module such as Scheduling, Portal, Forms, Documents, Commerce, Location, or Events.

The goal is to establish durable ownership and dependency direction without forcing Core to understand the new module or adding speculative vertical behavior.

Each current universal module with a foundation doc should keep module-specific details in `docs/modules/{module}/module_state.md`. This document should keep only durable global rules, ownership freezes, and dependency direction.

### 1. Classify the module

Confirm both classifications before implementation:

```text
architecture tier
    Core | universal | vertical | integration/adapter

product surface
    loud | silent
```

Confirm the capability is truly universal rather than Core, vertical, or integration code.

A universal module should be reusable across multiple verticals and should own a capability rather than a business-specific meaning.

Examples:

```text
Scheduling = universal appointment/booking capability.
Portal = universal external/customer account capability.
Forms = universal configurable form/submission capability.
Documents = universal document request/upload/review capability.
Commerce = universal catalog/storefront/offer/checkout-orchestration/purchase/inventory-effect capability.
Location = universal normalized location/address facts and optional geographic-provider capability.
Events = universal concrete-event catalog and reconciliation capability.
```

Examples of product-surface classification:

```text
Scheduling = loud universal module.
Broadcasts = loud universal module.
Location = silent universal module.
InternalNotifications = silent universal module.
```

Do not create a universal module when the behavior is only vertical-specific. Vertical-specific interpretation belongs to a vertical module. Do not create a standalone surface for a silent module merely because its schema or service provider exists.

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

Keep dependencies one-way and minimal. Use explicit module enablement for runtime capability availability. Product surfaces must follow the loud/silent classification and deliberate navigation/settings contributions. Provider loading for dependencies must not accidentally expose UI.

Typical planned universal dependencies:

```text
Scheduling -> Core
Portal -> Core, optionally Messaging
Forms -> Core when contact-linked
Documents -> Core when contact-linked
Commerce -> Core
Location -> Core
Events -> Core
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
Music imports a Commerce provider adapter directly for purchase history.
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
docs/modules/<module>/TODO.md for module-owned backlog (or docs/TODO.md only when no single module owns it)
core-project-tree.txt after regenerating from the repo
```

Do not turn `module-boundaries.md` into a backlog. Actionable module work belongs in `docs/modules/<module>/TODO.md`; use root `docs/TODO.md` only for truly platform-wide work.

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
- Tasks must remain operational with only Core enabled; optional InternalNotifications/TeamMember behavior must arrive through public extension seams when enabled
- Tasks must not depend structurally on FlowRoutes; FlowRoutes may consume Tasks through public actions and neutral events
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
- Commerce may optionally use Events through the Events public promotion gate for Event-linked offers
- Commerce may optionally use Messaging, Broadcasts, Campaigns, FlowRoutes, Portal, and Reporting through public services/contracts when those modules are enabled
- Commerce may use Integrations through provider contracts/role resolvers and may bind multiple provider packages to different commerce capabilities in the same client ecosystem
- Location -> Core
- Relationships -> Core
- Events -> Core
- Events remains independently usable and must not depend on FlowRoutes, Messaging, Campaigns, Broadcasts, Tasks, Music, Experiences, Commerce, Webinars, Reporting, or Location
- Experiences may consume Core, Events, and Commerce as foundation dependencies and may optionally consume Messaging, Tasks, FlowRoutes, InternalNotifications, Reporting, Location, and Integrations through public seams
- PetServices may consume Core, Scheduling, Portal, Forms, Documents, Tasks, Messaging, Campaigns, FlowRoutes, Reporting, and Integrations as needed
- Music may consume Core, Events, Commerce, Experiences, Messaging, Campaigns, Broadcasts, FlowRoutes, Tasks, Reporting, Scheduling, Portal, Location, and Integrations as needed
- Mortgage -> Relationships (and therefore Core transitively)
- Mortgage may optionally coordinate with Workflow, FlowRoutes, Tasks, Messaging, Campaigns, Broadcasts, Webinars, Reporting, Location, and Integrations only through public/shared seams when those capabilities are enabled
- Messaging may use Integrations through provider contracts/managers
- Webinars may use Integrations through provider contracts/managers
- Mortgage may use Integrations through provider contracts/managers

Avoid:

- Core -> feature modules
- Core -> Messaging
- Core -> InboundMessaging
- Core -> InternalNotifications
- Core -> Relationships
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
- Core -> Events
- Core -> Experiences
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

## Automation Opportunities shared infrastructure

Automation Opportunities are app-level shared infrastructure for noticing repeated meaningful work and suggesting automation without acting autonomously.

Detailed contract: `docs/automation-opportunities.md`.

Keep these concepts distinct:

```text
AutomationEventRecorded
    neutral domain/business outcome that automation may react to

AutomationBehaviorOccurrence
    compact intentionally recorded behavior or correlation evidence

AutomationOpportunity
    aggregate repeated pattern that may justify a suggestion

FlowRoutes
    owns accepted automation/control-flow execution
```

Most evaluated opportunity occurrences come from explicit manual human behavior. Some occurrences are evidence only and must not create or advance an opportunity by themselves.

Current evidence-only examples:

```text
task.completed_manually
automation_event.recorded
```

The current selected automation-event evidence allowlist is intentionally small:

```text
webinar.attended
webinar.missed
permission_invitation.accepted
inbound_message.normal_reply
task.completed
```

The allowlist may change as usefulness becomes clearer. Adding an event to evidence collection is not equivalent to declaring it suggestion-worthy.

Participating modules explicitly opt in. Do not implement generic clickstream tracking, global Eloquent observation, arbitrary request capture, or “record every event just in case.”

The owning producer module decides what makes two actions meaningfully equivalent and supplies compact fingerprint parts/context. Shared infrastructure normalizes/hashes fingerprints, persists occurrences, aggregates opportunities, and applies generic count/distinct-subject/window qualification.

The generic evaluator should not accumulate domain-specific branching for Tasks, Webinars, Messaging, InboundMessaging, or future modules.

Current default qualification:

```text
3 occurrences
3 distinct subjects
30-day observation window
```

Current implemented compound correlation uses a 10-minute window.

Shared opportunity infrastructure may reference stable automation capability keys. It should not canonically depend on FlowRoutes-owned database IDs merely to represent that a behavior could be automated.

Producer modules must not depend on FlowRoutes to record occurrences. FlowRoutes remains the destination for accepted automation where applicable.

A plain repeated manual Contact status change is not currently an opportunity producer. Current status-related opportunity patterns require additional causal context, such as:

```text
manual status change -> manual Task creation
manual Task completion -> manual status change
```

Current event-evidence correlation may retain selected neutral automation events and later correlate them to a manual Task for the same Contact. Evidence alone remains silent.

## Module-specific architecture

Detailed module responsibility, persistence, public seams, product behavior, Project State status, and deferred direction live in each module-owned state document:

```text
docs/modules/<module>/module_state.md
```

Actionable module work belongs in the optional local `TODO.md`. Do not duplicate module roadmaps or implementation backlogs here.

This file should retain only rules that apply across module boundaries: ownership direction, schema ownership, shared registries/contracts, module installation/enablement semantics, and cross-module interaction standards.

See `docs/modules/README.md` for the current module index and documentation standard.

## Adapters / Integrations

Adapters are not modules.

Examples:

- Resend powers email
- Telnyx/Twilio power SMS
- Zoom powers webinar behavior
- commerce provider packages may power catalog, pricing, promotions/discounts, checkout, order, POS, inventory, or reconciliation capabilities according to the client-configured provider roles
- External calendar adapters may power Scheduling sync later
- Geocoding/address providers may power Location later
- Arive may power mortgage LOS behavior later

Adapters must sit behind the owning module's provider-neutral contracts, managers,
resolvers, DTOs, public actions, or provider services.

The owning module is authoritative for business/domain meaning. A vendor adapter
translates provider-specific input/output into those neutral seams and must not become
the place where Contact, Workflow, FlowRoute, Task, Campaign, Commerce, Mortgage, or
other cross-module business behavior is hard-coded.

Commerce is explicitly allowed to compose multiple provider adapters at once. Do not force
catalog, pricing/promotion, checkout/payment, inventory, POS, order, and fulfillment roles
through one global provider selection when the client ecosystem assigns those roles differently.
The same provider may satisfy several roles when appropriate. When a provider is the configured
pricing or promotion authority, Engage Core may present and style that state but must not create
a competing price/discount truth or reimplement provider-owned eligibility/calculation rules.

Do not make a third-party middleware/integration SaaS a required architectural layer merely
to synchronize providers. When Engage Core already owns the relevant orchestration contract,
prefer direct provider packages so clients can keep the specialized platforms they need
without adopting another integration product.

### Package/repository default

Going forward, a new third-party/vendor integration should normally live in its own
private Composer package/repository and be installed only for clients that need it.

Preferred shape:

```text
Engage Core module
    owns provider-neutral contracts/domain behavior
            ^
            | implements/registers
            |
private provider package
    owns vendor API/webhook/parser/provider implementation
```

Illustrative package names:

```text
engage-integration-arive
engage-integration-[commerce-provider]
engage-integration-[payment-provider]
engage-integration-[pos-provider]
```

These are examples of the package pattern, not a required provider stack. A client may use
one provider for several roles or several providers concurrently.

The exact package naming convention may be finalized when the first package is created,
but vendor code should not be added to Engage Core merely because `app/Integrations/**`
currently exists.

The shared Integrations bootstrap/registration layer may discover or register installed
provider packages. It is infrastructure for composition, not the owner of vendor-specific
business logic.

A provider package should:

- declare compatible Engage Core versions;
- depend on the owning module's public contracts rather than private internals;
- register its implementations explicitly through supported service-provider/registry seams;
- keep credentials and deployment-specific secrets outside source-controlled package code;
- emit/return provider-neutral outcomes after authoritative provider work;
- remain absent from client deployments that do not use the provider.

Existing in-repository adapters are grandfathered:

```text
app/Integrations/Messaging/Email/Resend
app/Integrations/Messaging/Sms/Telnyx
app/Integrations/Messaging/Sms/Twilio
app/Integrations/Webinars/Zoom
```

They may be extracted later when there is real maintenance/deployment value. Do not
perform extraction merely for consistency.

New specialized integrations such as Arive and future Commerce providers should establish
the external package pattern rather than adding new long-lived vendor directories under
`app/Integrations/**`.

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
- Producer modules do not import FlowRoutes runtime internals, construct `FlowRouteExternalEvent`, or call FlowRoutes execution/resume actions directly.
- Intentional FlowRoutes provenance relationships on artifact models are allowed when a module owns route-created artifacts, such as `ScheduledMessage` and `CampaignEnrollment`.
- `FlowRouteExternalEvent::make(...)` is only called from FlowRoutes-owned code.
- FlowRoutes does not listen directly to producer-specific events such as TaskCompleted or Webinar-specific outcomes.
- Automation-worthy producer outcomes use `AutomationEventRecorded`.

When a boundary test fails, prefer improving the architecture over whitelisting the violation.

A whitelist should only be used for a deliberate, documented exception.

## Setup validation architecture standard

Config/setup validation is app-level orchestration over module-owned validation contributors. It should not become one monolithic service that imports every module's private config parser, models, and runtime internals.

Implemented shape:

```text
SetupValidationManager
    -> tagged setup.validation_contributors
        -> ModuleDependenciesSetupValidationContributor
        -> ReferenceRegistrySetupValidationContributor
        -> CoreSetupValidationContributor
        -> TasksSetupValidationContributor
        -> MessagingSetupValidationContributor
        -> WebinarsSetupValidationContributor
        -> CampaignsSetupValidationContributor
        -> FlowRoutesSetupValidationContributor
        -> future module/vertical contributors
    -> SetupValidationResult
    -> setup:validate CLI now
    -> future authoring/readiness UI later
```

Validation contributors should return one stable finding shape with machine-readable code and actionable context. At minimum, the shared contract should support:

```text
severity
code
message
source
path
module
context
compact meta when useful
```

Use two blocking levels initially:

```text
error
    Intended runtime behavior is invalid, impossible, ambiguous, or unsafe.
    Fails the validation command and blocks staging/client handoff.

warning
    Setup is safe but dormant, unused, unavailable by choice, discouraged, or surprising.
    Does not block handoff.
```

Do not persist validation findings by default. Add validation history/schema only when a concrete operator workflow needs retained runs, acknowledgements, audit history, or comparisons.

Validation ownership follows module ownership. Tasks validates Task definitions and the Tasks-owned `create_task` Point contract. Campaigns validates Campaign journeys/variants and Campaign-owned Route action definitions. Messaging validates message definitions/templates/fields/tokens and its `send_message` Point contract. FlowRoutes validates the Route envelope, capabilities/handlers, graph, progression, bindings, and runtime state while delegating Point-specific schemas and semantic/domain-reference checks through `AutomationPointDefinitionRegistry`. The app-level manager coordinates contributors without absorbing private module rules.

The same reusable validation seam should support:

```text
Artisan setup validation
staging/client handoff gates
future Message Template authoring feedback
future Campaign authoring feedback
future Route Management authoring feedback
future setup/readiness screens
```

Executable references should be checked against their owning source of truth. Reference registries are useful authoring/documentation registries and are validated for drift, but a stale registry must not become the only runtime truth. Future authoring/readiness UI should consume the same registries, resolvers, availability checks, and validation seams so impossible combinations are hidden, disabled, or blocked before save; server-side validation and `setup:validate` remain backstops for stale state, manual edits, deployment drift, and legacy data.

Phase 6 completed in this order:

```text
docs audit
-> config normalization
-> schema/model audit
-> contributor-based validation/runtime code
-> fast schema checks when applicable
-> focused validation tests
-> adjacent module/runtime regressions
-> broader client/default-preset fallback coverage
-> final docs/handoff reconciliation
```

Add schema only when the audit proves a durable first-class concept is missing. Do not use `meta` to avoid a proven field, and do not add tables merely to persist validation output.

## Shared available-field/token registry direction

Many modules author reusable copy, task descriptions, instructions, route send-message points, or other text that may include dynamic fields.

The executable token source/context foundation is implemented:

```text
TokenSourceProvider
TokenContextProvider
ComputedTokenValueProvider
TokenContractRegistry
MessageTemplateTokenValidator
```

`MessageTemplateTokenValidator` is reused by Messaging config/setup validation, MessageTemplatePreset sync, and CRM template editing. Unknown or registered-but-unavailable tokens are hard errors for those authoring paths.

The polished `Insert field` / `Add field` picker remains future UX, but it must consume the same registry/context source of truth rather than introduce a UI-only token list.

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

The registry preserves module ownership:

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
A config/reference file is treated as an executable global allowlist.
```

Treat available-field validation as setup/config-validation work. Future editor autocomplete should be a consumer of the existing registry/validator, not a second validation system.