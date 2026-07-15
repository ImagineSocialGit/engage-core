# Documents Module

Documents is a current universal module.

Documents owns reusable document request, upload, review, and lifecycle capability that can be used by multiple verticals without pushing document state into Core contacts, Forms submissions, Portal accounts, or vertical-specific tables.

Documents is not intended to be a full enterprise document-management system or a client-facing document-requirement builder.

The intended product shape is:

```text
Engage Core developers/operators define reusable document requirements and request patterns.
The client can request, review, approve, reject, or track documents quickly.
Portal users can upload requested documents through a simple guided flow.
Vertical modules can decide what an accepted document means in their domain.
```

## Product barometer

Documents should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

For Documents, this creates a clear split.

Client-facing Documents work:

```text
Request these documents from a contact.
Open this contact's document checklist.
Review an uploaded document.
Approve a document.
Reject a document and ask for a replacement.
Mark a requested document as waived or not needed.
See what is still missing.
```

Developer/operator Documents work:

```text
Define document requirements.
Build reusable document request templates/checklists.
Decide which vertical workflows require which documents.
Wire reminders, review tasks, Portal upload surfaces, and vertical interpretation.
Map accepted documents into domain-specific states.
Configure storage disks/providers.
```

Making a document checklist from scratch can involve legal, compliance, operational, or vertical-specific judgment. That should usually be developer/operator work.

The foundation should still make repeated setup fast. A common document checklist that would take a client hours to design should become a 10-15 minute developer/operator task when it follows an existing pattern: copy or select requirement definitions, adjust labels/instructions, choose review behavior, attach to the right workflow surface, and publish.

The client-facing action should be something like:

```text
Request onboarding documents.
Review uploaded vaccination record.
Ask for a clearer copy of the signed waiver.
```

Not:

```text
Design the document collection architecture from scratch.
```

## Responsibility

Documents should answer:

```text
What document was requested, who or what is it about, what file/upload was submitted, what is its review state, and what lifecycle events happened?
```

Documents should stay vertical-neutral.

It may support dog vaccination records, waivers, mortgage income/asset files, music contracts/assets, compliance documents, customer ID files, signed agreements, or general customer uploads without owning the business meaning of those documents.

## FOSS feature-shape assumptions

Before proposing schema, Documents was evaluated against common patterns in mature open-source and open-source-adjacent document-management, file-sharing, client-portal, and document workflow systems.

Those systems commonly support:

```text
- document/file records
- document types/categories/tags/metadata
- uploaded files and original filenames
- MIME type, extension, size, checksum, and storage location metadata
- document versions or replacement uploads
- request/approval/rejection workflows
- status/lifecycle tracking
- secure sharing or upload links
- file-drop or portal upload surfaces
- comments/notes/activity history
- reviewer/approver assignment
- access permissions
- full-text search and OCR
- provider/storage abstraction
- automation hooks, workflows, or notifications
- audit logs and document history
- retention/archive behavior
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Documents should have a roomy, generic foundation for request definitions, document requests, uploaded document records, and review/lifecycle events, while consuming other Engage Core modules for portal access, messaging, tasks, forms, storage adapters, reporting, and vertical-specific interpretation.

## Intended authoring model

Documents should support developer/operator-authored requirements first.

Likely document authoring sources:

```text
code/config-defined default document requirements
client-specific config document requirements
preset-synced DB document request templates later
operator-created document requirement records later, if needed
```

The first implementation should not require a polished client-facing document-requirement builder.

The first implementation should instead optimize for fast developer/operator authoring from repeatable patterns.

A later admin/operator interface may exist for internal maintainers, but it should not be the default client mental model.

Default client-facing actions should be prebuilt and simple:

```text
select an existing document request/checklist
send/request it
review uploads
approve/reject/request replacement
view missing documents
trigger follow-up work
```

## Owns

Documents owns:

```text
document_requirement_definitions
document_requests
document_uploads
document_review_events
```

Documents should also own, when implemented:

```text
document request lifecycle
uploaded document record lifecycle
document review status behavior
document replacement/version relationship behavior
document request public/upload token behavior, if not fully Portal-authenticated
request expiration behavior
request reminder orchestration intent
document-related domain events
generic document checklist/read services
document preset sync, if default/client document requirements are added later
```

Documents should not own message delivery, task lifecycle, portal accounts/auth, form answers/submissions, raw storage provider internals, OCR provider internals, or vertical-specific interpretation of a document.

## Does not own

Documents does not own:

```text
Core Contact records
Forms structured answers, form definitions, or form submissions
customer portal identity/auth
Messaging delivery infrastructure
Messaging consent records
task assignment/digest lifecycle
appointment scheduling or booking state
raw file storage provider details
OCR/extraction provider internals
commerce products/orders/payments
vertical-specific document meaning
pet vaccination compliance rules
mortgage underwriting/doc collection decisions
music contract strategy or rights metadata
client-facing blank-canvas document checklist builder by default
```

Documents may store the uploaded record and review result. The vertical module owns what that result means.

Examples:

```text
Documents stores an uploaded rabies vaccination record.
PetServices decides whether that document satisfies the dog's compliance requirements.

