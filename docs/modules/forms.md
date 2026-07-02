# Forms Module

Forms is a current universal module.

Forms owns reusable form definition, form runtime, submission, and review capability that can be used by multiple verticals without pushing submitted answers into Core contacts or vertical-specific tables by default.

Forms is not intended to be a client-facing drag-and-drop form-builder product.

The intended product shape is:

```text
Engage Core developers/operators can add the right form for a client.
The client can send, use, and review that form quickly.
The form plays cleanly with Contacts, Portal, Scheduling, Documents, Tasks, Messaging, Reporting, and vertical modules.
The client should not have to learn a full form-builder system to do normal work.
```

## Product barometer

Forms should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

For Forms, this creates a clear split.

Client-facing Forms work:

```text
Send this existing intake form to a lead.
Open this lead's submitted intake.
Review this submission.
Approve this submission.
Ask for missing information.
Attach an existing form to a booking flow.
Require an existing questionnaire before a consultation.
```

Developer/operator Forms work:

```text
Build the form.
Define fields and validation.
Version the schema.
Map answers into vertical-specific records.
Decide review workflow.
Connect the form to Messaging, Tasks, Portal, Scheduling, Documents, or a vertical module.
```

Making a whole form is usually hours of design, technical judgment, copywriting, validation, and workflow thinking for a nontechnical person.

That is developer/operator work.

But the foundation should still make that work fast for common cases. A form that would take a client hours to think through and build should become a 10-15 minute developer/operator task when it follows an existing pattern: copy or select a form definition, adjust labels/questions, choose review behavior, attach it to the right workflow surface, and publish it.

The client-facing action should be something like:

```text
Send the onboarding form.
Review the submitted onboarding form.
```

Not:

```text
Design the onboarding form from scratch.
```

## Responsibility

Forms should answer:

```text
What form was defined, which version was submitted, who submitted it, what answers were submitted, and what is the review/lifecycle state of that submission?
```

Forms should stay vertical-neutral.

It may support dog training intake forms, mortgage lead/application intake, music booking inquiries, webinar questionnaires, general client questionnaires, internal request forms, portal-submitted forms, or other structured data collection without owning the vertical meaning of the submitted answers.

## FOSS feature-shape assumptions

Before proposing schema, Forms was evaluated against common patterns in mature open-source and open-source-adjacent form builder, survey, data collection, and low-code form systems.

Those systems commonly support:

```text
- form definitions
- form versions or JSON/schema-backed definitions
- no-code or low-code form builders
- many field/input types
- validation rules
- required/optional fields
- conditional logic / skip logic
- multi-page forms or sections
- public or embedded form rendering
- authenticated/customer-submitted forms
- submission records / responses
- answer/value storage
- response export or reporting
- webhooks / integrations
- email or internal notifications
- file upload fields
- permissions and access control
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Forms should have a versioned, schema-backed foundation for definitions, versions, submissions, and submission values, but Engage Core should not expose a full form-builder UX to clients by default.

## Intended authoring model

Forms should support developer/operator-authored forms first.

Likely form authoring sources:

```text
code/config-defined default forms
client-specific config forms
preset-synced DB form definitions
operator-created form records later, if needed
```

The first implementation should not require a polished client-facing form-builder.

The first implementation should instead optimize for fast developer/operator authoring from repeatable patterns.

A later admin/operator interface may exist for internal maintainers, but it should not be the default client mental model.

Default client-facing actions should be prebuilt and simple:

```text
select an existing form
send/request it
review responses
trigger follow-up work
view status
```

## Owns

Forms owns:

```text
form_definitions
form_versions
form_submissions
form_submission_values
```

Forms should also own, when implemented:

```text
form definition lifecycle
form publishing/versioning behavior
form submission lifecycle
submission validation against the submitted form version
submission review state
form-rendering schema normalization
form-submission domain events
form preset sync, if default/client forms are added later
```

Forms should not own message delivery, task lifecycle, portal accounts, document upload/review lifecycle, appointment booking, commerce records, or vertical-specific interpretation of answers.

## Does not own

Forms does not own:

```text
Core Contact records
customer portal identity/auth
message delivery infrastructure
Messaging consent records
task assignment/digest lifecycle
appointment scheduling or booking state
document request/upload/review lifecycle
raw file storage provider behavior
commerce products/orders/payments
vertical-specific profile fields
pet/dog profiles
mortgage application underwriting state
music booking or fan strategy
client-facing drag-and-drop form-builder UX by default
```

Forms may collect answers that a vertical module later interprets, but the vertical module owns that interpretation.

Examples:

```text
Forms stores submitted dog intake answers.
PetServices decides what those answers mean for pet profile/training goals.

