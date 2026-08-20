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
- module-neutral value resolution with explicit treatment overrides

The occurrence is created before module handlers run so every handler consumes the same durable Core row evidence.

## Operator treatment layer

The import preview exposes reusable treatment targets contributed through Core's `ContactImportTreatmentRegistry`. A target can support either:

- one fixed destination applied to every successfully imported row; or
- a CSV source column whose distinct values are explicitly mapped to CRM destinations.

The preview profiles the staged CSV and shows source-value counts before import. Up to 100 distinct nonblank values per column are displayed; higher-cardinality remainder values stay unchanged rather than being guessed. Blank and unmapped values are counted separately.

Treatment precedence is intentional:

```text
operator treatment
    -> mapped CSV value
        -> import-profile default
```

Treatment changes current CRM/domain state only. Source evidence remains source evidence: raw/profile source, subsource, status evidence and each treatment's source column/value remain recorded in import provenance.

Current generic treatment targets include Contact Status and additive Contact Tags. Relationships contributes Relationship and Relationship Stage targets when enabled. Relationship Stage destinations carry their relationship identity so a stage can never be applied without its owning relationship. Modules may add future targets without teaching Core their business vocabulary.

The former one-off `status_mapping` request path is retired. Contact status now uses the same generic treatment mechanism as other controlled business values.

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
- automatic lifecycle/status interpretation from historical export labels without explicit operator mapping
- campaign enrollment
- imported marketing consent grants
- reply-response orchestration

Campaign family/priority arbitration is now Campaign-owned and intentionally remains outside the import handlers. The next orchestration layers can use explicit import treatment as the CRM/domain decision, then establish scoped Messaging consent and request Campaign enrollment.