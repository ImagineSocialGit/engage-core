


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


## Canonical contact terminology and client-facing aliases

Core owns the canonical internal Contact identity.

Internal identifiers should use `contact`, including config keys, preset keys, task-template keys, route keys, automation event keys, trigger keys, registry keys, token identities, model/data field names, and validation codes.

Client/operator UI may present a configured business noun such as:

```text
Lead
Fan
Customer
Client
Borrower
Owner
Member
```

That label is presentation vocabulary, not a second runtime identity.

Authoring UI may also expose friendly field aliases derived from the configured contact noun, for example:

```text
lead_first_name
fan_first_name
customer_first_name
```

Those aliases should normalize to one canonical internal field identity such as:

```text
contact.first_name
```

or another documented canonical Contact token/field.

Do not create separate runtime payload fields, database columns, event keys, preset identifiers, or validation concepts for each client-facing noun.

## ContactStatus durability and preset sync

`ContactStatus` is DB-owned runtime state, not a config-only value.

Preset sync semantics:

```text
missing status
    create it

existing non-customized status
    update it from the selected preset definition

existing customized status
    preserve it during normal sync

force sync
    overwrite it from preset data and clear is_customized/customized_at
```

Durable customization fields are:

```text
is_customized
customized_at
```

The first-class `ContactStatus` fields remain the source of truth for status identity and presentation:

```text
key
name
description
category
color
is_core
is_active
sort_order
source_version
meta
```

Do not duplicate first-class values inside `meta`.

Default status resolution should use the configured canonical contact status key rather than hard-coding `prospect` or another vertical/client-specific label.

## Core setup validation ownership

Core contributes setup validation through `CoreSetupValidationContributor` for Core-owned authoring definitions, especially selected ContactStatus preset groups/definitions and canonical internal terminology.

Core validation should verify at least:

```text
selected ContactStatus preset groups exist
referenced ContactStatus definitions exist
definition keys match their registry/config keys
required fields are present
internal identifiers use canonical contact terminology
```

Core-owned setup validation returns the shared structured finding shape used by the app-level setup validation manager. It validates selected package/group/definition existence, definition key identity, canonical internal terminology, required names, metadata shape, and duplicated first-class metadata fields. It does not persist validation findings by default.

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

Core may expose module-owned actions on Core pages when the owning module is enabled.

For example, the import batch detail page may show a Messaging-owned permission invitation action when Messaging is enabled.

Core must not directly import Messaging actions, services, models, or scheduled-message internals to support that UI.

Core owns the generic contact filter resolver used by modules for stable Contact-owned filter facts.

That resolver should understand stable Core-owned contact facts such as contact IDs, tags, imported/source fields, statuses, and generic timestamps.

Modules may consume resolved contact sets, but Core should not absorb module-specific business rules by default.

Future module-specific contact filters should be contributed through explicit provider/registry seams rather than hard-coded into Core.

Core `ContactController` may ask registries for module-provided data.

Core `ContactController` must not directly import module-specific models/services such as Tasks, Messaging, InboundMessaging, InternalNotifications, Webinars, Campaigns, Mortgage, or FlowRoutes.


## Contact show shell

Core owns the contact show shell and generic contact details.

Current implementation direction:

```text
Core loads generic contact details and notes.
Workflow status appears only through the Workflow seam when Workflow is enabled.
Modules contribute contact sections and panels through Core-owned registries.
Contact show should lead with next action and use module-provided summaries below it.
```

Core may render module-provided DTOs/arrays/views, but it should not query module tables directly.