Forms stores mortgage intake answers.
Mortgage decides what those answers mean for mortgage profile/stage/LOS behavior.

Forms stores music booking inquiry answers.
Music decides what those answers mean for fan/customer/show workflows.
```

## Consumes

Forms may consume these modules through public seams when enabled:

```text
Core
Portal
Messaging
Tasks
Documents
Scheduling
InternalNotifications
Reporting
```

Expected usage:

```text
Core -> contact-linked submissions
Portal -> authenticated/customer-facing form access and submission
Messaging -> form confirmations or submission notifications
InternalNotifications -> team-facing submission alerts
Tasks -> manual review/follow-up tasks generated from submissions
Documents -> uploaded document/file request and review behavior
Scheduling -> intake forms attached to booking flows
Reporting -> submission reporting through public read/query services
```

For the first foundation slice, Forms should depend only on Core. Portal, Messaging, Tasks, Documents, Scheduling, and InternalNotifications should remain optional later integrations through public seams.

## Consumed by

Forms may be consumed by:

```text
Portal
Scheduling
Documents
PetServices
Music
Mortgage
Reporting
FlowRoutes
Campaigns
Broadcasts
```

Consumers should use public Forms actions/services/contracts/events/read services rather than directly mutating Forms internals.

Expected future examples:

```text
Portal contributes customer-facing access and shell around form submission.
Scheduling requires an intake form before a booking can be completed.
Documents links a document upload request to a form-submission review flow.
PetServices reads dog intake answers and maps them into pet-service records.
Mortgage reads mortgage intake answers and maps them into mortgage records.
Reporting reads submission summaries through a Forms read service.
```

## Public seams to add later

The first foundation slice does not need full actions yet.

Likely future public seams:

```text
CreateFormDefinitionAction
PublishFormVersionAction
ArchiveFormDefinitionAction
CreateFormSubmissionAction
ReviewFormSubmissionAction
RejectFormSubmissionAction
ApproveFormSubmissionAction
FormsReadService
FormSubmissionReadService
FormRendererSchemaNormalizer
FormSubmissionValidator
FormSubmissionAutomationEventEmitter
FormSubmissionTaskOrchestrator
FormSubmissionNotificationOrchestrator
```

Public actions should exist before other modules directly create or mutate Forms records.

## Definitions and versions

Forms should separate a durable form identity from the specific version that was submitted.

A form definition answers:

```text
What is this form conceptually?
```

A form version answers:

```text
What exact fields/schema/rules were active when this submission happened?
```

Good:

```text
form_definitions.key = dog_training_intake
form_versions.version = 3
form_submissions.form_version_id = version 3
```

Bad:

```text
Store only current form fields and let old submissions point at changing definitions.
```

Submitted data should always be traceable to the version that produced it.

## Schema-backed, not UI-builder-first

Forms should store a flexible schema/config snapshot for each form version.

That schema may eventually drive rendering, validation, and review screens.

But the database foundation should not assume that clients are building these schemas manually in a UI.

For the first foundation, keep authored field structure on `form_versions`:

```text
schema json
rules json
layout json
settings json
```

Do not add a separate `form_fields` table yet.

Reason:

```text
We need versioned form runtime capability, not a full relational form-builder engine.
```

A future `form_fields` table can be added later if the product truly needs field-level authoring, analytics, or reusable fields.

## Submission values

Even though authored fields stay in `form_versions.schema`, submitted values should get their own table.

Reason:

```text
Submissions need to be queryable, reviewable, reportable, and inspectable without parsing the entire JSON payload every time.
```

`form_submission_values` should store normalized answer rows while `form_submissions.payload` or `raw_payload` preserves the submitted snapshot.

Good:

```text
form_submission_values.field_key = preferred_training_days
form_submission_values.value = ["monday", "wednesday"]
```

The original submitted payload can still remain on the submission for audit/debugging.

## Form requests vs submissions

The foundation does not need a separate `form_requests` table yet.

For now, requests can be represented later through:

```text
Portal access grants
Messaging scheduled/request messages
Tasks follow-up/review tasks
Documents document requests when the request is document-specific
```

Add `form_requests` only if a distinct lifecycle emerges:

```text
requested
sent
opened
started
submitted
expired
cancelled
reminded
```

Do not add that table speculatively in the first foundation slice.

## Messaging, tasks, notifications, and Portal

Forms products commonly send confirmations, internal notifications, reminders, and follow-up tasks.

In Engage Core, Forms should record form/submission state and then call other modules through public seams when needed.

Good future direction:

```text
Forms -> Messaging public action/service for customer confirmation
Forms -> InternalNotifications public action/service for team alert
Forms -> Tasks public CreateTaskAction for review/follow-up
Forms -> Portal extension point for customer-facing submission UI
```

Bad:

```text
Forms writes directly to scheduled_messages.
Forms owns TeamMember notification preferences.
Forms owns Portal account access.
Forms creates Tasks by mutating task table internals.
```

When Forms schedules or dispatches customer-facing messages through Messaging, Messaging should keep its existing recipient/context split:

```text
recipient_type / recipient_id
    Who receives the scheduled message.

