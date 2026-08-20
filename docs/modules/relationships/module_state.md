# Relationships Module State

Relationships is a reusable universal CRM-context module built on Core Contact identity.

## Ownership

Core owns the canonical person/contact record. Relationships owns the distinct business contexts in which that Contact participates.

Examples include consumer/customer, collaborator, Realtor, referral partner, vendor, or other client-defined relationship types.

A Contact may hold multiple relationships at the same time. Normal CRM list/workspace surfaces must remain relationship-scoped; unrelated relationship populations should not be mixed in routine operational views. An all-contacts view is exceptional/admin-oriented.

Relationship-owned state includes:

```text
relationship_key
stage_key
source
subsource
is_active
started_at
ended_at
meta
```

Client/vertical config defines allowed relationship types, labels, and stages. A DB-backed SiteSetting may override the configured default relationship workspace.

## Optional Location composition

Relationships does **not** depend on or import Location.

When Location is explicitly enabled, app-level composition may associate a `ContactRelationship` with one or more Location-owned `LocationArea` records through `RelationshipLocationAreaBridge`.

The ownership split is:

```text
Relationships
    owns business context and relationship stage

Location
    owns areas/markets/regions and area assignments

app/Support/ModuleIntegrations
    composes the two only when both capabilities are available
```

Relationship geography must not be duplicated into generic `market_key` columns or vertical-specific market tables when Location is the configured source of truth.

## Import composition

Core Contact imports can assign/update configured relationship types and stages through the public Relationships import handler. Relationships also contributes generic import-treatment targets so an operator may apply one Relationship to all successfully imported rows or map distinct CSV values to configured Relationships/stages. A Relationship Stage treatment also establishes its owning Relationship identity, preventing cross-relationship stage ambiguity. Acquisition source/subsource is preserved rather than casually overwritten by later overlapping imports. Optional Location-area mapping composes through the app-level bridge when Location is explicitly enabled.

## Project State

Relationships Project State is imported after Core and before Location/Mortgage consumers. Contact relationship records transfer as durable business-context state.

## Deferred

- relationship-scoped CRM list/navigation implementation;
- client UI for choosing default relationship workspace;
- relationship-aware bulk actions and exports.