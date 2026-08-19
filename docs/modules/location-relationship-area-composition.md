# Location Relationship Area Composition

This note records the active reusable relationship-market composition contract. The broader Location module reference remains `docs/modules/location.md`.

## Ownership

`LocationArea` is the canonical reusable market/region/service-area record. `LocationAreaAssignment` owns membership of a Contact, Location, or polymorphic subject in an area.

`LocationAreaAssignment.is_primary` identifies the primary active area for one subject + assignment role. The public `AssignSubjectToLocationAreaAction` owns idempotent assignment/restoration and primary-area switching.

Relationships and vertical modules must not write Location tables directly.

For relationship-specific geography, the supported app-level composition is:

```text
ContactRelationship
    -> RelationshipLocationAreaBridge
        -> AssignSubjectToLocationAreaAction
            -> LocationAreaAssignment
                -> LocationArea
```

The bridge requires Relationships to be runtime-available and Location to be explicitly enabled. This is optional composition, not a hard Relationships -> Location or Mortgage -> Location dependency.

## Project State

Location Project State now transfers:

```text
location_areas
location_area_assignments
```

`locations` and `contact_locations` remain guarded by `must_be_empty` until their transfer contract is intentionally added. `location_id` on transferred area assignments is nulled on import; supported area-assignment exports therefore use area/contact/subject references rather than depending on untransferred normalized Location rows.