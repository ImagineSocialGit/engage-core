# Events Module

Events is a planned universal module with an approved architecture and no repository implementation yet.

Events is a thin catalog and reconciliation capability for concrete events that are operated, produced, ticketed, hosted, or streamed outside Engage Core.

Events gives Engage Core one canonical Event identity against which optional modules can coordinate messaging, promotion, attendance, Experiences, tasks, automation, reporting, and external listing exports without turning Engage Core into an event provider or production-management suite.

A concise definition:

> Events catalogs real-world or externally managed events so Engage Core can associate, automate, and report on the activity it handles around them.

## Product barometer

Events should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

Appropriate client/admin work:

```text
Create one concrete Event.
Confirm its schedule and location snapshot.
Move it from draft to upcoming when core readiness passes.
Postpone, reschedule, cancel, or complete it.
Maintain external links and occurrence-specific stakeholders.
Record whether a known Contact attended.
See whether the Event may be promoted yet.
```

Operator/developer work:

```text
Configure Event type and reference registries.
Contribute capability-specific readiness rules.
Connect optional modules through stable Events seams.
Configure external listing adapters.
Build module-owned FlowRoute, Campaign, Broadcast, Messaging, or Reporting contributions.
```

Events must not become a venue-management, ticketing, production, staffing, or generic workflow product.

## Responsibility

Events should answer:

```text
What concrete Event is this?
When and where does it occur?
What is its lifecycle state?
When may downstream promotion begin?
Which passive external references and occurrence-specific stakeholders belong to it?
Which known Contacts were reconciled as attended or not attended?
```

Events stays universal and vertical-neutral.

It may represent:

```text
one concert
one seminar
one conference occurrence
one open house
one community event
one livestream
one hybrid event
one externally ticketed event
```

## Identity and grain

Each Event represents one concrete occurrence.

Examples:

```text
one concert in Chicago on August 14
one seminar on September 10
one livestream on November 1
one open house on a particular date
```

The initial Events module does not include:

```text
EventSeries
recurrence rules
parent/occurrence modeling
reusable venue ownership
itinerary management
multi-day production operations
```

Related or recurring Events remain separate Event records until a proven use case justifies a separate series abstraction.

## Owns

Events owns:

```text
Event identity
type key
lifecycle status
attendance mode
schedule and timezone
historical location snapshot
announcement embargo
core readiness
capability-readiness contribution seams
structured passive external references
occurrence-specific external stakeholders
generic Contact attendance outcomes
duplicate similarity warnings
Events-owned lifecycle actions and read contracts
neutral Events automation signals
```

Expected first owned tables:

```text
events
event_external_references
event_stakeholders
event_attendances
```

## Does not own

Events must never own:

```text
ticket inventory
ticket types
ticket sales
ticket issuance
ticket validation or scanning
ticket transfers
seating or admission
event production
venue operations
staffing
vendor coordination
run-of-show management
contracts
riders
payments
posters
social publication
recipient messaging
VIP access
benefit grants or fulfillment
artist, lineup, tour, or setlist meaning
a workflow engine
```

External ticket URLs and provider identifiers are passive references only. They do not mean Engage Core owns ticketing or can verify admission.

Engage Core is not recreating Eventbrite.

## Dependency direction

Events depends only on Core.

```text
Core
└── Events
```

Events must not depend on:

```text
FlowRoutes
Messaging
Campaigns
Broadcasts
Tasks
Music
Experiences
Commerce
Billing
Webinars
Reporting
Location
```

Optional modules consume Events through public actions, services, registries, read contracts, neutral automation events, and subject references.

Consuming modules own their relationships to Events:

```text
Music        -> Event
Experiences  -> Event
Webinars     -> Event
Commerce     -> Event through Commerce-owned offers or presentation rules
Other module -> Event
```

Events remains unaware of those optional modules.

## Canonical Event record

The first Event record should use normalized first-class columns for durable Event facts.

Expected fields:

```text
events
- id
- type_key nullable
- title
- description nullable
- status
- attendance_mode
- starts_at
- ends_at nullable
- timezone

- venue_name nullable
- address_line_1 nullable
- address_line_2 nullable
- city nullable
- region nullable
- postal_code nullable
- country nullable

- announcement_at nullable
- primary_external_reference_id nullable

- created_at
- updated_at
- deleted_at
```

Repository convention uses `country` for an ISO 3166-1 alpha-2 country code. Do not introduce a parallel `country_code` Event convention.

