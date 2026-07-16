# Engage Core — System-Wide Model Creation and Persistence Bloat Audit Plan

## Status

This is an immediate cross-module audit track, not deferred product backlog.

## Purpose

This document defines a broad audit for every path that creates or materially updates database records in Engage Core.

The goal is not simply to make JSON columns smaller. The goal is to establish a consistent persistence contract across modules so the database stores what is operationally necessary without becoming a duplicate object graph, configuration archive, request dump, or debugging scratchpad.

This audit should be performed in a clean thread because it spans the entire application and will require current project files, write-path inventories, runtime samples, and focused regression tests.

## Core principle

Persist data because it serves a durable purpose:

- Canonical business state
- Operational querying
- Deterministic execution
- Retry and recovery
- Compliance evidence
- Auditability
- Dedupe and idempotency
- Historical truth that cannot be safely reconstructed

Do not persist data merely because it was available in memory when a model was created.

## What the audit covers

The audit should include every database-writing mechanism, not only classes named `Data`, `DTO`, or `Payload`.

### Eloquent write paths

Search for and inspect:

- `Model::create()`
- `Model::forceCreate()`
- `Model::firstOrCreate()`
- `Model::updateOrCreate()`
- `Model::firstOrNew()` followed by `save()`
- `fill()` and `save()`
- `update()`
- `saveQuietly()`
- `replicate()`
- relationship `create()` and `createMany()`
- `upsert()`
- `insert()` and `insertOrIgnore()`

### Query-builder writes

Inspect:

- `DB::table(...)->insert()`
- `DB::table(...)->update()`
- `DB::table(...)->upsert()`
- raw SQL writes

### Indirect and asynchronous writes

Include:

- Controllers
- Actions and services
- Jobs
- Event listeners and subscribers
- Observers
- Model events
- Console commands
- Importers
- Sync actions
- Seeders and presets
- Webhook handlers
- Provider adapters
- FlowRoutes handlers
- Campaign schedulers
- Message planners
- Notification builders
- Test/dev utility actions that may later be reused in production

### Data assembly paths

Audit all classes that build arrays or objects later persisted:

- `*Data`
- `*Dto` / `*DTO`
- `*Payload`
- `*Context`
- `*Snapshot`
- `*Builder`
- `*Factory`
- `*Resolver`
- `*Mapper`
- `*Normalizer`
- `*Serializer`
- `toArray()` implementations
- model casts returning arrays

## Audit objectives

For each table and write path, answer:

1. What is the canonical source of each value?
2. Why must this value be persisted?
3. Is it queried directly?
4. Is it required for retry or deterministic execution?
5. Is it required for compliance or historical evidence?
6. Can it be derived safely from first-class relationships?
7. Can it change after the row is created, and should history preserve the old value?
8. Is the same fact stored elsewhere in the same row?
9. Is the same nested object copied across many rows?
10. Is raw provider or request data retained longer than necessary?
11. What is the expected row volume and retention period?
12. What breaks if this value is removed?

## Persistence classification system

Classify every persisted field or nested JSON key into one of these categories.

### A. Canonical state

The authoritative business value.

Examples:

- Contact email
- Registration status
- Task due date
- Consent grant timestamp

Usually keep.

### B. Operational index or routing value

Needed for efficient querying, dispatching, or ownership.

Examples:

- `channel`
- `purpose`
- `scope`
- `status`
- `send_at`
- morph IDs
- dedupe keys

Usually keep as first-class columns.

### C. Immutable execution snapshot

A historical value intentionally frozen because later changes must not alter an already planned action.

Examples:

- Final scheduled email subject/body
- A route-plan definition snapshot
- A pricing value accepted at checkout

Keep only the minimum snapshot required for deterministic behavior.

### D. Late-bound execution input

A key or reference intentionally resolved at send/run time.

Examples:

- Playback URL not available when registration occurs
- Provider access token reference
- Current unsubscribe URL

Persist the stable lookup key and context identity, not an entire future object graph.

