# Engage Core — Project State Transfer Runbook

## Purpose

Use this runbook for a controlled Engage Core database clean rebuild that must preserve the currently supported client-owned state.

Project State is not a normal deployment mechanism and is not a substitute for:

- a database backup;
- normal append-only production migrations;
- provider reconciliation;
- Redis/queue isolation;
- change approval and a maintenance window.

Developer contract changes belong in [`../project-state-extension-guide.md`](../project-state-extension-guide.md).

## Current transfer contract

```text
format: engage-core-project-state
version: derived sum of the configured section versions
contract.fingerprint: exact SHA-256 identity of the normalized configured contract
contract.section_versions: ordered section-version vector
```

The root version is derived and must never be incremented manually. Exact compatibility is established by the section-version vector plus the contract fingerprint. A change to any owning section version composes automatically into the root version; the fingerprint prevents different vectors or exact contract shapes from colliding behind the same root sum.

The fingerprint/vector cutover is itself a current-format boundary. Exports created before this change that contain only the old manually maintained root version must be re-exported from current code or transformed externally before import.

The current contract includes dependency-ordered Core/universal sections plus optional Reporting, Mortgage, and Scheduling sections. Reporting is included only when its activation schema is installed. Mortgage is included only when the complete Mortgage vertical schema is installed. Scheduling is included only when the complete Scheduling schema is installed. A document containing an optional section is rejected when the target does not have that section's activation schema.

The CRM surface is owner-only:

```text
/project-state
```

Access requires:

- an authenticated CRM user;
- an exact email match with `PROJECT_STATE_ADMIN_EMAIL`;
- the current password for export, apply, and resume;
- the exact confirmation word `IMPORT` for apply;
- the exact confirmation word `RESUME` for resume.

A blank `PROJECT_STATE_ADMIN_EMAIL` disables access because no user can match it.

## Safety model

Project State provides:

- a consistent database read snapshot for the exported tables;
- closed schema and column contracts;
- explicit policy gates for excluded tables;
- checksum-protected JSON;
- validation without mutation;
- one transaction for import;
- source-to-target ID remapping;
- inert import of runnable work;
- explicit dependency-aware resume.

Project State does not transfer:

- users, passwords, password-reset tokens, or sessions;
- Redis queue contents, Horizon metadata, cache, or locks;
- failed-job history;
- Reporting sessions, raw observations, and projection checkpoints reset; retained Reporting daily metrics and imported external measurements transfer only when the Reporting schema is installed;
- unsupported Location, Portal, Forms, Documents, or Commerce durable state; Mortgage and Scheduling durable state transfer when their complete optional schemas are installed;
- active Scheduling booking holds or slot offers, or destination-verification challenge/proof state;
- external provider state.

Do not proceed when excluded durable state must survive. Extend the contract first.

## Roles

Use at least two roles for a live transfer when possible:

```text
operator
    performs the documented steps

reviewer
    verifies backups, export file, validation report, counts, and resume outcomes
```

Keep a transfer log containing:

- source and target commit hashes;
- selected `CLIENT_KEY`;
- source and target database names;
- export filename and SHA-256 checksum;
- backup location;
- start/end timestamps;
- validation warnings;
- applied counts;
- resume outcomes;
- exceptions and manual reconciliation.

## Phase 0 — Decide whether Project State is appropriate

Proceed only when all are true:

```text
[ ] Source schema matches the deployed Project State contract
[ ] Target will run the exact intended code/client configuration
[ ] All durable data that must survive is transferred
[ ] Every excluded durable table is intentionally empty
[ ] A normal migration path is not the intended operation
[ ] A maintenance window is approved
[ ] Independent database backup and restore have been tested
[ ] Redis isolation is understood
[ ] Provider-side reconciliation is possible
```

Stop and extend the contract when:

- an installed Mortgage schema is partial/outside the current Mortgage section contract, or unsupported Scheduling durable rows must survive;
- an unsupported module table contains required data;
- a new table or column is unclassified;
- a polymorphic relation points to an unexported target that must survive;
- the file was produced under an incompatible Project State contract/version and no deliberate migration path exists for that contract change.

## Phase 1 — Prepare the source environment

### 1. Record the deployment identity

From the source checkout:

