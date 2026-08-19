# Mortgage Module State

Mortgage is an optional vertical module. Its declared dependency is:

```text
Mortgage -> Relationships -> Core
```

Mortgage owns mortgage-specific durable facts and interpretation. It must not duplicate generic Contact identity, generic relationship state, or Location-owned market/region state.

## Current ownership

### Core

Owns canonical Contact identity and generic import occurrence/provenance.

### Relationships

Owns consumer/Realtor/collaborator business context, relationship stage, source/subsource, active lifecycle, and relationship-scoped CRM workspace identity.

### Location

When explicitly enabled, owns reusable markets/regions/service areas and assignments of those areas to relationship subjects. Mortgage does not hard-depend on Location and does not write Location tables directly.

### Mortgage

Owns:

- current consumer mortgage facts (`has_realtor`, `original_lead_at`);
- mortgage loan/application/history records;
- borrower/co-borrower participation snapshots;
- buyer/listing Realtor loan snapshots;
- mortgage-specific Realtor specialization;
- Realtor production snapshots;
- mortgage process stages;
- later LOS/provider-neutral interpretation.

## Market/location rule

Mortgage no longer stores `contact_mortgage_profiles.market_key` and no longer owns `mortgage_realtor_markets`.

For a client that enables Location, consumer and Realtor market membership is represented as:

```text
Contact
  -> ContactRelationship (consumer or realtor)
      -> LocationAreaAssignment
          -> LocationArea (market/region/service area)
```

This keeps one geographic source of truth while preserving the distinct relationship context to which the market applies.

If Location is not enabled, Mortgage remains functional; market-aware behavior is simply unavailable unless another documented public capability is introduced later.

## Loan/history model

`mortgage_loans` owns repeatable loan facts and has no direct Contact owner. Borrower/co-borrower identity is represented by `mortgage_loan_participants`; historical Realtor involvement is represented by `mortgage_loan_realtors`. Snapshot rows may have a nullable Contact link so unresolved co-borrowers/counterparties remain durable without unsafe Contact creation.

`mortgage_realtor_profiles` specializes a Relationships-owned `ContactRelationship`; generic Realtor relationship stages do not belong in Mortgage.

`mortgage_realtor_production_snapshots` stores time-bounded imported production evidence rather than mutable lifetime counters.

## Import boundary

Mortgage import handlers added later must consume Core import seams, call Relationships public mutation seams, and persist only Mortgage-owned facts. Optional geographic assignment uses the app-level Relationships/Location bridge. Mortgage persistence code must not grant Messaging consent or enroll Campaigns directly.

CSV/TXT remains the CRM import format; source XLS/XLSX files are converted externally before import.

## Project State

Mortgage Project State is optional and follows Relationships. It transfers:

```text
mortgage_stages
contact_mortgage_profiles
mortgage_loans
mortgage_loan_participants
mortgage_loan_realtors
mortgage_realtor_profiles
mortgage_realtor_production_snapshots
```

Market/region state transfers through Location Project State rather than Mortgage.

## Deferred

- client-specific import profiles and mappings;
- loan/Realtor import idempotency/reconciliation actions;
- co-borrower Contact-resolution policy beyond exact safe identity matches;
- referral event/metric accounting;
- LOS provider packages;
- Campaign family/priority behavior;
- imported Messaging consent orchestration;
- high-intent reply orchestration.