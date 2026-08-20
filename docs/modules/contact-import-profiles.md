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