### E. Compliance or audit evidence

Required to prove what happened and under what authority.

Examples:

- Consent ID
- Source
- IP address
- User agent
- Policy/version identifier
- Final acknowledgement intent coverage

Keep deliberately and document retention.

### F. Derived display data

Convenience values that can be reconstructed from canonical fields.

Examples:

- Repeated full name when first and last name already exist
- Formatted webinar date repeated in every reminder

Usually remove unless the frozen display value is historically significant.

### G. Redundant duplicate

The same fact stored multiple times in the same row or graph.

Examples:

- `contact_id` at the top level, inside `tokens`, inside `contact`, and inside `context.contact`
- Full webinar object in both `tokens.webinar` and `context.webinar`

Remove.

### H. Ephemeral execution/debug data

Useful only during one request or for temporary diagnosis.

Examples:

- Entire resolver working set
- Loaded relationships
- Intermediate token maps
- Full configuration branches

Do not persist by default.

### I. Raw external payload

Provider webhook or API response data.

Keep only when there is a clear replay, dispute, debugging, or compliance need. Prefer normalized first-class fields plus a retained raw payload with explicit retention and redaction rules.

## Phase 1 — Build the write inventory

Generate a current project tree and a database-write-site dump before proposing code changes.

Suggested discovery command:

```bash
mkdir -p file_dumps
{
    rg -n --glob '*.php' --glob '!vendor/**' --glob '!storage/**' --glob '!bootstrap/cache/**' \
        '::create\(|::forceCreate\(|::firstOrCreate\(|::updateOrCreate\(|->create\(|->createMany\(|->save\(|->saveQuietly\(|->update\(|::upsert\(|::insert\(|insertOrIgnore\(|DB::table\(' \
        app database routes tests client
} > file_dumps/database-write-sites.txt
```

Generate a second inventory for array/data builders:

```bash
{
    find app client -type f \( \
        -name '*Data.php' -o \
        -name '*DTO.php' -o \
        -name '*Dto.php' -o \
        -name '*Payload.php' -o \
        -name '*Context.php' -o \
        -name '*Snapshot.php' -o \
        -name '*Builder.php' -o \
        -name '*Resolver.php' -o \
        -name '*Mapper.php' -o \
        -name '*Normalizer.php' -o \
        -name '*Serializer.php' \
    \) -print
} | sort -u > file_dumps/persistence-data-builders.txt
```

Generate a model/table inventory:

```bash
{
    find app -type f -path '*/Models/*.php' -print
    find database/migrations -type f -name '*.php' -print
} | sort -u > file_dumps/models-and-migrations.txt
```

These inventories should be reviewed together. A write site is not understood until the builder that assembled its attributes is also traced.

## Phase 2 — Group by persistence domain

Audit in domain batches rather than file order.

Recommended groups:

1. Messaging and scheduled delivery
2. Webinars and registrations
3. Campaigns and enrollments
4. FlowRoutes plans, progress, and snapshots
5. Automation behavior/opportunity records
6. Tasks and links
7. Core contacts, workflow, imports, and notes
8. Forms and submissions
9. Broadcasts and recipients
10. Inbound messages and webhooks
11. Scheduling and appointments
12. Portal and access grants
13. Locations and geocoding
14. Documents and uploads
15. Commerce and provider records
16. Internal notifications and team preferences

## Phase 3 — Create a model audit worksheet

Use one worksheet entry per table/model.

### Template

```markdown
## Model: ScheduledMessage

- Table: `scheduled_messages`
- Expected volume: High
- Retention: To determine
- Primary write paths:
  - `...`
- Data builders:
  - `...`
- First-class operational columns:
  - `...`
- JSON columns:
  - `payload`
  - `dispatch_keys`
  - `meta`
- Largest observed row:
  - `...`
- Duplicated facts:
  - `...`
- Required immutable snapshot:
  - `...`
- Required compliance evidence:
  - `...`
- Safely derivable values:
  - `...`
- Candidate removals:
  - `...`
- Compatibility strategy:
  - `...`
- Required tests:
  - `...`
```