Store `starts_at`, `ends_at`, and `announcement_at` in UTC. Store the valid IANA timezone used to interpret and present local Event time.

Do not add a generic `meta` column to Event-owned tables in the foundation merely for future flexibility. Add first-class fields or a narrowly justified JSON field only when a proven operational requirement cannot be represented cleanly.

Do not add an Event image/media column until Engage Core has a shared media ownership and attachment convention. Bandsintown or public-page media needs must not force an isolated Event-only storage pattern.

## Event type registry

`type_key` is optional and registry-backed.

Possible contributed keys may include:

```text
concert
seminar
conference
open_house
livestream
community_event
```

The key set must not be a hard-coded exhaustive enum. Clients and modules may contribute supported definitions through an Events-owned registry contract.

An Event type contribution may provide compact metadata such as:

```text
key
label
description
sort order
optional capability hints
```

Type contributions must not smuggle vertical-owned state into the Event record.

## Attendance mode

Initial controlled values:

```text
physical
virtual
hybrid
```

Attendance mode is a fixed universal Event concept and should use an enum or value object rather than a client-extensible registry.

## Location snapshot

Events stores an inline historical location snapshot.

The Event retains the location that was operationally true for that occurrence. Updating a saved Location record elsewhere must not rewrite historical Event facts.

The foundation must not add `events.location_id`.

Later Location integration may:

```text
geocode an Event snapshot through a public Location service
associate a Location-owned assignment with the Event subject
provide radius or area calculations
provide location-aware Contact filters
```

Location remains optional. The Event snapshot remains authoritative for Event history.

Coarse city/region/country targeting may use Event-owned snapshot fields. Radius targeting requires Location-owned geocoding and query services.

## Lifecycle

Initial statuses:

```text
draft
upcoming
postponed
completed
cancelled
```

Expected transitions:

```text
draft -> upcoming
    explicit action after core readiness passes

upcoming -> postponed
    explicit action

postponed -> upcoming
    explicit reschedule action with a confirmed new schedule

upcoming -> cancelled
    explicit action

upcoming -> completed
    automatic only after a non-null ends_at has passed
    or explicit authorized completion/correction
```

Do not automatically complete an Event at `starts_at` when `ends_at` is absent. A missing end time does not prove that the Event has ended.

`cancelled` and `postponed` are domain states, not deletion.

Events use soft deletion for ordinary record removal.

Authorized manual correction may repair lifecycle mistakes while preserving current domain truth.

## Announcement and promotion gates

`announcement_at` is a hard upstream promotion gate.

```text
announcement_at is null
-> timing is unknown
-> promotion is blocked

announcement_at is in the future
-> Event is embargoed
-> promotion is blocked

announcement_at has been reached
-> announcement timing alone no longer blocks promotion
```

An Event may be `upcoming` while still embargoed.

Events should expose two decisions:

```text
EventAnnouncementGate
    answers whether the announcement embargo has lifted

EventPromotionGate
    is the authoritative public/downstream promotion decision
    checks lifecycle, readiness, announcement timing, and other universal blockers
```

The promotion gate must block at least:

```text
draft Events
postponed Events
cancelled Events
completed Events
Events that fail required promotion readiness
Events with unknown or future announcement_at
```

No downstream capability may bypass the authoritative promotion gate, including:

```text
Commerce offer publication
Experience sales or promotion
Messaging
Campaigns
Broadcasts
FlowRoutes
Bandsintown publication
social publishing
public Event surfaces
```

## Core readiness

Core readiness controls `draft -> upcoming`.

Required:

```text
title
attendance mode
exact start date and time
valid IANA timezone
```

For physical or hybrid Events, also require:

```text
venue name
city
country
region when required by the country rules, initially including the United States and Canada
```

For virtual Events, require:

```text
a structured livestream external reference
```

Supported but not required for core readiness:

```text
end time
description
street address
postal code
primary public reference
announcement date
media
```

An embargoed Event may still be a valid upcoming Event.

## Capability-specific readiness

Optional operations use separate readiness contributors.

Examples:

```text
CoreEventReadiness
PromotionReadiness
BandsintownExportReadiness
GeoTargetingReadiness
AttendanceReconciliationReadiness
```

Optional modules and integrations must not expand the universal core readiness contract.

The Events-owned readiness registry should aggregate contributions without importing the contributing module's private models or services into Events.

## Structured external references

All Event URLs and provider identities belong in structured rows.

Expected fields:

```text
event_external_references
- id
- event_id
- provider_key
- reference_type
- external_id nullable
- url nullable
- label nullable
- created_at
- updated_at
- deleted_at
```

At least one of `external_id` or `url` must be present.

Examples:

```text
artist_site   | public_page
venue_site    | event_page
bandsintown   | listing
youtube       | livestream
youtube       | recording
ticketmaster  | ticket_page
external_vip  | vip_page
maps          | directions
```

Requirements:

```text
provider_key is Events-registry-backed
reference_type is Events-registry-backed
provider external identity is uniquely constrained where appropriate
URL-only duplicate handling occurs in the application layer
references remain passive integration data
```

### Primary external reference

`events.primary_external_reference_id` may select one public-facing reference that belongs to the same Event.

Do not duplicate that identity in a `public_url` Event column.

Because `events` and `event_external_references` form a circular reference, create the tables first and add the primary-reference foreign key in a separate migration. Project State must restore that pointer through an explicit deferred reference.

An external ticket page may become primary only through explicit admin selection.

## Event stakeholders

Events owns occurrence-specific external stakeholder snapshots.

These people are not automatically Core Contacts or InternalNotifications TeamMembers.

Expected fields:

```text
event_stakeholders
- id
- event_id
- role_key
- name
- organization nullable
- email nullable
- phone nullable
- notes nullable
- created_at
- updated_at
- deleted_at
```

Possible role keys:

```text
agent
promoter
venue_contact
tour_manager
production_manager
```

Requirements:

```text
role keys are Events-registry-backed
custom roles may be contributed
multiple stakeholders may share one role
contact details are occurrence-specific snapshots
stakeholders are never automatically inserted into Core
```

Optional consumers may address stakeholders by role. Events does not own notification delivery.

## Attendance reconciliation

Events owns only generic Contact attendance outcomes.

Expected fields:

```text
event_attendances
- id
- event_id
- contact_id
- status
- observed_at
- source_key
- source_reference nullable
- created_at
- updated_at
- deleted_at
```

Initial statuses:

```text
attended
did_not_attend
```

Recommended uniqueness:

```text
UNIQUE (event_id, contact_id)
```

Events does not own:

```text
invitation state
RSVP state
registration
ticket ownership
eligibility
VIP entitlement
Experience participation
Campaign enrollment
```

Attendance writes should go through one Events-owned action.

The action should:

```text
validate the registered source key
create or update the one Event/Contact row
accept the latest authoritative observation
emit the neutral attendance signal after commit
remain idempotent for repeated provider or operator observations
```

No observation ledger or conflict-resolution subsystem is required initially.

Experience or VIP check-in must not automatically create Event attendance. An explicit owning-module decision may later call the Events attendance action when business rules prove that equivalence.

## Duplicate detection

Do not enforce uniqueness on title, venue, artist, or schedule.

Legitimate Events may share those values.

Hard duplicate prevention applies to unique provider identities in external references.

Soft similarity detection should run:

```text
when creating a draft
when promoting a draft to upcoming
```

Compare normalized:

```text
title
venue name
city
local start date and time
```

Local comparison must use the Event timezone rather than comparing raw UTC display values.

When a likely duplicate exists, warn:

> An event with the same name, venue, and date already exists. Confirm that you want to continue.

The admin may continue only after explicit confirmation.

## Automation events

Events records domain state first and then emits neutral automation events through the shared Automation Event outbox.

Initial actionable keys:

```text
event.created
event.upcoming
event.announcement_reached
event.postponed
event.rescheduled
event.cancelled
event.completed
event.attendance_recorded
```

Broad implementation events such as `event.updated` or `event.announcement_changed` should remain ordinary domain events unless a proven automation workflow requires them.

Automation payloads should stay compact and reference canonical records rather than copying Event object graphs.

Events automation signals may be contact-aware without being contact-required:

```text
Event lifecycle signal
    contact_id nullable
    subject = Event

Attendance signal
    contact_id = EventAttendance contact
    subject = EventAttendance or Event according to the final public contract
```

## Automatic reconciliation job

A scheduled reconciliation job may:

```text
emit event.announcement_reached once when the embargo lifts
complete upcoming Events only when a non-null ends_at has passed
```

The job must be idempotent and must not infer completion from `starts_at` when `ends_at` is absent.

## Optional-module boundaries

### FlowRoutes

Events remains fully functional without FlowRoutes.

FlowRoutes owns process orchestration. Events owns Event records and lifecycle actions.

