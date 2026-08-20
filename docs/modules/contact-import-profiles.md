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
- trusted dataset-level default values for registered import fields.

Mapped non-blank CSV values take precedence over profile defaults. Explicit operator
import treatment takes precedence over both when a treatment owns the same destination.
Profile defaults are server-side configuration and are not accepted from request input.

The preview remains authoritative. Operators must be able to review and change all
suggested CSV column mappings before import.


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

A profile may optionally declare server-owned `post_import` behavior. This is not
submitted by the browser and is validated through the same profile registry used by
`setup:validate`.

Post-import behavior runs only after Core has persisted the Contact and
`ContactImportOccurrence`, module-owned domain handlers have consumed the row, and
operator-selected treatments have been applied.

The mapping preview must show every configured post-import behavior before the
operator submits the import. Profiles must never hide consent grants, Campaign
enrollment, or similar side effects.

Post-import processors are registry-driven optional capabilities. Core does not import
Messaging or Campaigns. App-level composition registers processors only when their
owning modules are enabled.

Current reusable processors are:

- `marketing_permission`: silently imports Marketing permission for explicitly
  configured email/SMS channels and one operational scope. Messaging canonicalizes
  that request through the current channel/purpose consent-domain policy. Active
  consent is reused. A currently revoked channel is never reactivated merely because
  the Contact appears in a later import; that requires a separate valid re-grant
  event. A missing/invalid SMS destination does not prevent available email permission
  from being imported; the row records a partial/reviewable outcome.
- `campaign_enrollment`: requests enrollment in one configured Campaign through the
  normal Campaign action. Existing open enrollment remains idempotent and Campaign
  family/priority arbitration remains authoritative.

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
],
```

Client profiles should enable these only when the intended Campaign and permission
policy are actually defined. Do not add placeholder Campaign keys merely to make an
import profile look complete.