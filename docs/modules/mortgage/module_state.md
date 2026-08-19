# Mortgage Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this vertical. Keep global architectural rules in `docs/module-boundaries.md`.

## Identity

```text
Architecture tier: vertical
Product surface: loud
Standalone value: yes, when installed for a mortgage client
Primary users: mortgage operators and consuming automation workflows
Primary surfaces: CRM mortgage records/contact context; dedicated operational surfaces later
Project State status: transferred when the optional Mortgage schema is installed
```

Mortgage is optional and should not be installed by default.

Its declared runtime dependency remains:

```text
Mortgage -> Core
```

Mortgage must not directly import private Workflow, FlowRoutes, Tasks, Messaging, Campaigns, Webinars, Reporting, or provider implementation classes merely because a mortgage workflow may ultimately interact with those capabilities. Cross-module outcomes use public Core/shared automation seams and owning-module actions/contracts.

## Ownership

Mortgage owns mortgage-specific durable facts and interpretation, including:

- current consumer mortgage facts that do not belong on the generic Contact;
- mortgage loan/application/history records;
- borrower/co-borrower participation on a loan;
- mortgage-specific Realtor/agent relationship profiles;
- Realtor market coverage and imported production snapshots;
- buyer/listing-agent participation snapshots on loans;
- mortgage stages for loan/process meaning;
- provider-neutral LOS contracts and mortgage-specific LOS interpretation later.

Core continues to own generic Contact identity, generic acquisition source fields, lifecycle Contact status infrastructure, tags, and generic import provenance.

Campaigns continues to own generic Campaign identity/enrollment behavior. Messaging continues to own channel authorization and delivery. Mortgage must not encode those concerns in its tables.

## Current persistence model

### `contact_mortgage_profiles`

One current mortgage-consumer profile per Contact.

Current structured facts:

```text
has_realtor
    yes | no | unknown

market_key
    config/client-defined market identity; no Slam Dunk geography is hard-coded

original_lead_at
    earliest reliable mortgage lead date when known
```

The profile is intentionally not a loan-history row. Loan amount, rate, purpose, program, property, and close date are repeatable loan facts and live on `mortgage_loans`.

### `mortgage_loans`

One durable mortgage loan/application/history record.

It may contain:

- source-system/reference/fingerprint hints for later idempotent import reconciliation;
- optional Mortgage stage;
- originator;
- purpose/program/type/lien position;
- amount/rate/sales price/appraised value/cash-to-close;
- subject-property snapshot;
- close date;
- bounded domain metadata.

A MortgageLoan intentionally has **no direct `contact_id`**. Borrower identity is represented through participants so one loan can retain multiple borrowers without pretending the loan belongs to one person.

### `mortgage_loan_participants`

Borrower/co-borrower participation on a loan.

Each row stores a source snapshot of the participant's identifying facts and may optionally resolve to a canonical Core Contact.

This is deliberate for imports where:

- a co-borrower shares an email with the primary borrower;
- only the primary borrower is safely resolvable as a Contact;
- a historical source row contains a participant who should be preserved without manufacturing a new Contact.

A null `contact_id` means unresolved/not safely linked, not missing source history.

### `mortgage_loan_realtors`

Buyer/listing-agent participation on a loan.

Like borrower participants, the row keeps source name/email/phone snapshots and may optionally link to a canonical Contact. Historical loan evidence does not depend on a Realtor Contact already existing.

### `mortgage_realtor_profiles`

One mortgage Realtor/agent relationship profile per Contact.

`relationship_stage_key` is intentionally a string/config-owned business key rather than a hard-coded universal enum. Client-specific stages such as Target Agent, Strategic Partner, or Referral Partner can be selected later without making those labels Core concepts.

The profile also owns relationship-level facts such as brokerage/license references and last referral/contact timestamps when known.

### `mortgage_realtor_markets`

Many-to-one structured market coverage for a Realtor profile.

A Realtor may cover multiple markets; one may be marked primary. Market keys remain configuration/client-owned. This supports future rules such as matching a preapproved consumer's `market_key` to eligible strategic Realtor partners without forcing Mortgage to depend on Location.

