# Engage Core Module Boundaries

Engage Core is a modular contact engagement platform. The goal is to let each client enable only the features they need without forcing every client into CRM, sales, webinar, marketing, or other vertical workflows.

## Core Rule

Modules may depend on another module’s public API, but should not depend on another module’s internal schema, implementation details, or private assumptions.

Good dependencies use:

- actions
- services
- contracts
- DTOs/data objects
- events
- documented config keys
- documented model relationships that are intentionally public

Avoid dependencies on:

- another module’s table internals
- another module’s private status fields
- another module’s implementation-specific config structure
- hardcoded lifecycle assumptions like `converted_at`, `crm_status`, or `assigned_to`

## Installed Schema vs Enabled Features

Shared platform/core/capability-module tables may exist in every install.

A table existing does not mean the feature is enabled.

Feature availability is controlled through:

- module config
- route registration/visibility
- navigation visibility
- controllers/actions
- views/components
- jobs/listeners
- policies/gates
- service bindings
- workflow hooks

Do not put module-enabled conditionals inside normal shared migrations.

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

## Platform Core

Core is required for every install.

Core owns:

- users/auth
- contacts
- contact tags
- imports
- message consents
- consent revocations
- message suppressions
- scheduled messages
- inbound messages
- outbound email/SMS infrastructure
- provider contracts/managers/adapters
- module shell/config

Core contacts must remain generic.

Core contacts should answer:

> Who is this person, how can we reach them, and where did they come from?

Core contacts should not contain workflow, sales, mortgage, webinar, or marketing lifecycle state.

Avoid these fields on `contacts`:

- `status`
- `crm_status`
- `contact_status_id`
- `converted_at`
- `closed_at`
- `assigned_to`
- location/address fields
- mortgage-specific fields
- workflow-specific fields

## Messaging Infrastructure

Messaging infrastructure is shared platform infrastructure, not a sellable Marketing module.

Messaging owns:

- scheduled messages
- provider contracts
- email/SMS adapters
- consent/suppression
- inbound messages
- outbound send jobs
- message gates
- dispatch actions

Other modules may use Messaging through its public actions/services.

Good:

    DispatchMessageAction
    ScheduleMessageAction

Bad:

    ScheduledMessage::query()->create(...)
    MessageConsent::query()->where(...)

## Workflow Module

Workflow is optional.

Workflow owns:

- team members
- team member notification preferences
- contact workflow profiles
- contact statuses
- notes
- tasks
- task assignment
- internal notification routing
- workflow/activity UI

Workflow state belongs in:

    contact_workflow_profiles

Not in:

    contacts

A contact may exist with no workflow profile.

## Flow Module

Flow is optional and depends on Workflow.

Internally, use “Flow” naming to avoid conflicting with Laravel routes.

Client-facing labels may say “Routes.”

Flow owns:

- flow routes
- points
- flow route points
- task generation rules
- cancellation rules

Recommended tables:

    flow_routes
    points
    flow_route_points

Recommended models:

    FlowRoute
    Point
    FlowRoutePoint

A status should have at most one flow route.

Runtime meaning:

    ContactStatus has one FlowRoute
    FlowRoute has many FlowRoutePoints
    FlowRoutePoint belongs to Point

Generated tasks should snapshot cancellation rules so later point/route edits do not mutate task history.

## Marketing Module

Marketing is optional.

Marketing owns:

- broadcasts
- broadcast recipients
- campaigns
- campaign enrollments
- segments/conditions later
- newsletters
- show alerts
- announcement-style sends

Marketing may use Messaging to send messages.

Marketing should not directly depend on Workflow status, Webinar conversion, or Mortgage stages.

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

## Webinar Module

Webinar is optional.

Webinar owns:

- webinar series
- webinars
- webinar registrations
- webinar waitlist signups
- webinar provider behavior
- webinar reminders/follow-ups

Zoom is not a module. Zoom is an adapter used by Webinar.

Webinar may use Messaging to send reminders/follow-ups.

Webinar may trigger Marketing enrollment through Marketing actions, but should not directly manage campaign enrollment internals.

Good:

    EnrollContactInCampaignAction
    DispatchMessageAction

Bad:

    CampaignEnrollment::create(...)
    ScheduledMessage::create(...)

## Location Module

Location is optional.

Location owns:

- contact locations
- city/state/zip/country
- lat/lng later
- radius/market filtering later

Location should not be part of Core contacts.

## Mortgage Module

Mortgage is a vertical module.

Mortgage is optional and should not be installed by default.

Mortgage owns:

- mortgage stages
- contact mortgage profiles
- mortgage-specific fields
- LOS automation
- mortgage-specific flow definitions
- LOS adapters such as Arive

Mortgage may consume Workflow, Flow, Marketing, Webinar, and Messaging.

Mortgage must not push mortgage-specific state into Core contacts.

## Adapters / Integrations

Adapters are not modules.

Examples:

- Resend powers email
- Telnyx/Twilio power SMS
- Zoom powers webinar behavior
- Arive powers mortgage LOS behavior

Adapters should sit behind contracts, managers, resolvers, or provider services.

Modules should depend on contracts/managers, not concrete adapter internals.

## Practical Dependency Standard

When module A needs module B, prefer:

    Module A → Module B public action/service/contract

Avoid:

    Module A → Module B table internals

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

    $contact->workflowProfile?->assignedTo

Bad:

    $contact->assigned_to

## Current Refactor Goal

The current refactor should make these boundaries true:

1. Contacts are true platform core.
2. Workflow data is optional/module-owned.
3. Flow data is optional/module-owned and depends on Workflow.
4. Marketing, Webinar, Location, Mortgage, and future vertical modules are separated.
5. Existing shared module tables may exist in all installs, but only enabled modules expose/use them.
6. Vertical module tables live outside normal migrations.
7. Tests should prove module boundaries instead of old CRM assumptions.