Current FlowRoutes runtime is still Contact-centered. Event-level, contactless lifecycle routes require the planned subject-first FlowRoutes generalization before they may be enabled. Attendance events that identify a Contact may use existing contact-aware automation paths once the public integration is implemented.

FlowRoutes must call Events public actions and must not write Event tables directly.

### Messaging

Messaging owns templates, chains, consent, scheduling, delivery, and provider attempts.

Events may provide stable Event token/context data. Events must not schedule or deliver messages itself.

### Broadcasts and Campaigns

Broadcasts and Campaigns may target Contacts based on Events only after Core exposes the planned contributor-based Contact-filter seam.

Events may contribute Event-aware filter definitions and query behavior. Broadcasts and Campaigns continue to own recipient or enrollment lifecycle.

### Tasks

Tasks owns Task records and lifecycle.

Events may expose Event subjects and public actions/automation signals that optional Task contributions consume.

### Reporting

Reporting owns reporting queries and dashboards. Events may expose stable read contracts and dimensions.

### Webinars

Webinars may later own an association to Event. Events must not import Webinar models or provider behavior.

### Location

Location may geocode Event snapshots or calculate radius/area behavior through public services. Events retains the historical snapshot and remains usable without Location.

### Music

Music owns artist association, concert/show meaning, lineup, setlist, tour context, music-specific production requirements, and Bandsintown mapping.

Events must not contain Music-specific columns.

### Commerce

Commerce owns public storefront/offers, product presentation, provider-backed checkout orchestration, purchase records, inventory-effect orchestration, and purchase lifecycle.

An Event-linked Commerce offer inherits the Event promotion gate. Events does not own pricing, checkout, orders, or entitlements.

### Experiences

Experiences owns VIP packages, entitlements, participants, benefits, QR credentials, management access, check-in, and fulfillment.

An Experience occurrence may optionally reference one Event through an Experiences-owned relationship. Event-linked Experiences inherit the Event promotion gate.

Events does not know Experiences exists.

## Bandsintown boundary

Bandsintown is initially an export-only integration assembled from:

```text
canonical Event
+ Music-owned artist/show data
+ structured external references
+ adapter-owned Bandsintown options
-> deterministic Bandsintown CSV
```

Events-owned mappings include schedule, timezone, location snapshot, Event name, description, announcement timing, and relevant structured streaming/public references.

Music owns Artist Name, lineup, setlist, and music-specific display decisions.

The adapter owns exact CSV headings, ordering, accepted destination values, scheduling fields, and Bandsintown-specific formatting.

The external template must not dictate Events schema.

Pre-announcement export is allowed only when the adapter can guarantee publication or scheduling no earlier than `announcement_at`. Otherwise export remains blocked until the embargo lifts.

Initial export retention:

```text
deterministic on-demand generation
no export-history tables
no retained per-row payload copies
generation time and adapter/template version in filename or artifact metadata
```

## Project State

Events durable state must be supported by Project State in the same implementation workstream as the Events foundation. The fresh repository snapshot is Project State version 11, so an Events transfer section is expected to produce version 12 if no other format-changing batch lands first.

Expected dependency-safe section order:

```text
events
event_external_references
event_stakeholders
event_attendances
deferred events.primary_external_reference_id restoration
```

Project State must also classify the Event morph aliases used by Automation Events and any other supported polymorphic references.

Do not ship operational Events while its durable tables remain under a must-be-empty table policy.

Ephemeral caches or reconstructible export artifacts should not become transferred durable state.

## Initial implementation order

```text
1. module/config registration and definition registries
2. Events schema, models, factories, and ownership tests
3. Project State Events section and current-format version bump
4. readiness, announcement, promotion, and duplicate gates
5. lifecycle and attendance actions plus neutral automation events
6. scheduled Event reconciliation
7. setup validation
8. CRM Event administration
9. optional consumers through public seams
10. Music/Bandsintown integration
11. Experiences against the stable Events contract
```

Do not include Music, Bandsintown, Commerce, Experiences, FlowRoutes, Messaging, or public storefront behavior in the Events foundation batch.

## Implementation status

Current repository status:

```text
Events module directory: not present
Events tables: not present
Events config: not present
Events Project State section: not present
Events CRM routes/navigation: not present
Events runtime actions/services: not present
```

This document is the canonical architecture reference to use before implementation. Exact file manifests still require a fresh dependency cone for every consumer module touched by a later integration batch.