## Phase 4 — Measure actual row sizes

Do not rely only on visual inspection. Sample realistic rows after fresh migrations, imports, registrations, campaign scheduling, and provider activity.

For MySQL, collect approximate JSON byte sizes with queries such as:

```sql
SELECT
    id,
    OCTET_LENGTH(payload) AS payload_bytes,
    OCTET_LENGTH(meta) AS meta_bytes,
    OCTET_LENGTH(dispatch_keys) AS dispatch_keys_bytes
FROM scheduled_messages
ORDER BY payload_bytes + meta_bytes DESC
LIMIT 100;
```

For every high-volume table, record:

- Average row payload size
- Median
- 95th percentile
- Maximum
- Rows created per common workflow
- Expected monthly volume
- Retention period

The important metric is not only row size. It is:

```text
row size × rows per workflow × workflow volume × retention
```

## Phase 5 — Trace duplication within and across rows

### Intra-row duplication

Look for the same value repeated in multiple JSON paths.

Common patterns:

- Top-level ID plus nested ID copies
- `tokens.contact` plus `context.contact` plus `contact`
- Raw and normalized versions retained without a use case
- Full model arrays plus morph references

### Inter-row duplication

Look for large immutable structures copied into every scheduled item or progress row.

Examples:

- Full contact snapshots copied into every reminder
- Full webinar snapshots copied into every reminder
- Entire route definition copied into both plan and every plan item
- Entire form schema copied into every form value
- Full provider payload copied into multiple related records

Prefer a single intentional snapshot at the correct ownership level, referenced by ID from child rows.

## Phase 6 — Decide schedule-time versus send-time resolution

Every delayed action needs an explicit resolution policy.

### Resolve and freeze at schedule time when:

- Later edits must not change an already planned communication.
- Exact historical content must be auditable.
- Provider-ready retry must not depend on mutable templates.

Persist the final minimal send-ready content.

### Resolve at send time when:

- The value does not exist yet.
- Freshness is required.
- The latest canonical value is intentionally desired.

Persist only:

- The stable intent/template key
- The context reference
- The specific late-bound token names
- Any required fallback policy

Do not persist every available token merely because one token may be late-bound.

## Phase 7 — Prioritize high-impact areas

### Priority 1: Scheduled messages

Audit first because they are high-volume and currently show repeated contact, webinar, registration, series, token, and context structures.

Target shape:

- First-class routing/status columns remain.
- `payload` contains compact provider-ready content and necessary destinations/links.
- `meta` contains compact behavior, condition, consolidation, consent, provenance, and delivery information.
- Morph columns identify recipient and context.
- Avoid whole-model-style nested arrays.

### Priority 2: FlowRoutes snapshots and progress

Review whether route, point, capability, plan, plan item, and progress records duplicate the same definitions across several levels.

Preserve deterministic historical plans, but ensure the snapshot exists at the narrowest correct ownership level.

### Priority 3: Provider raw payloads

Audit:

- Webinar registration provider responses
- Webhooks
- Inbound messages
- Geocoding
- Commerce providers

Define retention, redaction, and whether raw payloads belong in hot operational tables or an archive.

### Priority 4: Forms

Review overlap among:

- Submission `payload`
- Submission `raw_payload`
- Normalized `form_submission_values`

Avoid retaining three complete representations without a defined reason.

### Priority 5: Campaign and broadcast orchestration

Review repeated snapshots, recipient arrays, scheduled-message ID arrays, and source context stored across enrollment, recipient, and message rows.

## Phase 8 — Establish automated guardrails

### Architecture tests

Add tests that reject known bloat patterns in high-volume payloads.

Examples:

- Scheduled-message payload must not contain full `contact`, `webinar`, and `webinar_registration` graphs simultaneously.
- Payload and metadata may not repeat recipient/context IDs across several nested paths without an approved exception.
- Provider-ready rows must not contain loaded relationship collections.