Documents stores an uploaded bank statement.
Mortgage decides whether that document satisfies underwriting or LOS requirements.

Documents stores an uploaded music contract.
Music decides what rights, release, or strategy meaning that contract has.
```

## Consumes

Documents may consume these modules through public seams when enabled:

```text
Core
Portal
Messaging
Tasks
Forms
InternalNotifications
Reporting
Integrations/adapters
```

Expected usage:

```text
Core -> contact-linked document requests and uploads
Portal -> customer-facing document request visibility and upload screens
Messaging -> document request messages, reminders, and replacement requests
InternalNotifications -> team-facing alerts when documents are uploaded or need review
Tasks -> manual review/follow-up tasks generated from document outcomes
Forms -> forms may reference requested documents or collect structured answers near an upload flow
Reporting -> document request/review reporting through public read/query services
Integrations -> storage/OCR/provider adapters behind Documents-owned contracts
```

For the first foundation slice, Documents should depend only on Core. Portal, Messaging, Tasks, Forms, InternalNotifications, Reporting, OCR, and storage-provider behavior should remain optional later integrations through public seams.

## Consumed by

Documents may be consumed by:

```text
Portal
Forms
Scheduling
Tasks
PetServices
Music
Mortgage
Reporting
FlowRoutes
Campaigns
Broadcasts
```

Consumers should use public Documents actions/services/contracts/events/read services rather than directly mutating Documents internals.

Expected future examples:

```text
Portal contributes the customer-facing shell around document uploads.
Forms links an intake submission to related document requirements.
Scheduling requires a waiver document before an appointment.
Tasks creates internal review work after an upload.
PetServices reads approved vaccination document state through a Documents read service.
Mortgage reads document checklist state through a Documents read service.
Reporting reads document completion/review summaries through a Documents read service.
```

## Public seams to add later

The first foundation slice does not need full actions yet.

Likely future public seams:

```text
CreateDocumentRequirementDefinitionAction
CreateDocumentRequestAction
CancelDocumentRequestAction
ExpireDocumentRequestAction
UploadDocumentAction
AttachUploadedDocumentToRequestAction
ReviewDocumentUploadAction
ApproveDocumentUploadAction
RejectDocumentUploadAction
RequestDocumentReplacementAction
WaiveDocumentRequestAction
DocumentsReadService
DocumentRequestReadService
DocumentUploadReadService
DocumentRequestReminderScheduler
DocumentReviewTaskOrchestrator
DocumentNotificationOrchestrator
DocumentAutomationEventEmitter
DocumentStorageManager
DocumentStorageProvider
DocumentTextExtractionProvider
```

Public actions should exist before other modules directly create or mutate Documents records.

## Requirement definitions vs requests

Documents should separate reusable requirement identity from the specific request sent to a contact or subject.

A requirement definition answers:

```text
What kind of document do we commonly ask for?
```

A document request answers:

```text
Who or what currently needs this document, what was requested, and what is the request lifecycle state?
```

Good:

```text
document_requirement_definitions.key = vaccination_record
document_requests.requirement_definition_id = vaccination_record
document_requests.contact_id = Contact #123
document_requests.subject = PetProfile #456, later through a vertical-owned subject morph
```

Bad:

```text
Store document checklist state on contacts.
Store pet vaccination compliance state inside Documents.
Store mortgage underwriting state inside Documents.
```

Requirement definitions are optional for one-off uploads.

Documents should allow a request or upload without a reusable requirement definition when the business needs an ad hoc document.

## Requests, uploads, and review events

Documents should distinguish the request from the uploaded file record.

A request can exist before any file is uploaded.

An upload can exist with or without a request.

A request may have multiple uploads over time, especially when the first upload is rejected, replaced, superseded, or expired.

Review events should be append-style history records rather than overwriting all context on the upload.

Good:

```text
DocumentRequest status = pending
DocumentUpload status = uploaded
DocumentReviewEvent event = rejected, reason = blurry_image
DocumentRequest status = replacement_requested
New DocumentUpload replaces previous upload
DocumentReviewEvent event = approved
DocumentRequest status = satisfied
```

Bad:

```text
Only keep latest uploaded filename and latest status with no review history.
```

## Files and storage

Documents owns the domain record around uploaded files.

Documents does not own raw storage infrastructure.

Good:

```text
document_uploads.disk = private
document_uploads.path = documents/...
document_uploads.original_filename = bank-statement.pdf
document_uploads.mime_type = application/pdf
document_uploads.size_bytes = 123456
```

Bad:

```text
Documents hardcodes S3/Spaces behavior directly into domain logic.
Documents assumes all files are images or PDFs only.
Documents stores provider-specific lifecycle state as first-class universal columns before behavior exists.
```

Storage adapters, virus scanning, OCR, previews, and extraction can be added behind Documents-owned contracts later.

The foundation should store enough generic file metadata to support secure retrieval, audit, review, and replacement workflows.

## Forms relationship

Documents and Forms are adjacent but separate.

Forms owns structured answers.

Documents owns document requests, uploads, file records, and document review lifecycle.

Good:

```text
Forms stores the answer: "Has your dog received rabies vaccination? Yes."
Documents stores the uploaded vaccination record file and review status.
PetServices decides whether the answer plus approved document satisfies the requirement.
```

Bad:

```text
Forms owns document review lifecycle.
Documents owns structured questionnaire answers.
Documents stores form field values as document metadata by default.
```

Forms may reference Documents through public seams later when a form asks for supporting documents.

Documents may reference a form submission as context through a subject morph when an upload supports a submitted form.

## Portal, Messaging, tasks, and notifications

Document products commonly include upload portals, request emails, reminders, internal review alerts, and follow-up tasks.

In Engage Core, Documents should record request/upload/review state and then call other modules through public seams when needed.

Good future direction:

```text
Documents -> Portal extension point for customer upload surfaces
Documents -> Messaging public action/service for request/reminder/replacement messages
Documents -> InternalNotifications public action/service for team alert
Documents -> Tasks public CreateTaskAction for review/follow-up
```

Bad:

```text
Documents owns Portal accounts.
Documents writes directly to scheduled_messages.
Documents owns TeamMember notification preferences.
Documents creates Tasks by mutating task table internals.
```

When Documents schedules or dispatches customer-facing messages through Messaging, Messaging should keep its existing recipient/context split:

```text
recipient_type / recipient_id
    Who receives the scheduled message.