### `mortgage_realtor_production_snapshots`

Time-bounded imported production evidence such as:

```text
12-month loan count
12-month conventional count
12-month VA count
loan volume when supplied
```

Production data is historical snapshot evidence, not permanent counters on the Realtor profile. Derived labels such as High Producer or VA Producer can be evaluated later from the snapshot or represented through client-owned tags/rules.

### `mortgage_stages`

Stable keyed loan/process stages.

Mortgage stages are distinct from the client's main CRM Contact lifecycle statuses. Stacey's consumer lifecycle statuses remain a Core/Workflow-facing Contact-status concern; registering/attending a Webinar or importing a historical loan must not silently rewrite those statuses.

## Import architecture

The supplied Slam Dunk exports established the following requirements:

```text
one Contact may appear in many imported files
one borrower may have many loans/properties
one loan may contain primary + co-borrower data
co-borrowers may share a primary borrower's email
loans may contain buyer/listing Realtor snapshots
agent lists may provide production metrics independently of loan history
```

Core Phase 1A owns row-level import occurrence/provenance and canonical exact-email Contact resolution.

Mortgage import handlers will be added in the next slice. They must:

- consume Core's public Contact import registry/handler seam;
- persist Mortgage-owned facts only;
- perform idempotent/reconciliation logic inside Mortgage actions/services;
- preserve unresolved participant/counterparty snapshots instead of forcing unsafe Contact merges;
- never make Core understand mortgage columns;
- never grant Messaging consent or enroll Campaigns directly from Mortgage persistence code.

Import-profile orchestration may later combine Core status/source mapping, Mortgage persistence, Messaging imported consent, and Campaign enrollment through the appropriate public seams.

CSV/TXT remains the CRM import format. XLS/XLSX client files should be converted externally before operator import; the Core importer will not add spreadsheet parsing solely for this rollout.

## Project State

Mortgage now has an optional Project State section.

Activation requires the complete Mortgage schema. When the Mortgage vertical is not installed, the section is omitted. A partially installed Mortgage schema is a Project State contract error rather than silently exporting incomplete state.

Transferred tables:

```text
mortgage_stages
contact_mortgage_profiles
mortgage_loans
mortgage_loan_participants
mortgage_loan_realtors
mortgage_realtor_profiles
mortgage_realtor_markets
mortgage_realtor_production_snapshots
```

## Provider boundary

Vertical-specific migrations live under:

```text
database/migrations/verticals/mortgage
```

Mortgage may consume installed LOS providers only through provider-neutral contracts/services. Mortgage must not depend on concrete Arive or other vendor package classes.

Preferred shape:

```text
Engage Core Mortgage
    owns mortgage/LOS domain meaning and neutral contracts
            ^
            |
provider package
    owns vendor-specific API/webhook/email parsing and translation
```

Provider-specific transport/parsing must not hard-code downstream Contact-status, Task, Campaign, Messaging, or FlowRoute behavior.

## FlowRoutes / cross-module orchestration

When Mortgage has an automation-worthy outcome, it records Mortgage domain state first and then emits a neutral shared automation event. FlowRoutes may consume that seam without Mortgage taking a direct FlowRoutes dependency.

When another module needs to mutate Mortgage state, it must call a public Mortgage action/service/contract. It must not write Mortgage tables directly.

Do not add `flow_route_*`, `campaign_*`, or provider-specific foreign keys to Mortgage artifacts merely for provenance symmetry.

## Deferred from this persistence foundation

Not implemented by this slice:

- client-specific import profiles and field mappings;
- loan/realtor import idempotency actions;
- co-borrower secondary Contact creation/resolution policy;
- client Contact-status presets;
- Realtor relationship-stage preset/config authoring;
- referral event/metric accounting;
- LOS provider packages;
- CRM Mortgage UI/workspace;
- Campaign family/priority behavior;
- Messaging imported-consent behavior;
- high-intent reply orchestration.