```bash
cd <APP_PATH>
git rev-parse HEAD
php artisan about
php artisan route:list | grep project-state
```

Record:

```text
APP_ENV
CLIENT_KEY
database name
Redis DB/prefix
Horizon prefix
Supervisor program
PHP binary
deployment user
```

### 2. Confirm owner access

In the selected client environment:

```env
PROJECT_STATE_ADMIN_EMAIL=owner@example.com
```

The value must exactly match the intended CRM user's email after trimming and case normalization.

Run:

```bash
php artisan optimize:clear
```

Log in as that user and confirm the Project State page opens.

### 3. Verify current application contracts

Run:

```bash
php artisan presets:sync
php artisan setup:validate
php artisan test tests/Feature/ProjectState
```

For a production transfer, tests should normally have been run in CI or a production-shaped staging environment rather than against the live production database.

Do not export from code that does not match the live schema.

### 4. Create an independent database backup

Create and verify a normal database backup before using Project State.

Project State is a selective application-level transfer artifact. It is not the rollback backup.

Record the backup filename, size, checksum, storage location, and restore procedure.

### 5. Freeze writes

Enter the approved maintenance window.

Prevent new application and provider writes:

- place the application in maintenance mode or otherwise block write traffic;
- stop or disable the Scheduler invocation for the cutover window;
- stop Horizon through the configured Supervisor program;
- pause inbound provider delivery when operationally available;
- verify no separate worker or cron path is still writing.

Example process checks:

```bash
sudo supervisorctl status
ps aux | grep "[a]rtisan horizon"
sudo crontab -u <DEPLOY_USER> -l
```

A consistent read transaction protects the export from mixed snapshots while it runs, but it does not freeze changes that occur after the export. The maintenance freeze makes the downloaded file the intended final source state.

### 6. Resolve export blockers

The export action performs the authoritative checks. Before clicking it, review likely blockers:

```text
pending project_state_resume_items
database-backed jobs
unsupported durable module rows
active booking holds or slot offers
nonterminal inbound/webhook receipts
unclassified tables or columns
unsafe direct, polymorphic, or JSON references
```

Do not delete required data merely to make the export pass.

## Phase 2 — Export

### 1. Download the file

Open:

```text
CRM → Project State
```

Under **Download current state**:

1. enter the current password;
2. click **Download Project State**;
3. save the file in protected storage.

The filename follows:

```text
{client-key}-project-state-v{derived-version}-{contract-fingerprint-prefix}-YYYYMMDD-HHMMSS.json
```

The response is streamed directly. The application does not intentionally leave a public server-side copy.

### 2. Preserve the original

Treat the downloaded file as immutable.

Create a working copy only when an external current-format transformation is deliberately required.

Record its checksum:

```bash
sha256sum <PROJECT_STATE_FILE>
```

The JSON also contains its own `sha256:` integrity checksum over the document without the checksum field.

### 3. Inspect the envelope

Confirm:

```text
format = engage-core-project-state
version = current derived root version
contract.fingerprint = current exact contract fingerprint
contract.section_versions = current ordered section-version vector
client_key = expected selected client
source.environment = expected source environment
source.database = expected source database
sections = expected current active sections
checksum is present
```

The Project State page shows the current derived version, exact fingerprint, and section-version vector. The root number alone is not sufficient evidence of compatibility.

Do not hand-edit row data or the checksum.

### 4. Preserve a row-count record

The target validation screen will report file row counts by table. Preserve a screenshot or transcription for comparison after apply.

## Phase 3 — Dispose of stale runtime coordination safely

Project State transfers database state, not Redis jobs.

Before reusing primary keys in a fresh database:

1. confirm Horizon and Scheduler remain stopped;
2. identify the exact Redis DB/prefix for this app/environment;
3. confirm whether sessions, cache, queues, locks, Horizon metadata, or another application share it;
4. delete only the intended queue/runtime keys or flush only a dedicated Redis DB when proven safe;
5. record what was cleared.

Never run an unscoped `FLUSHDB` without confirming isolation.

Old delayed jobs must not survive the rebuild and later execute against reused IDs. Explicit Project State resume recreates supported work from the imported database state.

## Phase 4 — Rebuild the target

Keep Horizon and Scheduler stopped.