context_type / context_id
    What domain record the scheduled message is about.
```

Example:

```text
Document request reminder
    recipient = Contact
    context = DocumentRequest
```

Documents should not ask Messaging for `subject_type` / `subject_id` columns. `scheduled_messages.context_type` / `scheduled_messages.context_id` is already the canonical scheduled-message "about this record" morph.

## Automation events

Documents should use the existing app-level automation event seam when document outcomes become automation-worthy.

Current seam:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Likely future Documents automation events:

```text
document.requested
document.uploaded
document.reviewed
document.approved
document.rejected
document.replacement_requested
document.request_satisfied
document.request_waived
document.request_expired
```

Documents should emit automation events after it records its own domain state.

FlowRoutes should listen to `AutomationEventRecorded`, not Documents-specific events.

Good:

```text
Documents records document.approved.
Documents emits AutomationEventRecorded(document.approved).
FlowRoutes reacts through the generic automation event seam.
```

Bad:

```text
Documents imports FlowRoutes.
FlowRoutes adds a Documents-specific listener.
Producer module calls FlowRouteExternalEvent directly.
```

Automation events should be contact-aware, not contact-required.

A document event may have:

```text
contact_id nullable
subject_type = DocumentRequest or DocumentUpload
subject_id = related Documents record
```

## Schema foundation

The first Documents foundation should add these tables:

```text
document_requirement_definitions
document_requests
document_uploads
document_review_events
```

These tables are intentionally roomy but generic.

They include generic fields such as:

```text
key
contact_id nullable
subject_type / subject_id nullable
status
source
provider nullable
external_id nullable
requested_at / submitted_at / reviewed_at / approved_at / rejected_at / expires_at where appropriate
meta json
timestamps
soft deletes
```

They avoid vertical-specific columns, UI-specific assumptions, provider-specific assumptions, and domain-record ownership that belongs to other modules.

## Table notes

### document_requirement_definitions

Represents a reusable type of document the business may request.

Examples:

```text
Vaccination record
Signed waiver
Photo ID
Bank statement
W-2
Music contract
Proof of insurance
General supporting document
```

Important fields:

```text
key
name
description
instructions
status
category
is_required_by_default
allows_multiple_uploads
requires_review
accepted_mime_types
max_file_size_kb
expires_after_days
sort_order
source
provider
external_id
external_url
settings
meta
```

Notes:

```text
category should remain generic.
accepted_mime_types may be nullable.
settings stores generic behavior knobs that are not yet first-class.
Do not encode pet, mortgage, music, legal, or compliance-specific meaning here.
```

### document_requests

Represents a specific request for a document from or about a contact/subject.

Important fields:

```text
document_requirement_definition_id nullable
contact_id nullable
subject_type / subject_id nullable
requested_by_type / requested_by_id nullable
assigned_to_type / assigned_to_id nullable
title
instructions
status
priority
request_token
requested_at
sent_at
opened_at
first_uploaded_at
last_uploaded_at
satisfied_at
waived_at
expired_at
cancelled_at
expires_at
source
provider
external_id
external_url
settings
meta
```

Notes:

```text
contact_id is nullable so non-contact or subject-first document workflows remain possible.
subject_type / subject_id describes what the request is about.
requested_by tracks who initiated it when useful, without requiring a user-only assumption.
assigned_to tracks internal review/follow-up ownership when useful, without replacing Tasks.
request_token is nullable and only needed for non-Portal public upload/request flows.
status should remain generic: draft, pending, sent, viewed, uploaded, replacement_requested, satisfied, waived, expired, cancelled.
```

### document_uploads

Represents an uploaded or externally referenced document file record.

Important fields:

```text
document_request_id nullable
document_requirement_definition_id nullable
contact_id nullable
subject_type / subject_id nullable
uploaded_by_type / uploaded_by_id nullable
replaces_document_upload_id nullable
title
status
review_status
disk
path
original_filename
mime_type
extension
size_bytes
checksum
storage_visibility
submitted_at
reviewed_at
approved_at
rejected_at
expires_at
source
provider
external_id
external_url
metadata
meta
```

Notes:

```text
document_request_id is nullable for ad hoc uploads.
replaces_document_upload_id supports replacement/superseded upload history.
disk/path store generic Laravel storage location details, not provider-specific implementation behavior.
metadata stores extracted or user-supplied document metadata that is not Forms structured answer data.
review_status may duplicate status narrowly for review UX/query convenience, but lifecycle rules should stay coherent.
status should remain generic: uploaded, pending_review, approved, rejected, superseded, archived, deleted.
```

### document_review_events

Represents append-style review, status, and audit events for document requests/uploads.

Important fields:

```text
document_request_id nullable
document_upload_id nullable
actor_type / actor_id nullable
event
from_status
to_status
reason
notes
occurred_at
meta
```

Notes:

```text
At least one of document_request_id or document_upload_id should be present.
event should stay generic: requested, sent, opened, uploaded, reviewed, approved, rejected, replacement_requested, waived, expired, cancelled, archived.
Review events are not Tasks.
Review events record what happened; Tasks track manual work that still needs to happen.
```

## Status guidance

Use simple lifecycle states first.

Suggested document request statuses:

```text
draft
pending
sent
viewed
uploaded
replacement_requested
satisfied
waived
expired
cancelled
```

Suggested document upload statuses:

```text
uploaded
pending_review
approved
rejected
superseded
archived
deleted
```

Do not add vertical-specific statuses such as:

```text
underwriting_pending
vaccination_non_compliant
royalty_review_needed
```

Those belong in vertical modules or vertical-owned interpretation records.

## First foundation slice

The first implementation slice should be foundation only.

### Schema/model/migration changes

Add:

```text
app/Modules/Documents/Providers/DocumentsModuleServiceProvider.php
app/Modules/Documents/Models/DocumentRequirementDefinition.php
app/Modules/Documents/Models/DocumentRequest.php
app/Modules/Documents/Models/DocumentUpload.php
app/Modules/Documents/Models/DocumentReviewEvent.php
database/migrations/*_create_document_requirement_definitions_table.php
database/migrations/*_create_document_requests_table.php
database/migrations/*_create_document_uploads_table.php
database/migrations/*_create_document_review_events_table.php
database/factories/DocumentRequirementDefinitionFactory.php
database/factories/DocumentRequestFactory.php
database/factories/DocumentUploadFactory.php
database/factories/DocumentReviewEventFactory.php
```

Also add the standard empty module directories:

```text
app/Modules/Documents/Actions
app/Modules/Documents/Contracts
app/Modules/Documents/Controllers
app/Modules/Documents/Data
app/Modules/Documents/Requests
app/Modules/Documents/Services
app/Modules/Documents/Support
```

Register `documents` in `config/modules.php` with:

```text
name = Documents
depends_on = core
provider = DocumentsModuleServiceProvider
```

### Code integration changes

Keep this first slice minimal:

```text
Module provider only.
No routes.
No navigation.
No controllers.
No Portal screens.
No Messaging sends.
No Tasks creation.
No presets.
No provider/OCR/storage adapters beyond normal Laravel storage metadata fields.
```

### Docs/process changes

Update:

```text
docs/modules/documents.md
core-project-tree.txt after regenerating from the repo
```

Update `docs/module-boundaries.md` only if the implementation adds a new durable global rule or changes the table ownership freeze.

If the schema is added, the table ownership freeze should include:

```text
document_requirement_definitions | Documents
document_requests | Documents
document_uploads | Documents
document_review_events | Documents
```

Remove the completed Documents planning item from `docs/TODO.md` only after the planning doc is committed.

### Tests only

Add focused tests for the foundation:

```text
Documents module registration/config visibility test
DocumentRequirementDefinition schema/model test
DocumentRequest schema/model test
DocumentUpload schema/model test
DocumentReviewEvent schema/model test
Module boundary test coverage if an existing module-boundary test suite exists
```

Schema tests should verify durable fields and relationships, not future UI behavior.

Do not shape production code around stale tests. Shape tests around this future architecture.


## FlowRoutes integration

This module should integrate with FlowRoutes through the ownership-preserving automation extension pattern used across Engage Core.

When this module has automation-worthy outcomes, it records its own domain state first and then emits neutral `AutomationEventRecorded` events. FlowRoutes listens to the generic automation-event seam, not module-specific events.

When FlowRoutes creates or mutates this module's records, it does so only through public actions/services/contracts exposed by this module. FlowRoutes must not write this module's private tables directly.

When this module contributes a cross-module Route business action, the module owns the Point-definition schema, semantic/domain-reference validation, neutral automation action handler, and authoring contribution through the shared Support-layer automation registries. FlowRoutes owns the Route envelope, orchestration/progression, native orchestration Points, created-artifact references, correlation, and resume matching.

Preferred boundary:

```text
Owning module
    owns business records and lifecycle
    owns contributed Point schema and semantic validation
    owns neutral business-action execution
    owns Point-specific authoring fields/rules/guidance when authorable

FlowRoutes
    owns route structure and progression
    adapts neutral business-action results into Point execution results
    records created-artifact identity in FlowRoutes-owned state
    owns correlation and resume matching
```

Do not add `flow_route_*` foreign keys to this module's artifacts merely for provenance symmetry. Add artifact-side provenance only when this module has an independently justified neutral provenance contract that is useful outside FlowRoutes.

## Deferred work

Defer until after the foundation is stable:

```text
Document request UI
Portal upload screens
public upload links/tokens
Messaging request/reminder/replacement messages
InternalNotifications upload/review alerts
Tasks review/follow-up orchestration
Document requirement presets/sync
OCR/text extraction
preview generation
virus scanning
provider-specific storage adapters
signature/e-signature behavior
vertical-specific requirement/checklist definitions
vertical-specific interpretation and compliance state
Reporting dashboards
FlowRoutes document event reactions
```

## Open questions

Resolve before implementation if the first slice needs the answer:

```text
Should document_requirement_definitions be included in the first schema slice, or should requests start fully ad hoc?
Should request_token exist in the foundation, or wait until public upload links are implemented?
Should review_status be separate from status on document_uploads, or should status alone carry review state?
Should assigned_to_type / assigned_to_id be included now for review ownership, or should review ownership wait for Tasks/InternalNotifications integration?
```

Recommended defaults for the first foundation:

```text
Include document_requirement_definitions.
Include nullable request_token, but do not implement public token behavior yet.
Include both status and nullable review_status on document_uploads for query/UX clarity.
Include nullable assigned_to_type / assigned_to_id on document_requests as generic ownership metadata, but do not notify or create tasks from it yet.
```


