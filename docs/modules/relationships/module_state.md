# Relationships Module

## Identity

```text
Architecture tier: universal module
Product surface: loud when explicitly enabled
Standalone value: yes, as relationship-scoped Contact workspaces
Primary users: CRM operators
Primary surfaces: relationship-specific Contact lists/workspaces; Contact context later
Project State status: transferred when the Relationships schema is installed
```

Relationships gives one canonical Core Contact multiple independent business contexts without creating duplicate people.

Its dependency is:

```text
Relationships -> Core
```

The module is intentionally about **Contact business relationships with the client**, not a generic graph of arbitrary model-to-model relationships.

## Core rule

Identity and working context are separate:

```text
Core Contact
    canonical person identity

ContactRelationship
    one business context for that person
    relationship key
    relationship-specific stage
    relationship-specific source/subsource
    active/inactive lifecycle
```

A Contact may hold several relationships at once.

Example:

```text
Contact Jane Smith
    consumer -> past_client
    realtor -> referral_partner
```

That is one person, not two CRM records.

## Normal CRM presentation rule

Materially different relationship populations must not be mixed in routine CRM workspaces.

Normal operating surfaces should be relationship-scoped, for example:

```text
Leads
Realtors
Vendors
Collaborators
```

A Contact with several relationships may appear in every applicable workspace, each through the relevant relationship context.

An all-Contacts mixed view is reserved primarily for owner/admin identity management, duplicate resolution, export, debugging, or other exceptional whole-database operations. It is not the default daily-work list.

Relationship labels are configuration-owned presentation. Internal runtime identity remains `Contact` and stable relationship keys remain generic identifiers.

## Configuration

Definitions live under:

```text
relationships.types
```

Each relationship defines:

```text
stable key
singular label
plural label
visibility
sort order
configured stage keys/labels
```

Core provides no client-business relationship definitions by default.

The default normal Contact workspace resolves in this order:

```text
SiteSetting at relationships.default_relationship_setting_key
    -> relationships.default_relationship
    -> first visible configured relationship
```

The default SiteSetting key is:

```text
crm.contacts.default_relationship
```

A stored override must still name a configured visible relationship. Setup validation reports stale/invalid stored defaults.

## Persistence

### `contact_relationships`

One current row per:

```text
contact_id + relationship_key
```

Current fields include:

```text
stage_key
source
subsource
is_active
started_at
ended_at
meta
```

`stage_key` is definition/config-owned rather than a universal enum. The action seam rejects stages not configured for the selected relationship.

`source` and `subsource` belong here when acquisition context is relationship-specific. Core Contact source fields remain the canonical person-level snapshot and Core import occurrences preserve raw import evidence.

The table represents current relationship state, not a complete relationship-event ledger. Historical transition/event evidence may be added later when a concrete workflow requires it.

## Public mutation seam

`UpsertContactRelationshipAction` is the initial public mutation seam.

It:

- validates configured relationship and stage keys;
- maintains one row per Contact/relationship key;
- preserves the original start time on later updates;
- records relationship-specific source/subsource;
- records active/inactive lifecycle timestamps;
- accepts bounded metadata without turning `meta` into canonical business fields.

Other modules that depend on Relationships should use public Relationships actions/services rather than writing `contact_relationships` directly.

## Mortgage specialization boundary

Mortgage depends on Relationships for generic Realtor/partner relationship identity and stage.

Preferred shape:

```text
Core Contact
    -> Relationships ContactRelationship (e.g. realtor / strategic_partner)
        -> MortgageRealtorProfile (mortgage-specific specialization)
```

Relationships does not know what a Realtor is. A mortgage client/vertical defines the relationship key and labels. Mortgage owns only mortgage-specific Realtor facts such as brokerage/license data, loan involvement, production snapshots, and referral facts.

## Location integration boundary

Relationships does not depend on Location in this foundation.

A later optional integration may allow a relationship workspace to use Location-owned areas/markets through a public Location capability. That must remain optional and validated explicitly when enabled; it must not create direct Relationships writes to Location tables or a second generic module dependency system.

## Project State

Relationships has an optional Project State section activated by `contact_relationships`.

It is serialized after Core and before consuming modules such as Mortgage so Contact references are restored first and vertical relationship specializations may safely reference the restored relationship rows.

## Deferred

Not implemented by this foundation:

- relationship-scoped CRM list/routes/navigation;
- relationship-specific filter/action registries;
- owner UI for changing the default relationship SiteSetting;
- history/event ledger for relationship-stage transitions;
- Workflow/FlowRoutes transition semantics for relationship stages;
- optional Location market/area bridge;
- client-specific relationship definitions;
- import-profile assignment of relationship/stage;
- Campaign family or Messaging consent behavior.