### 1. Deploy the intended code and client configuration

Verify the exact application and client commits.

Run:

```bash
php artisan optimize:clear
```

### 2. Rebuild the platform database foundation

For the approved controlled clean rebuild:

```bash
php artisan migrate:fresh --force
```

After the modular migration path-selection cutover, `migrate:fresh` reconstructs the platform foundation only. It does not install optional module schema.

Do not use this command as an ordinary production deployment technique.

### 3. Install configured module schema and DB-owned definitions

Run:

```bash
php artisan engage:install --force --no-create-user
php artisan modules:status
```

`engage:install` reruns the already-current platform stage safely, installs the configured schema-owning module dependency closure, materializes DB-owned definitions through `presets:sync`, and runs `setup:validate`. `--no-create-user` keeps environment-owned CRM user recreation explicit in the next step.

Project State upserts stable DB-owned definitions against this installed target state and remaps source IDs to target IDs.

Resolve every installation or setup-validation error before import. Understand every warning, and review `modules:status` to confirm the intended configured scopes are installed and current.

### 4. Recreate environment-owned users

Users are not transferred.

Create the intended CRM user from an interactive operator session:

```bash
php artisan engage:user:add
```

Its email must match `PROJECT_STATE_ADMIN_EMAIL`.

Do not reuse password hashes or sessions from the source transfer file; they are not present.

### 5. Preserve maintenance-window access to Project State

The owner-only Project State surface is a web CRM surface. Plan how the authorized operator will reach it while background runtime remains inert.

Keep:

```text
Horizon stopped
Laravel Scheduler stopped
provider-side write delivery paused when operationally available
```

If the application is still in maintenance mode and that blocks the owner CRM session, use the environment's approved maintenance-window access method. Do not casually reopen public/provider write traffic merely to reach the import screen.

### 6. Confirm target tables are clean

`insert_empty` tables must be empty. Validation will reject any non-empty target table in that mode.

Do not create test contacts, registrations, messages, tasks, campaigns, broadcasts, or routes before applying the file.

## Phase 5 — Validate without mutation

Open the target CRM Project State page.

Under **Validate or apply current-format state**:

1. select the original JSON file;
2. click **Validate Only**;
3. review the complete report.

Validation does not mutate the database.

### Required result

```text
VALID
```

The report should show:

- format/derived version and contract identity;
- client key;
- row count for every transferred table;
- errors;
- warnings;
- `applied = false`.

### Errors that must block apply

Examples:

```text
unsupported format, derived version, section-version vector, or contract fingerprint
wrong client key
invalid checksum
missing section/table
wrong section version
row/column contract mismatch
duplicate source ID or identity
non-empty insert_empty target
missing direct or JSON reference
unsupported polymorphic type
known polymorphic target table not exported
duplicate table name across sections
target schema mismatch
```

Do not bypass the validator or weaken the contract during an operational transfer.

### Warnings that require review

Expected warning classes may include:

- import value mappings such as active/pending/sending to paused;
- values cleared by `null_on_import`;
- bounded JSON-path value mappings;
- historical polymorphic IDs absent from the current document and preserved without remapping;
- a missing checksum.

A missing checksum is not expected for a normal UI export. Investigate before apply.

Preserve the warning list in the transfer log.

## Phase 6 — Apply the import

Keep Horizon and Scheduler stopped.

Under the import form:

1. select the validated original JSON file;
2. enter the current password;
3. type `IMPORT` exactly;
4. click **Apply Import**.

The application revalidates before apply.

### Transaction boundary

All configured sections and tables apply inside one database transaction.

On failure:

```text
Project-state import failed and was rolled back: ...
```

No configured imported rows should remain from that failed transaction.

Investigate the first root cause. Do not retry repeatedly without understanding it.

### Successful result

The report shows:

```text
VALID
Import applied
applied = true
applied_counts by table
```

Compare:

- file row counts;
- applied counts;
- expected upsert behavior;
- target table counts;
- representative relationships and JSON values.

For upsert tables, `applied_counts` counts processed document rows, not only newly inserted rows.

## Phase 7 — Verify the inert imported state

Before restarting workers, verify:

```text
[ ] Imported counts are plausible
[ ] Representative Contacts and relationships exist
[ ] Preset-backed definitions retained client customizations
[ ] Foreign keys/morphs point to expected target rows
[ ] Environment-owned user IDs were cleared where configured
[ ] Runtime claim tokens were cleared
[ ] Active/pending/sending/processing work imported into supported inert states
[ ] Project State resume categories show expected pending counts
[ ] No external provider action has been triggered by the import itself
```

The import uses direct database writes and does not fire normal Eloquent model events.

Do not manually change paused statuses to active. Use explicit resume so dependencies, idempotency rules, outcomes, and queue recreation are applied.

## Phase 8 — Prepare runtime services

Before the first resume action:

1. verify the target Redis namespace is clean and correct;
2. verify every required Horizon queue is configured;
3. verify provider credentials, senders, webhooks, and idempotency behavior;
4. verify the Laravel Scheduler cron and `schedule:list`;
5. restart Horizon through Supervisor;
6. re-enable the Scheduler;
7. verify the actual worker process path;
8. keep normal user traffic in maintenance until the resume/verification decision is complete.

Commands:

```bash
sudo supervisorctl restart <SUPERVISOR_PROGRAM>
sudo supervisorctl status <SUPERVISOR_PROGRAM>
ps aux | grep "[a]rtisan horizon"

cd <APP_PATH>
php artisan schedule:list
```

## Phase 9 — Resume imported activity

If the import created no pending resume items, do not run `RESUME` merely because this phase exists. A deliberately runtime-stripped or otherwise non-runnable transfer may correctly proceed directly to final verification after runtime services are restored.

When resume items do exist, the UI exposes only categories that have pending items and no incomplete dependencies.

Each submission processes at most:

```text
project_state.resume_batch_size
```

Current default:

```text
500
```

Repeat a category until its pending count reaches zero.

### Recommended order

Use this operational order while respecting the UI's live dependency guards:

```text
1. Message-chain enrollments
2. Campaign enrollments
3. Interrupted Broadcasts
4. FlowRoute progress
5. Webinar registration finalization
6. Pending scheduled messages
7. Interrupted message deliveries
8. Scheduled-message terminal events
9. Automation events
```

Dependency details:

```text
message_chain_enrollments
    no dependency

campaign_enrollments
    no dependency

broadcasts
    no dependency

flow_routes
    message_chain_enrollments
    campaign_enrollments

webinar_finalizations
    message_chain_enrollments
    campaign_enrollments

scheduled_messages
    message_chain_enrollments
    campaign_enrollments
    broadcasts

message_deliveries
    message_chain_enrollments
    campaign_enrollments
    broadcasts

scheduled_message_outbox
    message_chain_enrollments
    campaign_enrollments
    broadcasts

automation_events
    message_chain_enrollments
    campaign_enrollments
    flow_routes
```

For each category:

1. choose the ready category;
2. enter the current password;
3. type `RESUME` exactly;
4. submit;
5. record processed count, outcomes, and remaining count;
6. investigate any `queue_failed` outcome before retrying.

### Category behavior

#### Message-chain enrollments

- paused enrollments become active;
- already-due enrollments are queued;
- future enrollments are activated without an immediate job;
- missing or no-longer-resumable rows are closed with explicit outcomes.

#### Campaign enrollments

- paused enrollments become active;
- no immediate Campaign job is manufactured by this category.

#### Interrupted Broadcasts

- imported `sending` Broadcasts were mapped to `paused`;
- resume restores them to the supported scheduled state;
- recipient message work is handled through scheduled-message resume.

#### FlowRoute progress

- restores the original active/waiting state from the bounded backup;
- restores plan and item statuses;
- recreates immediate continuation or timed wait jobs;
- event waits with no `resume_at` are restored without manufacturing a timer.

#### Webinar finalization

- clears stale queue claims in the stored finalization state;
- invokes the canonical queue action;
- failed or reconciliation-required finalizations are not blindly replayed.

#### Pending scheduled messages

- paused imported pending messages return to pending;
- queue names are validated;
- delayed jobs are recreated from future `send_at`;
- due/past messages are queued without a delay.

#### Interrupted message deliveries

This category is deliberately conservative:

- safe claims may be recovered and requeued;
- an imported sending message with no delivery attempt is failed;
- an ambiguous provider submission is failed rather than blindly resent;
- terminal outbox evidence is recorded where required.

