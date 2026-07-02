# Location Module

Location is a current universal module.

Location owns reusable address, contact-location, geocoding-result, market, region, and service-area capability that can be used by multiple verticals without pushing location state into Core contacts or vertical-specific tables.

Location is not intended to become a full GIS platform, a route optimizer, a map product, or a replacement for geocoding/map providers.

The intended product shape is:

```text
Engage Core stores enough location intelligence to make admin/client work easier.
Admins can answer practical business questions quickly.
Provider-specific geocoding or map behavior stays behind adapters later.
Vertical modules decide what a location means in their domain.
```

## Product barometer

Location should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

For Location, client-facing/admin-facing work should be practical and action-oriented:

```text
See which contacts are in a service area.
Find contacts near a city, region, market, or point.
Store a clean billing, shipping, home, work, or service address.
Check whether a customer is eligible for an in-person service.
Target contacts near an upcoming show or event.
Group contacts into markets or regions for reporting and outreach.
```

Developer/operator work includes:

```text
Choose the geocoding provider.
Configure service areas or markets.
Decide whether a vertical uses radius, postal codes, counties, manual areas, or provider-derived geometry.
Wire provider adapters.
Expose location-aware filters later.
Connect Scheduling, Broadcasts, Campaigns, Reporting, or vertical modules through public seams later.
```

Location should make life easier for the site admin. It should not recreate Google Maps, ArcGIS, routing software, or a full GIS editor inside Engage Core.

## Responsibility

Location should answer:

```text
What locations are known, which contacts or subjects are linked to them, what areas or markets exist, and which records belong to those areas?
```

Location should stay vertical-neutral.

It may support customer addresses, service addresses, business locations, event venues, show markets, dog trainer service areas, appointment eligibility, radius targeting, imported locations, and geocoded provider data without owning vertical-specific meaning.

## FOSS feature-shape assumptions

Before proposing schema, Location was evaluated against common patterns in mature open-source and open-source-adjacent geocoding, place, address, and geographic-data systems.

Those systems commonly separate:

```text
- normalized address/location records
- latitude/longitude and geocoding metadata
- reverse geocoding
- location search/geosearch
- geographic places or administrative areas
- service areas, territories, zones, or markets
- provider/source attribution
- confidence or precision
- geometry or boundary data when territory logic is needed
- links between people/business records and locations
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Location should have a roomy, generic foundation for normalized location records, contact-location links, areas/markets/service zones, and area assignments while consuming other Engage Core modules only through later public seams.

## Intended authoring model

Location should support developer/operator-authored locations and areas first.

Likely authoring sources:

```text
manual admin/operator entry
client-specific setup/config later
provider-synced/geocoded data later
import-derived contact address data later
vertical-specific setup/presets later
```

The first implementation should not require a polished client-facing location builder or polygon editor.

Default client-facing actions should be simple:

```text
choose a saved service area
view contacts in this market
store/update a contact address
run a location-aware outreach/reporting action later
```

## Owns

Location owns:

```text
locations
contact_locations
location_areas
location_area_assignments
```

Location should also own, when implemented:

```text
address/location normalization
contact-location lifecycle
geocoding result storage
service area, market, region, territory, and zone definitions
radius/area membership support
location read/query services
optional saved-place support for modules that have a first-class location relationship
geocoding provider contracts/managers
location-aware contact filter provider, when a consuming surface needs it
```

## Does not own

Location does not own:

```text
Core Contact records
Scheduling appointment lifecycle
Portal accounts
Messaging delivery
Commerce orders
Documents uploads
vertical-specific territory strategy
provider adapter internals
route optimization
full GIS editing UX
map tile/layer management
turn-by-turn routing
tax jurisdiction behavior
```

Do not add latitude, longitude, address, market, or service-area fields directly to `contacts` by default.

## Consumes

For the first foundation slice, Location depends only on Core.

Location may later consume these modules through public seams when enabled:

```text
Core
Scheduling
Portal
Commerce
Reporting
Integrations/adapters
```

Expected usage:

```text
Core -> contact-linked locations
Scheduling -> appointment eligibility or service-area checks later
Portal -> customer-facing profile/address screens later
Commerce -> billing/shipping location normalization later
Reporting -> location-aware summaries later
Integrations -> geocoding/address provider adapters behind Location-owned contracts later
```

## Consumed by

Location may be consumed by:

```text
Scheduling
Commerce
Music
PetServices
Mortgage
Reporting
FlowRoutes
Campaigns
Broadcasts
Portal
```

Consumers should use public Location actions/services/contracts/events/read services rather than directly mutating Location internals when those seams exist.

Expected future examples:

```text
Broadcasts asks a future Core/Location filter seam for contacts in a service area.
Scheduling checks whether a contact is inside a service area before offering an appointment.
Music targets contacts near an upcoming show.
PetServices checks whether a dog trainer serves the customer's location.
Reporting summarizes contacts/orders by market or region.
```

Scheduling appointments may optionally reference saved Location records for reusable places. This does not make Location required for Scheduling feature visibility; Scheduling can still use freeform `location_type` and `location_details` when Location is not enabled or when a saved place is unnecessary.

## Public seams to add later

The first foundation slice does not need full actions yet.

Likely future public seams:

```text
CreateLocationAction
UpdateLocationAction
LinkContactLocationAction
CreateLocationAreaAction
AssignLocationAreaAction
GeocodeLocationAction
LocationReadService
LocationAreaReadService
LocationEligibilityService
LocationContactFilterProvider
LocationProviderManager
GeocodingProvider
```

Public actions should exist before other modules directly create or mutate Location records.

Do not add the filter seam until a consuming workflow needs it, unless the future seam exposes a schema gap that must be fixed pre-rollout.

## Schema foundation

The first Location foundation adds:

```text
locations
contact_locations
location_areas
location_area_assignments
```

These tables are intentionally roomy but generic.

They include:

```text
normalized address fields
optional coordinates
timezone
precision/confidence
provider/external identifiers
raw provider payload storage
contact links
subject morphs
service area/market/region fields
boundary type and optional geometry/radius/settings
area assignment records
meta
timestamps
soft deletes
```

They avoid vertical-specific territory meaning, UI-builder assumptions, routing optimization, and provider-specific first-class behavior before implementation exists.

## Table notes

### locations

Represents a normalized address, place, virtual location, or region-like location record.

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

Notes:

```text
latitude/longitude are nullable because not every useful location is geocoded yet.
precision/confidence are generic provider-result hints, not provider-specific decisions.
raw_payload preserves provider output without promoting provider-specific fields into universal schema.
```

### contact_locations

Links Core contacts to Location-owned records.

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

Notes:

```text
type supports practical roles such as home, work, service, billing, and shipping.
subject morph allows a contact-location link to be about another record later without Core owning that context.
```

### location_areas

Represents a market, service area, territory, region, zone, radius area, or custom location grouping.

Important fields:

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

Notes:

```text
boundary_type describes how the area is meant to be interpreted later.
settings can store postal-code lists, county/state lists, provider hints, or other generic area configuration until runtime behavior deserves first-class tables.
geometry is nullable JSON and does not imply a polygon editor or spatial database dependency.
```

### location_area_assignments

Links a contact, location, or future subject to a LocationArea.

Important fields:

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

Notes:

```text
Assignments allow precomputed/manual membership without forcing every future area query to be calculated dynamically.
role can distinguish member, serviceable, or excluded records.
```

## First foundation slice

Schema/model/migration changes:

```text
app/Modules/Location/Providers/LocationModuleServiceProvider.php
app/Modules/Location/Models/Location.php
app/Modules/Location/Models/ContactLocation.php
app/Modules/Location/Models/LocationArea.php
app/Modules/Location/Models/LocationAreaAssignment.php
database/migrations/*_create_locations_table.php
database/migrations/*_create_contact_locations_table.php
database/migrations/*_create_location_areas_table.php
database/migrations/*_create_location_area_assignments_table.php
database/factories/LocationFactory.php
database/factories/ContactLocationFactory.php
database/factories/LocationAreaFactory.php
database/factories/LocationAreaAssignmentFactory.php
```

Code integration changes:

```text
Register location in config/modules.php.
Keep it disabled by default.
Depend only on Core.
```

Tests only:

```text
tests/Feature/Location/LocationFoundationTest.php
```

Deferred work:

```text
geocoding provider adapters
radius queries
service-area eligibility service
Scheduling service-area eligibility beyond optional saved appointment places
Broadcast/Campaign location filters
Portal location/profile screens
vertical-specific location interpretation
reporting surfaces
route optimization, if ever needed
```
