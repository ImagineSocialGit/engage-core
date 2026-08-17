# Engage Core — Project State Extension Guide

## Purpose

Project State is Engage Core's current-format database state transfer system for controlled clean rebuilds.

It exports the supported client-owned database state into one versioned JSON document, validates that document against the target application's current schema and transfer contract, imports it in dependency-safe order, and leaves runnable work inert until an owner explicitly resumes it.

This guide is for developers extending the Project State contract. Operators should use [`operations/project-state-transfer-runbook.md`](operations/project-state-transfer-runbook.md).

## Current contract

```text
format: engage-core-project-state
version: 11
```

The application accepts only the current root format version and the current version of every configured section. It does not contain in-application translators for older exports.

Current scope:

```text
13 configured section contracts
61 transferred tables when the optional Reporting schema is installed
54 explicitly policy-controlled tables
```

Reporting is the first schema-activated optional section. Its retained `reporting_daily_metrics` table transfers only when the complete Reporting activation schema is installed; Reporting sessions, raw observations, and projection checkpoints remain resettable. Mortgage and Scheduling durable data remain outside the transfer contract. Their durable tables are currently guarded by `must_be_empty` policies. Ephemeral Scheduling slot offers and booking holds are also guarded and must be empty before export.

## Authority

Executable authority lives in:

```text
config/project_state.php
config/project_state/*.php
app/Support/ProjectState/*
tests/Feature/ProjectState/*
```

Documentation describes those contracts but does not replace them.

The central facade is:

```text
ProjectStateManager
    export()
    validate()
    import()
    encode()
    decode()
```

Its collaborators own the actual work:

```text
ProjectStateExporter
    consistent snapshot, schema/policy gates, row export, checksum envelope

ProjectStateDocumentValidator
    envelope, schema, row, identity, reference, and warning validation

ProjectStateImporter
    one import transaction, section/table iteration, row application, counts

ProjectStateContractRegistry
    normalized section, table, reference, policy, and resume contracts

ProjectStateSchemaGuard
    exact schema coverage and policy enforcement

ProjectStateReferenceResolver
    document table index and direct/polymorphic/JSON reference validation

ProjectStateImportRowTransformer
    immediate ID remapping, import value maps, nulling, JSON encoding

ProjectStateDeferredReferenceApplier
    second-pass direct, polymorphic, and JSON-path remapping

ProjectStateImportIdMap
    source-to-target ID mapping for one import operation

ProjectStateResumeItemRecorder
    records imported runtime work that requires explicit resume

ProjectStateResumeManager
    dependency-aware category-by-category runtime restoration
```

These concrete classes are conventionally container-resolvable. Do not add service-provider bindings unless a future dependency genuinely requires an interface or custom lifecycle.

## Transfer or policy: make the decision first

Every application table must be exactly one of:

1. transferred by one configured section;
2. covered by one explicit table policy; or
3. listed as a narrowly justified ignored schema table.

A table must never be both transferred and policy-controlled. A transferred table must never appear in more than one section.

The coverage contract blocks export when a new migration creates an unclassified table.

### Optional schema-activated sections

A section may be marked `optional` only when its transfer contract belongs to schema that may intentionally be absent from an installation.

Optional sections use explicit activation tables:

```php
'optional' => true,
'activation_tables' => [
    'module_table_a',
    'module_table_b',
],
```

Runtime behavior is closed:

```text
none of the activation tables exist
    the section is inactive and omitted from the export/import contract

all activation tables exist
    the section is active and behaves exactly like a required section

only some activation tables exist
    Project State fails closed because the optional module schema is partial
```

An optional section may be absent from a current-version document when the source installation did not have that activation schema. If the target does have the schema, validation and import skip that absent optional section rather than manufacturing state.

The inverse is strict: an export containing a known optional section cannot be imported into a target where that section is inactive; validation fails instead of silently discarding transferred state.

Do not use optional sections to make ordinary required state best-effort. They exist for genuinely optional installed schema such as Reporting. A transferred table from an optional section must still not appear in `table_policies`; non-transferred tables from that module still need explicit policies.

### Transfer the table when

Transfer a table when its rows are durable client-owned state that must survive the rebuild, such as:

- Contacts and relationship history;
- consent and suppression history;
- DB-owned definitions that may have client customizations;
- Webinar registration history;
- current workflow, Campaign, Broadcast, Task, or Route state;
- historical records required to interpret retained activity.

### Use `environment_owned` when

Use `environment_owned` only for state that must be recreated or remain local to the destination environment.

Current examples:

```text
users
password_reset_tokens
sessions
```

This mode does not require the table to be empty. It means the table is deliberately excluded.

### Use `resettable` when

Use `resettable` for local cache, coordination, or operational history that is intentionally rebuilt or discarded.

