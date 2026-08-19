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

Mapped non-blank CSV values take precedence over profile defaults. Profile defaults
are server-side configuration and are not accepted from request input.

The preview remains authoritative. Operators must be able to review and change all
suggested CSV column mappings before import.

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