context_type / context_id
    What domain record the scheduled message is about.
```

Example:

```text
Form submission confirmation
    recipient = Contact
    context = FormSubmission
```

## Automation events

Forms should use the existing app-level automation event seam when form outcomes become automation-worthy.

Current seam:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Likely future Forms automation events:

```text
form.submitted
form.reviewed
form.approved
form.rejected
form.needs_changes
```

Forms should emit automation events after it records its own domain state.

FlowRoutes should listen to `AutomationEventRecorded`, not Forms-specific events.

Good:

```text
Forms records form.submitted.
Forms emits AutomationEventRecorded(form.submitted).
FlowRoutes reacts through the generic automation event seam.
```

Bad:

```text
Forms imports FlowRoutes.
FlowRoutes adds a Forms-specific listener.
Producer module calls FlowRouteExternalEvent directly.
```

Automation events should be contact-aware, not contact-required.

A form event may have:

```text
contact_id nullable
subject_type = FormSubmission
subject_id = related FormSubmission record
```

## Schema foundation

The first Forms foundation should add these tables:

```text
form_definitions
form_versions
form_submissions
form_submission_values
```

These tables are intentionally roomy but generic.

They include generic fields such as:

```text
key
name
status
source
provider
external_id
meta json
timestamps
soft deletes
```

They avoid vertical-specific columns, UI-builder-first assumptions, provider-specific assumptions, and domain-record ownership that belongs to other modules.

## Table notes

### form_definitions

Represents a durable form identity.

Important fields:

```text
key
name
description
status
category
is_public
current_form_version_id
source
provider
external_id
meta
```

Notes:

```text
key should be stable and suitable for presets/config references.
current_form_version_id points to the currently published version when available.
category stays generic, such as intake, questionnaire, review, request, feedback.
```

### form_versions

Represents a versioned form schema/config snapshot.

Important fields:

```text
form_definition_id
version
status
name
description
schema
rules
layout
settings
published_at
archived_at
source
provider
external_id
meta
```

Notes:

```text
schema stores sections/fields/options in a normalized JSON shape.
rules stores validation/conditional rules.
layout stores rendering hints.
settings stores runtime settings such as submit button label or confirmation mode.
Do not add a form_fields table in the first foundation slice.
```

### form_submissions

Represents one completed or in-progress form submission.

Important fields:

```text
form_definition_id
form_version_id
contact_id
portal_user_id nullable later
subject_type / subject_id nullable
status
review_status
submitted_at
reviewed_at
reviewed_by_type / reviewed_by_id
source
provider
external_id
ip_address
user_agent
payload
raw_payload
meta
```

Notes:

```text
contact_id should be nullable because some forms may be anonymous until matched.
subject morph lets submissions attach to an appointment, document request, portal account, or vertical record later.
payload stores normalized submitted data.
raw_payload stores original intake when useful.
review_status should remain generic: pending, approved, rejected, needs_changes.
```

### form_submission_values

Represents normalized submitted answer values.

Important fields:

```text
form_submission_id
field_key
field_label
field_type
value
value_text
value_number
value_boolean
value_date
value_datetime
sort_order
meta
```

Notes:

```text
value stores the canonical JSON value.
Typed value columns support common filtering/reporting without forcing everything into JSON queries.
field_label and field_type snapshot the submitted version's display context.
```

## Deferred work

Deferred until needed:

```text
client-facing form builder UI
internal/operator form builder UI
form preset sync
public form rendering routes
portal form submission routes
form submission review UI
form submission notifications
form confirmation messages
form-triggered task creation
form-triggered automation events
form attachment/file upload integration
form reporting/export views
field-level analytics
conditional logic runtime
multi-page form runtime
form request lifecycle table
```

## Open questions

```text
Should form definitions be synced from config/presets first, or created only by migrations/seeders/client setup code?
Should form_definitions.current_form_version_id be nullable in the first migration, or avoided until runtime publishing actions exist?
Should form_submissions.status and review_status be separate, or should one lifecycle field be enough?
Should portal_user_id exist immediately, or wait until Portal runtime actions exist?
Should file upload fields route through Documents from the beginning, or be blocked until Documents exists?
Should submitted values include typed columns immediately, or only JSON value + text value for the first slice?
Should Forms emit `form.submitted` automation events in the foundation slice, or wait until CreateFormSubmissionAction exists?
```