Current examples include framework cache/locks, failed-job history, dashboard acknowledgements, and `project_state_resume_items`.

This mode does not require the table to be empty.

### Use `must_be_empty` when

Use `must_be_empty` when the state cannot be transferred safely yet and its presence must block the operation.

Examples include:

- unsupported durable module state;
- database-backed queued work;
- ephemeral booking holds and slot offers.

The policy reason must tell the operator why export is blocked.

Do not use `must_be_empty` merely to avoid designing transfer support for data that must actually survive.

### Use `terminal_only` when

Use `terminal_only` for operational receipt tables that may be safely discarded only after every row is terminal.

A terminal policy requires:

```php
[
    'mode' => 'terminal_only',
    'column' => 'status',
    'values' => ['completed'],
    'reason' => '...',
]
```

Export fails when the status column is null or contains a value outside the allowlist.

### Ignored schema tables

`schema_ignored_tables` is for framework/schema bookkeeping that should not be treated as application state.

Current entries are:

```text
migrations
sqlite_sequence
```

Do not use this list as a general escape hatch. Application tables belong in a section or `table_policies.php`.

## Adding a table to an existing section

### 1. Confirm the owning section and import position

Put the table in the section that owns its durable meaning.

Within the section, table order is import order. The root `sections` array in `config/project_state.php` is also import order.

An immediate reference may target only a table whose source-to-target ID mapping exists when the row is transformed. That normally means the referenced table must appear earlier.

Use a deferred reference when the relationship is cyclic, self-referential, or targets a later-imported table.

### 2. Remove any prior table policy

A transferred table cannot also appear in `config/project_state/table_policies.php`.

The schema guard rejects duplicate classification.

### 3. Declare the complete column contract

Every physical table column must appear in `columns`.

Example:

```php
'example_records' => [
    'mode' => 'insert_empty',
    'preserve_id' => true,
    'order_by' => ['id'],
    'columns' => [
        'id',
        'contact_id',
        'status',
        'meta',
        'created_at',
        'updated_at',
    ],
],
```

The contract is deliberately closed:

- a new physical column blocks export until classified;
- a configured column missing from the target schema blocks export;
- every imported row must have exactly the configured keys.

Do not omit columns because they appear unimportant. Classify their import behavior explicitly instead.

### 4. Choose deterministic export ordering

`order_by` must be non-empty and contain only declared columns.

Use stable business ordering where helpful, with `id` as a final tie-breaker when needed.

Examples:

```php
'order_by' => ['key']
'order_by' => ['parent_id', 'sort_order', 'id']
'order_by' => ['id']
```

Deterministic row ordering keeps exports reviewable and checksums stable for the same logical snapshot.

### 5. Declare JSON columns

Every database JSON/text column exported as structured JSON belongs in `json_columns`.

On export, stored JSON is decoded into arrays. Invalid stored JSON blocks export.

On import, non-null JSON values are encoded before database writes.

```php
'json_columns' => [
    'payload',
    'meta',
],
```

Do not classify arbitrary scalar text as JSON. Do not add duplicated snapshots or provenance bundles merely because Project State can transfer them.

## Choosing the import mode

## `insert_empty`

Use `insert_empty` for historical or runtime rows whose IDs are part of the retained relationship graph.

Requirements:

```php
'mode' => 'insert_empty',
'preserve_id' => true,
```

Behavior:

- validation requires the target table to be empty;
- rows are inserted in chunks of 500;
- source IDs are preserved;
- the source-to-target map records the same ID;
- normal Eloquent model events are not fired.

This is suitable only when a clean target is required and safe.

Do not set `preserve_id` to false in this mode; the registry rejects it.

## `upsert`

Use `upsert` for stable DB-owned definitions or settings that may already have been recreated by `presets:sync`.

Requirements:

```php
'mode' => 'upsert',
'identity' => ['key'],
'preserve_id' => false,
```

Behavior:

- rows are matched with `updateOrInsert`;
- the target row's actual ID is read back;
- later references map the source ID to that target ID;
- the target table does not need to be empty.

Composite identity example:

```php
'identity' => [
    'parent_id',
    'key',
],
```

Nullable identity columns must also be listed in:

```php
'nullable_identity' => [
    'optional_context_type',
    'optional_context_id',
],
```

Every non-nullable identity component must be present and non-empty in every document row.

Choose identities that are durable and unique in the destination. Do not use mutable display labels when a stable key exists.

## Reference contracts

## Immediate direct references

Use `references` when the referenced table is imported earlier and its ID map is available during row transformation.

```php
'references' => [
    'contact_id' => 'contacts',
],
```

Validation requires every non-null source ID to exist in the referenced document table.

Import replaces the source ID with the mapped target ID.

## Deferred direct references

Use `deferred_references` when the target ID is not available during the first pass.

