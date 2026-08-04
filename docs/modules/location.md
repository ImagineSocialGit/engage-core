# Location Module

## Identity

```text
Architecture tier:   universal module
Product surface:     silent
Standalone value:    no
Primary users:       consuming modules and developer/operators
Primary surfaces:    embedded only; shared settings only when a concrete provider/setup need exists
```

Location is supporting infrastructure for reusable location facts. It is not a standalone client product and should not receive a Location sidebar link, generic place-management dashboard, map builder, or GIS-style workspace merely because its tables exist.

Current repository implementation is a persistence foundation only:

```text
locations
contact_locations
location_areas
location_area_assignments

Location
ContactLocation
LocationArea
LocationAreaAssignment

LocationModuleServiceProvider
LocationFoundationTest
```

The service provider currently exposes no operational capability. All Location durable tables must remain empty while Project State classifies Location as unsupported.

## Current committed responsibility

Location should provide the smallest reusable capability needed by real consuming workflows:

```text
normalize an address or place into a provider-neutral representation
optionally resolve coordinates, timezone, precision, and confidence
persist a reusable saved Location only when a workflow needs durable reuse
link a Contact or another supported subject to a saved Location when that relationship has product value
expose compact read/normalization contracts to consuming modules
```

Location answers:

```text
What normalized place or address is this?
What provider-neutral geographic facts are known about it?
Which Contact or supported subject is linked to a reusable saved Location?
```

Location does not decide what those facts mean to Scheduling, Commerce, Events, Music, Mortgage, PetServices, or another vertical.

## Product boundary

Location is intentionally not:

```text
a replacement for Google Maps, ArcGIS, or another map product
a route planner or travel-time policy engine
a general map/marker browser
a polygon editor
a territory-management product
a client-facing location database
a generic geospatial reporting product
a reason to create Location CRUD screens for every table
```

A consuming loud module owns the user's workflow.

Example:

```text
Scheduling
    decides when an address is required
    asks for and validates the address
    explains availability/travel outcomes
    owns Appointment location policy and historical snapshots
    owns travel-time availability decisions

Location
    normalizes the submitted address
    optionally resolves provider-neutral geographic facts
    optionally persists a reusable saved place
```

The user should not have to leave Scheduling to operate Location.

## Owns

Location owns:

```text
locations
contact_locations
location_areas
location_area_assignments
provider-neutral address/location normalization contracts
provider-neutral geocoding result shape when implemented
reusable saved-location identity when a workflow needs it
Contact-to-Location and supported subject-to-Location relationships
Location-owned read services for normalized facts
```

The existing area tables remain Location-owned schema, but their presence does not commit Engage Core to service-area, territory, zone, radius, polygon, market, or assignment workflows. Those concepts remain dormant until a concrete consuming workflow requires one of them.

## Does not own

Location does not own:

```text
Core Contact identity
Scheduling service policy, Appointment lifecycle, availability, or travel decisions
Commerce billing/shipping workflow or purchase state
Event lifecycle or historical Event location snapshots
Portal accounts or profile workflows
Messaging delivery
Reporting dashboards
vertical-specific territory or market meaning
routing-provider travel estimates used to make Scheduling availability decisions
provider credentials
map tiles, layers, directions, or turn-by-turn navigation
```

Do not add address, latitude, longitude, market, or service-area fields directly to `contacts` by default.

## Consumes

Location depends only on Core for its current foundation.

When operational capability is added, Location may use provider adapters behind Location-owned contracts for address normalization or geocoding. Provider credentials remain environment/config state and do not become Location records.

Location should not import consuming loud modules merely to understand why normalization was requested.

## Consumed by

Potential consumers include:

```text
Scheduling
Commerce
Events
Experiences
Music
PetServices
Mortgage
Reporting
Portal
```

A consumer should use a narrow public Location contract rather than mutating Location internals.

Consumer demand determines which contract is implemented. Do not add every theoretical read service, filter, area engine, or provider manager at once.

## Scheduling boundary

Scheduling is the first approved consumer that needs additional Location capability.

The required sequence is consumer-driven:

```text
1. Scheduling defines the exact server-owned address/location facts required for customer-site and fixed-location appointments.
2. Location adds only the normalization/geographic-fact contract required by that Scheduling flow.
3. Scheduling owns Appointment location policy and immutable Appointment/Hold snapshots.
4. Scheduling owns provider-neutral travel-time resolution and availability decisions.
5. A reusable Location row is created only when durable reuse is intentionally required.
```

A public booking address may be normalized transiently and copied into a Scheduling-owned immutable snapshot without creating a durable Location record. This avoids abandoned-booking Location-row bloat.

Browser-authored coordinates, travel durations, confidence values, or verified-location flags are never authoritative.

## Public seams to add only when required

The next approved Location seam should be deliberately small, likely shaped around a transient normalized result rather than automatic persistence.

Conceptual capability:

```text
NormalizeLocationInput
    input:
        address_line_1
        address_line_2 nullable
        city
        region
        postal_code
        country

    output:
        normalized address fields
        formatted_address
        latitude/longitude nullable
        timezone nullable
        precision nullable
        confidence nullable
        provider identity nullable
```

Rules:

```text
no raw provider payload escapes through the public DTO
no Location row is automatically created
no client-facing Location UI is required
normalization failure is explicit
provider enrichment is optional
provider credentials remain outside durable records
```

Create/update/link/read actions should be added only when a real reusable-saved-location workflow needs them.

## Schema foundation

### locations

Represents a reusable normalized address, place, virtual location, or region-like record.

Important fields:

```text
key
name
label
type
status
address_line_1
address_line_2
city
region
postal_code
country
formatted_address
latitude
longitude
timezone
precision
confidence
source
provider
external_id
external_url
geocoded_at
raw_payload
meta
```

Current rules:

```text
latitude/longitude remain nullable
precision/confidence are generic provider-result hints
raw_payload is provider evidence, not a public contract or general token source
persistence is justified only when the Location will be reused or referenced durably
```

### contact_locations

Links a Core Contact to a reusable Location.

Important fields:

```text
contact_id
location_id
subject_type / subject_id
type
label
status
is_primary
verified_at
valid_from
valid_until
source
meta
```

Do not create a ContactLocation merely because a transient booking address was submitted. Create the link only when durable Contact-address reuse is part of the approved workflow.

### location_areas

Represents a possible reusable area definition.

Important fields include:

```text
key
name
description
type
status
boundary_type
country
region
city
postal_code
center_latitude
center_longitude
radius_meters
geometry
timezone
is_service_area
source
provider
external_id
external_url
settings
meta
```

This table is dormant foundation. Its schema does not require Engage Core to build markets, territories, zones, postal-code engines, polygons, or a spatial editor.

### location_area_assignments

Represents a possible durable relationship between a LocationArea and a Contact, Location, or supported subject.

Important fields include:

```text
location_area_id
location_id
contact_id
subject_type / subject_id
role
status
starts_at
expires_at
source
meta
```

This table is also dormant until a proven workflow needs precomputed or manual area membership.

## Events boundary

Events owns the historical schedule and inline location snapshot that was true for one concrete Event.

Updating a reusable Location must not rewrite historical Event facts. Event integration should remain optional and should use Location only for narrowly required normalization, geocoding, or derived geographic decisions.

Location must not copy Event lifecycle, announcement, artist, ticket, Commerce, or Experience state.

## Project State

Location durable tables currently have no first-class Project State transfer section and must remain empty.

Before any workflow persists Location, ContactLocation, LocationArea, or LocationAreaAssignment records that must survive a controlled clean rebuild, add explicit Location transfer support for exactly the operational tables and references in use.

Do not transfer:

```text
provider credentials
short-lived normalization requests
reconstructible caches
unjustified full provider payload archives
```

## Deferred possibilities

These remain possibilities, not current requirements:

```text
standalone saved-place management
Contact address editing outside a consuming workflow
service-area eligibility
radius queries
postal-code or region membership
markets and territories
polygon geometry and spatial queries
location-aware Broadcast/Campaign filters
location reporting surfaces
Portal profile/address screens
reverse geocoding
provider-specific place search
route optimization
```

Implement one only when a concrete loud-module or vertical workflow proves its value and defines the smallest required Location contract.