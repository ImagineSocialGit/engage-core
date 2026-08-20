# Contact Import Domain Ingestion

This document records the reusable import execution contract used after Core persists a canonical Contact and its row-level `ContactImportOccurrence` evidence.

## Ownership

Core owns:

- CSV/TXT parsing and field mapping
- canonical Contact identity resolution
- import batches
- durable row-level `ContactImportOccurrence` evidence
- the module-neutral import extension registry/context

Modules own durable facts derived from an imported row.

Core must not interpret Mortgage, Relationships, Location, Campaigns, Messaging, or other module-specific fields.

## Handler context

`ContactImportHandler` receives one `ContactImportContext` containing:

- the resolved canonical Contact
- the active ContactImportBatch
- the already-persisted ContactImportOccurrence
- the normalized CSV row
- the submitted mapping
- a module-neutral `mappedValue()` helper

The occurrence is created before module handlers run so every handler consumes the same durable Core row evidence.

## Relationships import

Relationships contributes relationship mapping fields and an idempotent handler.

The handler:

- no-ops when no relationship key is mapped
- validates relationship/stage keys through the Relationships public action
- preserves an existing meaningful relationship acquisition source/subsource during later overlapping imports
- may update relationship stage when an intentionally mapped later import supplies a current stage
- retains one current row per Contact + relationship key

Relationship-specific business contexts remain separate even when they point at the same canonical Contact.

## Mortgage import

Mortgage contributes grouped mapping fields for:

- current consumer mortgage facts
- repeatable loan history
- primary/co-borrower snapshots
- buyer/listing Realtor loan snapshots
- Mortgage Realtor specialization
- time-bounded Realtor production observations

Mortgage loan import is idempotent by source-system + source-record identity when supplied, otherwise by a deterministic source fingerprint. Blank incoming values do not erase populated loan facts.

A primary borrower links to the canonical imported Contact. A co-borrower may link only to an already-existing Contact resolved by a distinct exact email. When a co-borrower shares the primary borrower's email, the co-borrower remains a snapshot rather than falsely linking both people to one Contact identity.

Loan Realtor snapshots likewise reuse an existing Contact only by exact email; they do not create additional Contacts.

Realtor production rows are observations, not lifetime counters. When an export provides trailing-period counts but no observation date, the import batch date is used as the period-ending observation date.

## Optional Location composition

Relationship-area import is app-level optional composition.

When both Relationships and Location are available and Location is explicitly enabled, the import registry exposes fields for an existing `LocationArea` key and optional primary-area flag. The integration handler delegates through `RelationshipLocationAreaBridge` and the Location-owned assignment action.

When Location is not explicitly enabled, these fields/handler are not registered. Relationships and Mortgage remain independently functional.

This does not change Scheduling's location snapshots, normalization, or travel-time behavior.

## Client profile layer

Known client/export CSV shapes can now contribute filename hints, mapping aliases, and trusted dataset defaults through the generic Contact import-profile registry. The profile layer is configuration-driven and validated by `setup:validate`; generic tests do not assert a particular client's profile inventory or values.

## Intentionally deferred

This import/domain layer still does **not** add:

- XLS/XLSX parsing
- fuzzy Contact identity matching
- automatic creation of co-borrower or Realtor Contacts
- automatic lifecycle/status interpretation from historical export labels
- campaign enrollment
- imported marketing consent grants
- reply-response orchestration

Campaign family/priority arbitration is now Campaign-owned and intentionally remains outside the import handlers. A later orchestration slice may request Campaign enrollment after CRM/domain state and scoped Messaging consent are established.