# Core Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

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
