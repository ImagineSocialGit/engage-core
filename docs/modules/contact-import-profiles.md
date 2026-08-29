# Contact Import Profiles

Contact import profiles are optional configuration for known CSV export shapes.
They do not create a second import engine.

## Runtime boundary

Core continues to own:

- CSV/TXT upload and parsing;
- canonical Contact matching by the current identity policy;
- mapping preview;
- ContactImportBatch and ContactImportOccurrence provenance;
- invoking registered domain import handlers after the Contact and occurrence exist.

A profile may provide only:

- filename hints used to select one unambiguous profile;
- header aliases used to preselect mapping choices;
- trusted dataset-level default values for registered import fields;
- optional treatment-target applicability for the known population;
- optional registered post-import behavior.

Mapped non-blank CSV values take precedence over profile defaults. Explicit operator
import treatment takes precedence over both when a treatment owns the same destination.
Profile defaults are server-side configuration and are not accepted from request input.

The preview remains authoritative. Operators must be able to review and change all
suggested CSV column mappings before import.

## Add versus update imports

The Contact import workspace has two explicit operating modes that share the same CSV
parser, mapping registry, treatment registry, and module-owned domain handlers:

- `add`: the normal audience/list import path. New Contacts may be created and existing
  exact-email matches may be updated. Detected profile defaults and configured
  post-import behavior are available.
- `update`: enrichment of Contacts that already exist. Exact normalized email remains
  the identity key; a row with no existing exact-email Contact is skipped rather than
  created. Profile defaults and automatic profile post-import processors are disabled.
  Explicit operator-selected treatment remains available.

The selected mode is stored server-side with the staged CSV. The process request cannot
switch an update import back into add mode by submitting another browser value.

Update mode is intentionally appropriate for later domain enrichment such as mortgage
history, relationship facts, or other module-owned fields. Module handlers still receive
the mapped row and existing Contact context. Their normal idempotency and blank-value
rules remain authoritative.

The preview presents required and recognized fields first. Other registered fields remain
available behind an additional-fields control so optional modules can contribute deep
mapping capabilities without making every routine import look like a full platform schema.

## Import treatment is separate from profiles

Profiles describe a known source export and may suggest mappings/defaults. They do not
silently decide current CRM business treatment.

The preview has a separate generic treatment layer for decisions such as:

- apply one Contact Status to every successfully imported row;
- map the distinct values of a source status column to active CRM statuses;
- add fixed or source-value-mapped Contact tags;
- apply/map configured Relationships and relationship-specific stages.

The staged CSV is profiled before processing so the operator can see distinct source
values and row counts while defining a value map. Unmapped source values remain
unchanged and are recorded for review rather than being inferred.

Treatment targets are registry-driven. Optional modules contribute their own targets
without adding their models or business rules to Core.

### Profile-specific treatment applicability

A known import profile may narrow the treatment controls that are relevant to that
source population. This is presentation/acceptance policy for the import workflow; it
does not move Contact Status, Relationships, stages, or tags into Core.

The effective profile contract accepts an optional `treatment_targets` list. Client
configuration may supply that list through a separate client applicability overlay:

```text
client/{client-key}/config/contact_import_treatments.php
    -> contact_import_treatments.profiles.{profile_key}
```

Semantics:

```text
profile has no treatment-target list
    all currently available registered treatment targets remain visible/accepted

profile has an explicit treatment-target list
    preview renders only those registered targets
    process rejects any browser-submitted target outside that list

uploaded file matches no profile
    full advanced registered treatment catalog remains available
```

This allows the operator surface to reflect the population being imported instead of
showing every technically possible treatment on every known file. For example, a client
may expose Contact Status + Tags for consumer/lead profiles while exposing Relationship
+ Relationship Stage + Tags for Realtor/partner profiles. The rule is configured by the
client profile; Core does not hard-code "consumer", "Realtor", or another business noun.

A Contact may still participate in more than one business context. A narrowed import
profile is a routine-workflow affordance, not a restriction on the canonical Contact
model or later relationship/status management.

## Setup validation

`php artisan setup:validate` validates the effective client import-profile
configuration through Core's registered import-profile registry.

Validation is generic. It does not encode a particular client's profile names,
dataset inventory, labels, module list, or expected source files.

The validator checks that:

- the profile container and each profile definition have the supported shape;
- profile keys and fields satisfy the reusable profile contract;
- aliases/defaults reference import fields actually registered for the current
  enabled module composition;
- profile defaults do not attempt to invent Contact email identity;
- filename hints from different profiles do not overlap in a way that can make
  one uploaded filename match more than one profile.

This means client configuration correctness belongs in setup validation and
deployment/setup checks rather than in tests that hard-code one client's current
configuration contents.

Module-owned import handlers remain responsible for validating row-level business
values at import time. Where a module later needs static validation of one of its
own profile defaults, that module should contribute the validation without moving
the business rule into Core.

## CSV header normalization

Core normalizes the staged CSV header row once and uses that same normalized header set
for preview and processing. A leading UTF-8 BOM on the first header is removed before
profile alias matching, operator mapping, row combination, and import-batch provenance
are evaluated. This keeps common spreadsheet-exported UTF-8 CSV files from producing a
preview mapping that differs from the row keys used during processing.

Other header text is preserved apart from surrounding whitespace. Core does not silently
rename arbitrary columns or apply fuzzy header matching outside configured profile aliases.

## Excel files