```php
'deferred_references' => [
    'next_point_id' => 'flow_route_points',
],
```

First pass:

```text
column is imported as null
```

Second pass:

```text
source ID is mapped and the target row is updated
```

The physical schema must permit the temporary null state. If it cannot, reconsider import order or the schema design.

## Polymorphic references

A polymorphic reference defines one type column, one ID column, and an explicit type-to-table map.

```php
'polymorphic_references' => [
    [
        'type_column' => 'subject_type',
        'id_column' => 'subject_id',
        'targets' => [
            Contact::class => 'contacts',
            Task::class => 'tasks',
        ],
    ],
],
```

Rules:

- type and ID must both be null or both be present;
- unknown types are validation errors;
- a known type targeting an unexported table is an error;
- when the target ID is present in the document, import remaps it;
- when the target table exists in the document but a historical target ID is absent, validation warns and import preserves the raw historical pair without remapping.

Set `'deferred' => true` when the polymorphic target may be imported later.

Do not add broad catch-all morph maps. Every supported type is an explicit restoration promise.

## JSON-path references

Use `json_path_references` only for durable IDs embedded in an existing canonical JSON structure.

```php
'json_path_references' => [
    'meta' => [
        'campaign_id' => [
            'table' => 'campaigns',
            'deferred' => true,
        ],
    ],
],
```

Rules:

- the containing column must be in `json_columns`;
- absent paths are ignored;
- a present non-null source ID must exist in the target document table;
- immediate paths are remapped during row transformation;
- deferred paths are remapped in the second pass.

Prefer normalized columns and relationships for new durable identity. JSON-path references exist to preserve bounded, already-established structures; they are not permission to hide new relational state in `meta`.

## Import transformations

## `null_on_import`

Use `null_on_import` for values that must not cross environments or stale claims that must be cleared.

```php
'null_on_import' => [
    'claim_token',
    'claim_expires_at',
],
```

Validation warns when a non-null value will be cleared.

Typical uses:

- environment-local user IDs;
- runtime claim tokens;
- claim expiration timestamps;
- creator IDs that refer to environment-owned users.

## `import_value_maps`

Use `import_value_maps` to make imported runtime work inert or translate a supported current-format value.

```php
'import_value_maps' => [
    'status' => [
        'active' => 'paused',
        'waiting' => 'paused',
    ],
],
```

Validation reports how many rows will be changed.

Unknown values pass through unchanged. Do not rely on that behavior for unsupported legacy values; validate or translate legacy files before upload.

## `import_value_map_backups`

When resume logic must know the original source status, back it up into a canonical JSON path.

```php
'import_value_map_backups' => [
    'status' => [
        'json_column' => 'meta',
        'path' => 'project_state.original_status',
    ],
],
```

The backup target must be a configured JSON column.

Do not copy entire source rows into metadata. Preserve only the minimal value required for deterministic resume.

## `json_path_value_maps`

Use this for a bounded value translation inside a configured JSON column.

```php
'json_path_value_maps' => [
    'meta' => [
        'runtime.status' => [
            'active' => 'paused',
        ],
    ],
],
```

The containing column must be declared in `json_columns`.

## Runnable work and explicit resume

Import must never blindly replay queued or provider-facing work.

The supported pattern is:

```text
source runtime status
    -> import_value_maps changes it to an inert status
    -> resume_items records the original runnable work
    -> owner resumes the category after workers/providers are ready
```

A resume item uses either a scalar column:

```php
'resume_items' => [
    [
        'category' => 'scheduled_messages',
        'column' => 'status',
        'statuses' => ['pending'],
    ],
],
```

or a JSON path:

```php
'resume_items' => [
    [
        'category' => 'example_category',
        'json_column' => 'meta',
        'path' => 'runtime.status',
        'statuses' => ['pending'],
    ],
],
```

Exactly one source form is allowed.

The category must be returned by:

```php
ProjectStateResumeManager::supportedCategoryKeys()
```

Current supported categories:

```text
message_chain_enrollments
campaign_enrollments
broadcasts
flow_routes
webinar_finalizations
scheduled_messages
message_deliveries
scheduled_message_outbox
automation_events
```

### Adding a new resume category

A new category is a runtime behavior change, not only a config edit.

At minimum:

1. add a category constant;
2. add its label, description, and dependencies to `categoryDefinitions()`;
3. add a `resume()` match branch;
4. implement idempotent, lock-safe category handling;
5. complete each resume item only after its durable outcome is known;
6. leave queue/provider failures pending so the owner can retry;
7. expose safe outcome codes;
8. add focused resume tests;
9. add the category to the operator runbook;
10. verify `ProjectStateFinalArchitectureTest` category parity.

Provider submission ambiguity must fail closed. Do not blindly resend work whose provider outcome is unknown.

