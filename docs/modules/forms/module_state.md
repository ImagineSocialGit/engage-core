# Forms Module

Forms is a current universal, loud module.

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
Send this existing intake form to a contact.
Open this contact's submitted intake.
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

It may support dog training intake forms, mortgage contact/application intake, music booking inquiries, webinar questionnaires, general client questionnaires, internal request forms, portal-submitted forms, or other structured data collection without owning the vertical meaning of the submitted answers.

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

## Preset-backed definition runtime

Forms participates in the shared preset-composition architecture through the `forms` preset domain.

Reusable Forms-owned contributions live under:

```text
presets.modules.forms.forms
```

Client-owned form contributions live under:

```text
presets.modules.client.forms
```

A selected client may also override a reusable Forms-owned definition through its matching nested client config path when the reusable contract intentionally exposes a client-specific policy seam.

A preset package may optionally select Forms groups through:

```text
groups.forms
```

`groups.forms` remains optional so existing preset packages remain valid without Forms configuration.

Selected Forms definitions are synchronized through `SyncFormPresetsAction` when the Forms module is enabled. Global `presets:sync` skips Forms when the module is disabled or no Forms groups are selected.

A selected preset definition owns a stable `FormDefinition` key and an immutable published `FormVersion` snapshot containing:

```text
name
description
schema
rules
layout
settings
```

The sync contract is version-oriented:

```text
unchanged authored snapshot
    -> reuse the existing current FormVersion

changed authored snapshot
    -> publish the next FormVersion
    -> move form_definitions.current_form_version_id to the new version
    -> preserve every older published version unchanged
```

Published FormVersion records are immutable and cannot be deleted. A changed form must publish another version so historical submissions can always remain traceable to the schema that produced them.

Preset ownership is explicit. Preset sync will not overwrite a manual/provider-owned FormDefinition with the same key, and a preset-owned definition cannot silently adopt a non-preset current version. Resolve those ownership collisions deliberately instead of converting records during sync.

`FormDefinitionConfigContract` and `FormSchemaNormalizer` now define the foundational authored shape used by preset sync and runtime readiness checks. Form keys, section keys, and field keys use lowercase `snake_case`; section keys and field keys must be unique within one form. Fields require stable keys, labels, supported types, and boolean required state. Select/radio/checkboxes fields require explicit option values and labels.

Initial supported field types are:

```text
text
email
tel
url
number
textarea
select
radio
checkbox
checkboxes
boolean
date
datetime
hidden
```

This is authoring/schema validation, not submission validation. The submission runtime separately validates each response against the exact frozen FormVersion used for that submission.

`PublishedFormResolver` is the current DB-owned runtime read seam. It resolves only an active FormDefinition and its exact current non-archived published FormVersion. Public consumers may additionally require `is_public = true`. The resolver returns the transport-neutral `PublishedForm` contract with stable definition/version identity, frozen schema/rules/layout/settings, and flattened field metadata.

An active definition with a missing, draft, archived, or structurally invalid current version fails closed instead of silently falling back to another historical version.

Preset sync does not currently archive stale definitions merely because a group stops selecting them. Lifecycle cleanup must be explicit until Forms has a deliberate archival/customization contract.

## Owns

Forms owns:

```text
form_definitions
form_versions
form_submissions
form_submission_values
```

Forms also owns:

```text
form definition lifecycle
form publishing/versioning behavior
form submission lifecycle
submission validation against the submitted form version
submission review state
form-rendering schema normalization
form-submission domain events
form preset sync and version publication
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

## Public seams

Current Forms runtime seams are:

```text
PublishedFormResolver
PublishedForm
CreateFormSubmissionAction
FormSubmissionInput
FormSubmissionResult
FormSubmissionValidator
```

Consumers should resolve a stable form key through this seam rather than query FormDefinition/FormVersion directly or execute from raw preset config.

Later mutation/review seams still include:

```text
CreateFormDefinitionAction
PublishFormVersionAction
ArchiveFormDefinitionAction
ReviewFormSubmissionAction
RejectFormSubmissionAction
ApproveFormSubmissionAction
FormsReadService
FormSubmissionReadService
FormSubmissionAutomationEventEmitter
FormSubmissionTaskOrchestrator
FormSubmissionNotificationOrchestrator
```

Public actions should exist before other modules directly create or mutate Forms records.

## Durable submission runtime

`CreateFormSubmissionAction` is the transport-neutral mutation seam for completed submissions.

It accepts `FormSubmissionInput`, resolves the exact current `PublishedForm`, validates against that frozen version, and returns `FormSubmissionResult`. Neither input nor result exposes an Eloquent model as an external transport contract.

The runtime:

```text
resolves one active published form identity
pins form_definition_id and form_version_id
rejects unknown field keys
enforces required fields
normalizes every supported field type
enforces option-backed values
applies frozen authored Laravel validation rules
stores normalized payload separately from raw_payload
creates typed form_submission_values rows
optionally resolves a Core Contact through server-owned settings
optionally adds server-mapped Core ContactTags
returns an existing submission for a valid external replay
rejects conflicting reuse of an external replay identity
```

Submission, values, Contact resolution/update, and additive tag writes occur in one database transaction. A failed validation or mapping contract writes none of those records.

`provider + external_id` is the durable external idempotency identity. Both values must be present together. The authoritative pre-rollout `create_form_submissions_table` migration enforces their uniqueness when non-null; MySQL's multiple-NULL behavior continues allowing Forms submissions that do not participate in external replay protection.

The submission runtime stores an internal logical-request fingerprint in `form_submissions.meta._forms`. The fingerprint includes the form key, source, submitted field values, durable submission meta, and normalized server-authored verification evidence when verification is present. IP address, user agent, and raw transport payload are evidence snapshots but are not replay identity. A valid retry therefore returns the original pinned submission even when the definition's current version has since advanced. Reusing one external identity with different verification evidence is a conflicting logical request rather than a way to replace the original trust evidence.

Engage Sites and other transports should generate one stable external UUID per logical submission attempt and reuse the same UUID after transport uncertainty.

## External server-to-server Forms access

Forms exposes signed first-party read and submission endpoints on the existing webhook host:

```text
GET  https://webhooks.{root_domain}/forms/{form_key}
POST https://webhooks.{root_domain}/forms/{form_key}/submissions
```

This is an application-to-application boundary. Engage Sites accepts browser input on its own server and signs Core requests there. Forms client IDs and shared secrets must never appear in browser JavaScript, HTML, or public configuration.

### Published-form read

The GET endpoint resolves the exact current active, public, non-archived published FormVersion through `PublishedFormResolver`. It has an empty request body and does not require a `Content-Type` header. It never falls back to an older historical version.

The successful response contains the presentation-safe contract:

```json
{
  "data": {
    "definition_id": 10,
    "version_id": 24,
    "version_number": 3,
    "key": "artist_updates",
    "name": "Artist Updates",
    "description": "Choose the updates you want to receive.",
    "category": "intake",
    "is_public": true,
    "schema": {},
    "rules": {},
    "layout": {},
    "settings": {},
    "fields": []
  },
  "request_id": "..."
}
```

The HTTP response maps the internal transport-neutral `PublishedForm` into `ExternalPublishedForm`; it does not serialize an Eloquent model. `schema`, `rules`, `layout`, and normalized `fields` come from the frozen published version.

Only `FormVersion.settings.public` is exposed as response `data.settings`. Server-owned siblings such as `settings.submission.contact`, `settings.submission.tags`, future execution mappings, or any other internal settings remain private. A Core-backed presentation or disclosure setting must be deliberately authored beneath `settings.public` before an external renderer may receive it.

Published-form responses use `Cache-Control: private, no-store`. A trusted server-side caller may maintain its own bounded application cache after authenticating and validating the response.

### Completed submission

The POST endpoint requires `Content-Type: application/json`. Its request contract is:

```json
{
  "external_id": "ad918706-38b4-449c-8675-311c9a85bf09",
  "values": {
    "email": "fan@example.com",
    "interests": ["music", "vip"]
  },
  "meta": {
    "consent": {
      "disclosure_key": "artist_updates_v1"
    }
  },
  "provenance": {
    "ip_address": "203.0.113.42",
    "user_agent": "Artist site visitor browser"
  }
}
```

`external_id` is required and must be one stable UUID per logical submission. `values` must be a JSON object. `meta` is an optional JSON object for durable submission evidence; `_forms` remains reserved for the Forms runtime.

`provenance` is an optional JSON object authored by the authenticated calling application from the original browser request. It may contain only `ip_address` and `user_agent`. The signature covers the complete exact body so Engage Core can detect tampering; HTTPS still provides transport confidentiality. A caller may omit either or both values when its privacy policy does not retain them. When omitted, Forms stores `null`; it never mislabels the authenticated Engage Sites server or proxy as the visitor. The peer address remains available to authentication and rate limiting at the HTTP boundary.

Visitor provenance is evidence, not durable replay identity. A legitimate retry may therefore carry different or absent provenance and still replay the original submission; the first accepted submission retains its original snapshots. Unknown envelope or provenance keys fail validation.

### Server-authored verification attestation

An authenticated caller may attach normalized human-verification evidence beneath the dedicated top-level `verification` envelope:

```json
{
  "verification": {
    "provider": "turnstile",
    "outcome": "passed",
    "verified_at": "2026-08-20T19:59:30+00:00",
    "hostname": "artist.example.com",
    "action": "artist_updates"
  }
}
```

This envelope is not browser-owned metadata. Engage Sites first verifies the browser token with its configured human-verification provider, discards the raw token, and then authors this bounded result before signing the complete Core request. Core authenticates the calling application through the existing HMAC boundary and derives `authenticated_client_id` from that authenticated client; the browser cannot supply or override it.

Accepted verification evidence is normalized into `form_submissions.meta._forms.verification` with:

```text
version
provider
outcome
verified_at
hostname
action
authenticated_client_id
```

The raw provider token, provider secret, IP address, user agent, and unrestricted provider response are not valid verification-envelope fields and are never copied into the Forms-owned verification evidence.

The v1 attestation contract accepts only a normalized `passed` outcome. `verified_at` must be an RFC3339 timestamp with an explicit timezone. Evidence more than 60 seconds in the future is rejected. By default, presented evidence may be no older than 600 seconds.

Per-form enforcement is versioned under server-only `FormVersion.settings.submission.verification`:

```php
'verification' => [
    'required' => true,
    'providers' => ['turnstile'],
    'max_age_seconds' => 300,
    'action' => 'artist_updates',
    'require_hostname' => true,
],
```

`required` defaults to `false`, so existing Forms and existing external callers remain compatible until a specific published FormVersion deliberately opts in. When verification is required, at least one accepted provider must be configured. Setup validation checks this policy with the rest of the published submission contract.

The Core policy is provider-neutral. It can compare provider identity, evidence age, action, and hostname presence, but it does not call Cloudflare or reinterpret provider-specific payloads. Provider verification remains the responsibility of the authenticated edge/application caller.

Human verification establishes only that the configured anti-bot provider accepted the interaction. It does not prove email ownership, phone ownership, or marketing consent. Contact promotion, channel verification, and Messaging consent remain separate lifecycle decisions.

### Shared authentication contract

Every GET or POST request includes:

```text
X-Engage-Client
X-Engage-Timestamp
X-Engage-Nonce
X-Engage-Signature
```

`X-Engage-Timestamp` is the current Unix timestamp in seconds. `X-Engage-Nonce` is a fresh UUID for this HTTP request. `X-Engage-Signature` is `v1=` followed by the lowercase hex HMAC-SHA256 of this canonical string:

```text
v1
{client_id}
{unix_timestamp}
{lowercase_nonce}
{uppercase_http_method}
{request_path_without_host_or_query}
{lowercase_sha256_of_exact_raw_body}
```

The canonical string uses literal line-feed separators and no trailing line feed. GET signs an empty body and path `/forms/{form_key}`. POST signs the exact JSON bytes sent to `/forms/{form_key}/submissions`.

Authentication fails closed for unknown clients, stale timestamps, malformed nonces, invalid signatures, or replayed request nonces. Each client has a server-owned source, provider, and exact form allowlist. Setup validation requires every allowed form to resolve as the exact current active, published, public FormVersion.

Two replay controls have deliberately different lifetimes:

```text
X-Engage-Nonce
    Short-lived cache identity for one signed HTTP request.
    Reusing it returns request_replayed.