Engage Core intentionally does not support XLS/XLSX runtime parsing. Convert workbook
sheets to CSV before import. Import profiles match the resulting filename/header text
and therefore do not require an Excel dependency.

## Domain ownership

Profiles translate source exports into registered import field keys; they do not move
field ownership into Core. Relationships, Mortgage, Location bridges, and future
modules continue to register and consume their own fields through ContactImportHandler.

Client/export-specific profiles belong in:

`client/{client-key}/config/contact_imports.php`

Core `config/contact_imports.php` remains empty by default.

## Identity safety

Do not use profile defaults for Contact email identity. Profiles may not define a
default `email`. Shared-email co-borrower handling and domain-history reconciliation
remain the responsibility of the existing import/domain handlers.
## Post-import behavior

A profile may optionally declare server-owned `post_import` behavior. Processor identity
and business keys remain server-owned and are validated through the same profile registry
used by `setup:validate`.

A configured processor may optionally expose a bounded operator-input contract on the
preview screen. A processor that implements Core's operator-config provider may also expose
one import-wide decision even when no detected client profile configured that optional
module behavior. The processor still owns the server-side defaults and validation; browser
input cannot invent a processor or replace server-owned business identity such as a
Campaign key.

Row-level post-import behavior runs only after Core has persisted the Contact and
`ContactImportOccurrence`, module-owned domain handlers have consumed the row, and
operator-selected treatments have been applied.

Processors may also implement the batch-finalization contract. Those finalizers run only
after every CSV row has completed. Batch finalization is intended for behavior that must
not become externally actionable while a large import is still partially processed.

The mapping preview must show every configured post-import behavior before the
operator submits the import. Profiles must never hide consent grants, Campaign
enrollment, or similar side effects.

Post-import processors are registry-driven optional capabilities. Core does not import
Messaging or Campaigns. App-level composition registers processors only when their
owning modules are enabled.

Current reusable processors are:

- `marketing_permission`: every add-import exposes an explicit operator decision when
  Messaging is enabled. If prior marketing permission was already collected elsewhere,
  the operator must select the channels that have permission and attest that the grant
  already exists. Only those selected channels are imported. If the operator chooses
  `No / I’m not sure`, this processor is removed from the durable post-import plan and
  no marketing consent is created. Existing active consent is reused and a currently
  revoked channel is never reactivated merely because the Contact appears in a later
  import. A missing/invalid SMS destination does not prevent available email permission
  from being imported; the row records a partial/reviewable outcome.
- `campaign_enrollment`: requests enrollment in one configured Campaign through the
  normal Campaign action. Existing open enrollment remains idempotent and Campaign
  family/priority arbitration remains authoritative.
- `campaign_launch_timing`: does **not** select a Campaign audience and does **not**
  independently enroll a Contact. It expects the normal import treatment/lifecycle path
  to create the configured Campaign enrollment, requires one batch-level `Start sending`
  date/time from the operator, and applies that time to the still-unmaterialized first
  MessageChain action only after the entire import batch has finished. Existing open
  Campaign enrollments that predate the import are preserved.

These processors do not make post-import orchestration transactional with Contact
identity/domain ingestion. A blocked/unavailable Campaign or one unavailable channel
is recorded for review without undoing the successfully imported Contact row.

Example generic shape:

```php
'post_import' => [
    'marketing_permission' => [
        'channels' => ['email', 'sms'],
        'scope' => 'lead_nurture',
    ],
    'campaign_enrollment' => [
        'campaign_key' => 'lead_nurture',
    ],
    'campaign_launch_timing' => [
        'campaign_key' => 'lead_nurture',
    ],
],
```

Client profiles should enable Campaign post-import behavior only when the intended
Campaign policy is actually defined. A profile may constrain `marketing_permission`
channels/scope, but the operator confirmation is still required at import time. Do not add
placeholder Campaign keys merely to make an import profile look complete.

## Background processing and recovery

Contact CSV imports are background work. The browser request validates the operator's
mapping/treatments, creates a durable `ContactImportBatch`, records an environment-local
`ContactImportRun`, queues the first bounded chunk, and returns immediately.

The default chunk size is 500 CSV rows (`contact_imports.processing.chunk_rows`). A batch
has only one sequential chunk in flight. This preserves deterministic duplicate identity
handling and row order while keeping each queue job bounded.

`ContactImportRun` owns the staged CSV path, byte/row checkpoint, processing counters, and
the immutable execution snapshot needed by workers. It is intentionally not Project State
data: the staged CSV exists only in the current environment. Active run rows block Project
State export; failed run diagnostics may remain local.

Each chunk processes inside a database transaction. The Contact changes,
`ContactImportOccurrence` rows, module import handlers, treatments, post-import processor
effects, and the run checkpoint commit together. If a worker fails before commit, the
entire chunk rolls back and the retry resumes from the same durable byte/row checkpoint.
The unique `(contact_import_batch_id, row_number)` occurrence constraint remains the
successful-row provenance/idempotency boundary.

`ContactImportBatch.contact_count` is the known CSV data-row total as soon as the import
is queued. `successful_count` and `failed_count` advance after each committed chunk.
The batch detail page reads `ContactImportRun.processed_rows` for live progress.

After all rows commit, a separate finalization job runs the existing batch-finalizer
contract exactly once within the final database transaction. On success it marks the
batch completed and removes the transient run record/staged CSV. Campaign launch timing
therefore cannot become actionable while only part of a batch has been imported.

A terminal queue failure marks both the batch and local run failed with durable diagnostics.
The local failed run is intentionally not transferred through Project State.