Review failed outcomes and reconcile with the provider.

#### Scheduled-message terminal events

- paused terminal outbox rows return to pending;
- one publication job is dispatched;
- resume items complete only after release for publication.

#### Automation events

- paused automation outbox rows return to pending;
- publication occurs only after FlowRoute state has been restored.

## Phase 10 — Final verification

### 1. Confirm all resume work is complete

The Project State page should show zero pending items in every category.

Export remains blocked while any pending resume item exists. A successful no-op export readiness check after completion confirms that guard is clear, but do not create unnecessary additional transfer files without an operational reason.

### 2. Verify queues and Scheduler

```bash
sudo supervisorctl status <SUPERVISOR_PROGRAM>
ps aux | grep "[a]rtisan horizon"
php artisan schedule:list
```

Inspect queue depth and failures using the environment's normal Horizon/Redis tools.

### 3. Verify application invariants

Run:

```bash
php artisan setup:validate
```

Perform representative CRM checks:

```text
Contacts and tags/notes
consent and suppression history
Webinar registrations and bindings
message templates/chains
future scheduled messages
Campaign/Broadcast state
Tasks and links
workflow profiles
FlowRoutes progress and waits
automation outbox state
```

### 4. Verify provider-facing state

Check:

- no duplicate messages were sent;
- ambiguous interrupted deliveries remain failed until reconciled;
- Zoom finalizations are in expected states;
- scheduled-message and automation outboxes are publishing;
- inbound/webhook endpoints point to the target;
- no source worker or Scheduler is still active.

### 5. Reopen traffic

Exit maintenance only after:

```text
[ ] Import counts verified
[ ] Resume pending counts are zero or deliberately accepted
[ ] Queue workers healthy
[ ] Scheduler healthy
[ ] Provider reconciliation complete
[ ] Source writers disabled
[ ] Reviewer approval recorded
```

## Rollback boundaries

## Before apply

A failed validation makes no database changes.

Fix the target or use the correct original file and validate again.

## During apply

A thrown import exception rolls back the configured import transaction.

Confirm the target remains clean before retrying.

## After successful apply but before resume

The safest rollback is normally:

1. keep traffic/workers stopped;
2. restore the independent target backup or rebuild the target again;
3. correct the cause;
4. revalidate the original export;
5. reapply.

Do not attempt broad ad hoc deletes across transferred tables.

## After resume or external provider activity

Project State cannot reverse:

- sent email/SMS;
- provider submissions;
- published automation events;
- external Webinar changes;
- source/target webhook delivery.

Use the database backup plus provider-specific reconciliation and incident procedures. Record every external side effect before deciding whether to repeat a category.

## Common blocker matrix

| Blocker | Meaning | Correct response |
| --- | --- | --- |
| Unclassified table | A migration added a table with no transfer/policy decision | Extend the contract or add an explicit justified policy |
| Unclassified column | A transferred table changed without updating its complete contract | Update the owning section and versions/tests |
| `must_be_empty` rows | Unsupported or ephemeral state exists | Resolve/discard only when operationally correct, or add transfer support |
| Nonterminal receipt | Inbound/webhook processing is still active | Let processing finish or reconcile it |
| Pending resume items | A prior import has unfinished runtime restoration | Complete or deliberately reconcile every category |
| Unsafe polymorphic target | The document cannot restore the referenced object safely | Add supported transfer/mapping or remove the invalid source relation through owning behavior |
| Target table not empty | An `insert_empty` table already has rows | Rebuild/clean the intended target; do not merge ad hoc |
| Client key mismatch | File and target selected client differ | Use the correct environment/file; do not disable the guard casually |
| Checksum invalid | File changed or is corrupt | Return to the immutable original export |
| `queue_failed` outcome | Resume could not dispatch supported work | Fix queue/worker configuration and retry the still-pending category |

## Final transfer record

Archive securely:

```text
independent database backup
immutable Project State export
export SHA-256
source/target commits
environment/client identity
validation report and warnings
applied counts
resume outcomes
provider reconciliation notes
final setup:validate result
operator/reviewer approvals
```

Project State files contain client data. Store, transmit, retain, and destroy them according to the same policy as a database backup