## Adding a new section

Create:

```text
config/project_state/{section_key}.php
```

Return:

```php
return [
    'version' => 1,
    'tables' => [
        // dependency-safe table order
    ],
];
```

Register it once in `config/project_state.php`:

```php
'sections' => [
    // earlier dependencies
    'new_section' => require __DIR__.'/project_state/new_section.php',
    // later dependents
],
```

The registry rejects the same table under two sections.

Choose the root position from actual reference dependencies, not module menu order.

After adding the section:

- remove transferred tables from policies;
- add round-trip coverage;
- update the extension guide's current scope only when the change is merged;
- update the transfer runbook if the new section adds an operational prerequisite or resume category.

## Versioning and compatibility

Project State is exact-current-format only.

### Bump a section version when

Bump the owning section version when its serialized table contract or import semantics change, including:

- adding/removing/renaming a transferred table;
- adding/removing/renaming a column;
- changing identity or preserved-ID behavior;
- changing reference structure;
- changing import transformations that alter restored meaning;
- adding required resume semantics.

### Bump the root version when

Bump the root version when a previously exported full document is no longer directly accepted or would restore different meaning.

For the current implementation, most section contract changes that make prior files incompatible should also bump the root version because the application has no embedded translation layer.

### Do not bump versions for

Do not bump the document contract for:

- internal class extraction/refactoring;
- dependency-injection cleanup;
- tests that only lock existing behavior;
- documentation updates;
- error-message cleanup that does not change accepted data;
- performance changes that preserve serialization and restoration semantics.

### Older files

Do not weaken current validation to accept an older shape.

Transform an older export externally into the current contract, verify the transformed document, and preserve the original export and transformation record.

## Required test coverage

At minimum, run:

```bash
php artisan test tests/Feature/ProjectState
php artisan test
php artisan optimize:clear
php artisan setup:validate
```

Add or update focused tests as appropriate.

### Coverage contract

`ProjectStateCoverageContractTest` must continue proving:

- every actual table is transferred, policy-controlled, or ignored;
- transferred and policy tables do not overlap;
- export uses one database transaction;
- new tables and columns block export until classified;
- unsupported durable rows block export;
- terminal-only policy behavior;
- unsafe polymorphic references fail closed.

### Architecture contract

`ProjectStateFinalArchitectureTest` must continue proving:

- every collaborator auto-resolves;
- `ProjectStateManager` remains the five-operation facade;
- table ownership is unique;
- configured resume categories are supported;
- duplicate document tables are rejected.

### Round-trip tests

Add round-trip coverage for every newly supported module/section or meaningful contract expansion.

A round-trip test should prove:

```text
seed representative source rows
export
prepare a clean compatible target
run target setup/preset materialization when required
validate
import
assert identities, relationships, JSON, and timestamps/meaning
assert runnable work is inert
assert resume tracking exists when required
```

Also add negative validation tests for broken references or unsupported polymorphic types.

Use `assertEquals()` for ordered array structures and `assertEqualsCanonicalizing()` for intentionally unordered arrays. Reserve `assertSame()` for scalar/type-sensitive assertions.

### Controller/operations tests

Update controller tests when changing:

- owner access;
- password requirements;
- confirmation words;
- upload limits;
- export download behavior;
- validation/apply report shape;
- resume dependency behavior.

## Review checklist

Before merging a Project State contract change:

```text
[ ] Table has exactly one owner: section or policy
[ ] Root section order is dependency-safe
[ ] Table order inside the section is dependency-safe
[ ] Complete physical column list is declared
[ ] Deterministic order_by is declared
[ ] Correct insert_empty or upsert mode selected
[ ] Stable upsert identity is complete
[ ] JSON columns are explicit
[ ] Immediate/deferred references are correctly classified
[ ] Polymorphic type map is explicit and closed
[ ] JSON-path IDs are mapped only where already canonical
[ ] Environment-local values use null_on_import
[ ] Runnable work imports inert
[ ] Resume category exists and has tested dependencies
[ ] Section/root versions are deliberately evaluated
[ ] Coverage and round-trip tests are updated
[ ] Full suite and setup validation are green
[ ] Operator runbook is updated for new operational behavior
```

## Anti-patterns

Do not:

- silently ignore a new table or column;
- transfer authentication credentials or sessions;
- preserve Redis jobs as though they were database state;
- import runnable work directly into an active/sending/processing state;
- use unknown polymorphic types or raw unvalidated IDs;
- add redundant identity, configuration, labels, timestamps, or object snapshots to JSON merely to simplify export;
- create one-to-one payload/meta tables that retain the same bytes;
- loosen current-format validation to accept undocumented legacy shapes;
- resume provider-facing work before provider idempotency and worker readiness are understood;
- treat a successful import as proof that external side effects were rolled back or replayed.