provider + external_id
    Durable FormSubmission identity for one logical submission.
    An identical retry returns the original submission.
    A conflicting retry returns external_id_conflict.
```

After POST transport uncertainty, the caller reuses the same `external_id` but generates a new timestamp, nonce, and signature. The endpoint then returns HTTP 200 with `data.replayed = true`; a newly created submission returns HTTP 201.

Known error responses use one stable JSON envelope:

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The external form intake payload failed validation.",
    "details": {
      "errors": {}
    }
  },
  "request_id": "..."
}
```

Relevant statuses include 400 for malformed JSON or an invalid GET body, 401 for authentication failure, 403 for a form outside the client allowlist, 404 when external access is disabled, 409 for replay/conflict, 413 for body size, 415 for POST content type, 422 for envelope or form-value validation, 429 for rate limiting, and 503 for fail-closed runtime/form unavailability.

External access configuration lives under `forms.external_intake`. Client configuration may be supplied through selected-client config for multiple callers, or through the single-client environment variables documented in `.env.example`. Secrets must contain at least 32 bytes. Provider identities must be unique across configured clients because provider is the durable external-idempotency namespace.

The shared provider webhook inbox is not used for these endpoints. A completed `FormSubmission` is the canonical durable intake record, including the exact raw and normalized answers; creating a second durable transport receipt would duplicate ownership without improving replay safety.