### Size-budget tests

Use representative fixtures and assert reasonable serialized-size budgets.

Example concept:

```php
$this->assertLessThan(4096, strlen(json_encode($scheduledMessage->payload)));
```

Budgets must be chosen from real workflows, not arbitrary aesthetics. Separate budgets by message type when necessary.

### Persistence-contract tests

For each high-volume model, verify:

- Required deterministic data is present.
- Required compliance evidence is present.
- Derived and duplicate object graphs are absent.
- Retry still works after related mutable config changes.
- Historical rows remain readable.

### Development diagnostics

Add optional local logging or metrics for oversized JSON writes. Do not introduce a global production model hook that can unexpectedly block legitimate writes.

A safer approach is a small service used by selected high-volume writers to report:

- Serialized byte count
- Largest top-level keys
- Model/table
- Write path or intent key

## Phase 9 — Retention and archival policy

Trimming new writes is only part of database management.

Define retention separately for:

- Pending and retryable messages
- Sent message content
- Provider delivery metadata
- Failed jobs
- Raw webhook/provider payloads
- Consent evidence
- Automation histories
- Debug-only data

Compliance records may need long retention. Large raw payloads may not.

Consider:

- Keeping compact operational rows in the primary database
- Moving large historical raw payloads to object storage or archive tables
- Redacting secrets and signed URLs
- Pruning expired provider diagnostics
- Retaining hashes or references where full content is unnecessary

## Phase 10 — Backward compatibility strategy

Do not require an immediate rewrite of all historical rows.

Recommended migration pattern:

1. Make readers tolerate both legacy and compact shapes.
2. Change new writers to emit the compact contract.
3. Add metrics to confirm no required behavior is lost.
4. Backfill only values needed for querying or compatibility.
5. Optionally archive or compact old rows in a separate maintenance operation.
6. Remove legacy read support only after the retention horizon permits it.

## Questions that prevent over-trimming

Before removing a field, verify:

- Can a delayed job still execute after the template changes?
- Can a failed delivery retry without rebuilding from mutable state?
- Can support explain why the message was sent?
- Can the system prove which consent authorized delivery?
- Can the exact customer-facing content be reconstructed where required?
- Can the operation remain idempotent?
- Can the row still be queried efficiently?

If the answer becomes “no,” the proposed trim is too aggressive or the value must move to a better first-class location.

## Anti-patterns to flag throughout the audit

- `model->toArray()` written directly into JSON
- Loaded relationships persisted unintentionally
- Entire request payload stored alongside normalized columns
- Entire config branches copied into every row
- `tokens`, `context`, and named nested objects containing the same model data
- IDs repeated at many nesting levels
- Full signed URLs stored far beyond their useful lifetime
- Provider secrets or sensitive headers in raw payloads
- Debug traces in durable `meta`
- Child rows repeating a snapshot already owned by a parent record
- Both raw and normalized data retained indefinitely with no policy
- Large arrays of related IDs where a relationship table already exists

## Expected audit deliverables

The clean audit thread should produce:

1. A complete write-site inventory.
2. A complete data-builder inventory.
3. A table/model worksheet for every persistence domain.
4. Runtime size measurements from realistic workflows.
5. A ranked list of concrete bloat findings.
6. Proposed compact persistence contracts.
7. Compatibility and migration plans.
8. Focused tests and size guardrails.
9. Retention/archive recommendations.
10. A phased implementation plan, starting with the highest-volume and highest-duplication paths.

## Recommended execution order

1. Inventory only; make no edits.
2. Audit ScheduledMessage end to end as the pilot.
3. Agree on persistence-classification language and review format.
4. Apply the same method to FlowRoutes.
5. Apply it to remaining modules in domain batches.
6. Add shared guardrails only after two or more domains prove the pattern.

This approach avoids a one-off scheduled-message cleanup and turns the current observation into a repeatable persistence discipline for the entire platform.