## Contact and tag mapping

Contact mapping is optional. Forms remains useful for anonymous or non-contact intake when no mapping is declared.

The frozen FormVersion `settings` contract is:

```text
submission:
  contact:
    fields:
      email: email
      first_name: first_name
      last_name: last_name
      name: full_name
      phone: phone
    source: engage_sites
    subsource: artist_updates
  tags:
    - field: interests
      values:
        music: interest:music
        tour: interest:tour
        vip: interest:vip
  consents:
    - field: email_marketing_consent
      channel: email
      purpose: marketing
    - field: sms_marketing_consent
      channel: sms
      purpose: marketing
```

`contact.fields` maps supported Contact attributes to frozen form field keys. Email mapping is required whenever Contact mapping exists. Email must map an `email` field, phone must map a `tel` field, and name components must map `text` fields.

The runtime uses Core's existing Contact resolution and create/update actions. Source/subsource apply when the Contact is first created; a later form submission does not overwrite an existing Contact's original acquisition source.

Tag mappings are server-owned value-to-tag allowlists. Submitted values select only among the declared mappings, so a browser cannot author arbitrary Contact tag names. Tag writes are additive and rely on Core's unique `contact_id + tag` identity. An unchecked or omitted interest never removes an existing tag.

Invalid submission rules or Contact/tag mappings fail closed. `FormsSetupValidationContributor` reports these invalid published contracts before runtime handoff.

Consent mappings are server-owned and intentionally contain only `field`, `channel`, and `purpose`. Forms does not accept a consent `scope` or consent-domain key in the mapping. A configured consent field must be a `checkbox` or `boolean` field, and a `true` normalized value means explicit acceptance. `false`, omitted, or null values do not revoke existing permission; revocation remains owned by Messaging unsubscribe/STOP/preference flows.

Forms resolves the consent intent but does not own Messaging state. `FormSubmissionConsentBridge` is an optional shared integration seam. When Messaging is enabled, the Messaging implementation validates the requested channel and purpose and grants against that exact permission boundary. The persisted capture scope is `forms` for provenance/context; Forms does not choose or require a consent-domain policy. When Messaging is disabled, the submitted consent answer remains durable on the pinned FormSubmission/FormSubmissionValue graph but no MessageConsent is created and no later retroactive grant is implied.

A successful bridge grant uses the existing Messaging `GrantMessageConsentAction`. The stored permission identity therefore follows Messaging's configured channel+purpose domain, while the originating form field and FormSubmission/FormVersion IDs are retained as bounded provenance pointers. The bridge does not duplicate disclosure text, provider payloads, interest tags, or human-verification evidence into Messaging metadata. The immutable FormVersion plus the pinned submission/value/provenance graph remains the source evidence.

Interest/tag mappings remain separate from permission. Tags answer who should receive a relevant campaign or broadcast; Messaging consent answers whether that channel+purpose is authorized.

Human verification and channel ownership remain distinct from this explicit consent action. A passed Turnstile attestation does not itself grant consent, and this bridge does not prove control of an email address or phone number.

## Reusable `artist_updates` reference preset

Forms now contributes a reusable `artist_updates` preset from:

```text
config/presets/modules/forms/forms.php
```

The Forms module registers `FormsPresetContributor`, but contribution availability is not runtime activation. A client must explicitly select the `artist_updates` Forms group in its selected preset package before `presets:sync` materializes the definition.

That distinction is intentional:

```text
Forms module enabled
    != artist_updates automatically published

Forms contributor available
    != artist_updates automatically published

selected package includes groups.forms = ['artist_updates']
    -> preset sync may publish the reusable FormDefinition/FormVersion
```

The reusable contract is a public intake with these field identities:

```text
first_name                  optional text
last_name                   optional text
email                       required email
phone                       optional tel
postal_code                 optional text
interests                   optional multi-select
email_marketing_consent     required checkbox + accepted rule
sms_marketing_consent       optional checkbox
```

The current reusable interest tokens are:

```text
music
tour
vip
merch
```

and the server-owned Contact tag mappings are:

```text
email_marketing_consent=true -> interest:general_updates
music                        -> interest:music
tour                         -> interest:tour
vip                          -> interest:vip
merch                        -> interest:merch
```

These tags are segmentation facts, not consent gates.

The server-owned Contact mapping is:

```text
email      <- email
first_name <- first_name
last_name  <- last_name
phone      <- phone
source     = engage_sites
subsource  = artist_updates
```

`postal_code` is intentionally not copied into generic Contact `meta`. Core currently has no first-class postal-code Contact attribute, so the value remains durable typed Forms evidence in `FormSubmissionValue`. If postal geography later becomes a durable/queryable CRM concern, add an intentional first-class Core capability instead of casually expanding Contact meta.

The server-owned Messaging consent intents are channel + purpose only:

```text
email_marketing_consent=true -> email / marketing
sms_marketing_consent=true   -> sms / marketing
```

There is no Forms-authored permission scope. Operational message scopes continue to identify the message family. Messaging authorization uses exact channel + purpose, while the bridge stores `forms` as capture scope for provenance/context. Optional acknowledgement-domain configuration may still improve human-readable acknowledgement topic/copy, but it cannot broaden or narrow permission.

The reusable verification policy is deliberately staged:

```text
required = false
providers = [turnstile]
max_age_seconds = 300
action = artist_updates
require_hostname = true
```

This means an initial server-to-server transport proof may omit verification evidence, while any supplied verification evidence must already satisfy the intended Turnstile provider/action/hostname/freshness contract.

After the matching Artist Sites sender has been proven end to end, a selected client may promote the immutable next FormVersion to required verification with a narrow client override at:

```text
client/{client-key}/config/presets/modules/forms/forms.php
```

For example:

```php
<?php

return [
    'definitions' => [
        'artist_updates' => [
            'settings' => [
                'submission' => [
                    'verification' => [
                        'required' => true,
                    ],
                ],
            ],
        ],
    ],
];
```

Client config merging is associative at those levels, so this override changes only `required`; the reusable provider/action/hostname/max-age policy remains intact. `presets:sync` then publishes a new immutable FormVersion rather than mutating the prior published version.

The current Artist Sites adapter must not be switched to live Core submission merely because the read-only `artist_updates` probe succeeds. The live POST sender must first submit the required consent field(s) expected by the published schema. A GET probe proves authentication/allowlisting/published-contract reachability; it does not prove that the current public-site payload satisfies the submission contract.

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

Deferred until needed:

```text
client-facing form builder UI
internal/operator form builder UI
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
Should form_submissions.status and review_status be separate, or should one lifecycle field be enough?
Should portal_user_id exist immediately, or wait until Portal runtime actions exist?
Should file upload fields route through Documents from the beginning, or be blocked until Documents exists?
When should `form.submitted` automation events be added after the durable submission transaction commits?
```

## Setup/config validation vs submission validation

Forms has two different validation concerns and they must remain separate.

```text
Setup/config validation
    Validates selected form presets and active DB-owned runtime readiness before client handoff.

Submission validation
    Validates one submitted response against the frozen FormVersion schema/rules that apply to that submission.
```

The current setup/config path is:

```text
FormDefinitionConfigContract
    declarative selected-preset shape validation

FormDefinitionConfigContractTargetProvider
    discovers the selected composed Forms definitions

FormSchemaNormalizer
    mutation-time and runtime structural validation

FormsSetupValidationContributor
    validates active DB-owned FormDefinition -> current published FormVersion readiness
    validates frozen submission rules and Contact/tag mapping contracts

setup:validate
    composes those findings through the existing shared setup-validation system
```

Preset sync validates the config contract and normalized schema before writing Form records. This prevents `presets:sync` from being a looser mutation path than `setup:validate`.

Runtime readiness is intentionally DB-owned. An active form must point to one non-archived published current version with a valid supported schema. Setup validation reports missing/unpublished/invalid current snapshots rather than allowing a runtime consumer to select some other historical version.

`FormSubmissionValidator` is the Forms-owned submission validator. It validates each response against the exact resolved FormVersion and does not reinterpret setup/config validation as proof that submitted values are valid.