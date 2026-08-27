# Scheduling Module

Scheduling is a current universal module.

Scheduling owns reusable appointment and booking capability that can be used by multiple verticals without pushing appointment state into Core or vertical-specific tables.

```text
Architecture tier:   universal module
Product surface:     loud
Standalone value:    yes
Primary surfaces:    CRM workspace, configuration, Contact context, public booking
```

Scheduling owns the complete user-facing appointment and booking experience. Optional supporting modules such as Location, Messaging, or InternalNotifications may enhance that experience through explicit seams, but Scheduling must remain usable without them.

## Product expectation

Scheduling should follow the Engage Core product barometer:

```text
A client-facing scheduling task should be completable in roughly 5-10 minutes total, and common appointment scheduling should usually take far less.
```

Scheduling a known appointment on a known day should feel closer to a 30-second task than a configuration workflow.

Client-facing Scheduling UX should focus on fast actions:

```text
Schedule this appointment.
Reschedule this appointment.
Cancel this appointment.
Confirm attendance.
Mark completed or no-show.
```

Developer/operator-facing setup may own the more complex work:

```text
Define bookable services.
Assign hosts when explicit staff/provider assignment is needed.
Configure advanced staff/resource/capacity scheduling policy when needed.
Connect external calendar providers.
Wire reminders and follow-up behavior.
```

Scheduling should not become a generic calendar-builder product for clients to maintain.

## CRM first-use and readiness UX

The Scheduling CRM workspace distinguishes first-use setup from routine scheduling work. A truly empty installation should not present a cockpit of zero-value counters and unusable controls. It should guide the operator through the business prerequisites in this order:

```text
1. What can people schedule?
2. Who handles appointments? (optional unless explicit assignment is needed)
3. When can appointments happen?
4. Book a test appointment
```

`SchedulingSetupReadiness` derives setup state from current Scheduling-owned runtime facts. Internal readiness requires at least one active service and at least one applicable positive availability window. An active SchedulingHost is intentionally not required because hostless services are valid runtime behavior. Public readiness additionally requires the public Scheduling surface to be enabled and at least one active public service.

The first-use service create path asks for business inputs and generates or defaults technical values such as stable keys, status, timezone, capacity, slot interval, booking horizon, and sort order. Existing advanced service settings remain available after creation for uncommon policy needs such as variable-length/range bookings, buffers, notice windows, capacity policy, and detailed location behavior.

SchedulingHost remains the internal model name, but normal client-facing setup uses staff/provider language. The UI should not require a client to understand internal host, provenance, key, source-ownership, or sort-order terminology to add a person who can handle appointments.

Resources remain an advanced capability for rooms, equipment, or other limited shared items and should not dominate basic Scheduling setup.

The availability engine remains generalized and authoritative, but the normal CRM authoring surface no longer asks users to think in generalized rule-engine terms. The service-first availability workspace now centers on regular weekly hours, one-off special hours, and whole/partial time off. Multiple time ranges per day are supported, one-off changes may be removed so regular hours apply again, and the live test surface resolves actual bookable times through the authoritative engine. For fixed-duration services, consecutive valid appointment starts are summarized as start-time ranges while the exact underlying slot instants remain authoritative and selectable.

Advanced rule authoring remains available behind progressive disclosure for staff-specific availability, capacity overrides, provider/system diagnosis, and uncommon scheduling policies. Normal Scheduling setup does not expose the engine's union/intersection precedence model.

Any specialized term that must remain visible follows the shared UI/UX rule: explain it visibly below the control when understanding is important to the current decision, or use an accessible hover/focus/tap/click help affordance for secondary repeated terminology.

## Universal public booking surface

Scheduling provides every client with an optional generic public booking surface where visitors can discover public services, reserve one time through an authoritative short-lived hold, enter attendee details, and complete the hold into an Appointment.

The public host is selected-client deployment configuration. It must not be derived from a fixed subdomain prefix.

Examples:

```text
https://schedule.[ROOT_DOMAIN]
https://booking.[ROOT_DOMAIN]
https://appointments.[ROOT_DOMAIN]
https://[CUSTOM_SCHEDULING_DOMAIN]
```

The expected environment contract is:

```text
SCHEDULING_APP_URL=https://booking.[ROOT_DOMAIN]
```

`config/scheduling.php` normalizes that value as a root-level HTTP or HTTPS origin and derives the route host. An omitted value intentionally disables only the optional public surface. A non-empty malformed value also keeps public routes disabled for safety, but `SchedulingSetupValidationContributor` reports it as a `setup:validate` error so a deployment cannot silently lose public booking. `ClientEnvironmentLoader` treats `SCHEDULING_APP_URL` as selected-client deployment configuration.

The currently implemented public routes are:

```text
GET  /
GET  /services/{serviceKey}
POST /services/{serviceKey}/prepare
POST /services/{serviceKey}/offers
GET  /offers/{offerId}
POST /offers/{offerId}/hold
GET  /book/{holdId}
POST /book/{holdId}
```

They are registered only on the configured host while the Scheduling module is enabled. The catalog returns active services with `is_public = true`. Service pages progressively collect only the prerequisites required by the selected service before the relevant authoritative availability decision. Fixed-duration pages accept one bounded local date, calculate live availability through `FindBookableAvailabilityAction`, show times in the service timezone, and omit host identity, capacity, occupancy, availability-window identity, and other trusted booking details. Identical times produced by multiple eligible hosts are presented once.

A displayed fixed-duration time submits only its UTC `starts_at` value. Range-duration services submit local check-in/check-out wall times under the closed duration policy. The server revalidates the selection and issues an opaque, short-lived `BookableSlotOffer` without consuming capacity. A separate offer POST accepts only a UUID hold idempotency key and creates the real `BookingHold` after revalidating the exact service, location, host, travel, capacity, and resource state. The visitor cannot nominate a host, authoritative end time, normalized location, duration, capacity, offer provenance, or rule provenance.

The opaque hold page accepts attendee name, email, and optional phone only while the hold remains effective. `CompletePublicBookingAction` resolves the Contact through the Core-owned `ResolveContactByEmailAction`, supplies immutable attendee snapshots, and converts the hold through `ConvertBookingHoldToAppointmentAction`. An existing Contact is returned unchanged; public input never overwrites established Contact fields or metadata. Reservation, completion, and hold-review routes are rate limited through `config/scheduling.php`.

All public booking, cancellation, and reschedule URLs should resolve from the configured base URL.

The universal booking surface is separate from CRM, Portal, and Webinars. A webinar-triggered booking journey may add source context, eligibility, and tailored copy through a thin client-specific integration layer, but it must consume generic Scheduling contracts and must not shape Scheduling around Webinars.

### Progressive public booking contract

The implemented flow begins with appointment type because the selected service determines which prerequisites are required before authoritative availability can be calculated. The current visitor sequence is:

```text
1. choose appointment type
2. provide only the details required for that type
3. view authoritative availability
4. select one short-lived non-blocking slot offer
5. verify one reachable email or SMS destination when Messaging can deliver
6. revalidate service, location, slot, and capacity
7. create the capacity-consuming hold
8. review and complete the booking
```

Blade may present these steps as progressively revealed pages, and Alpine may later add sliding animation. Presentation does not establish authority. Each transition that changes trusted state is server validated, and later pages do not trust hidden browser-authored service, host, duration, normalized location, verification, offer provenance, or capacity fields.

Service location policy determines the details step:

```text
phone or virtual service
    no customer location prerequisite

fixed-location service
    use the configured fixed location

customer-site service
    collect and normalize the service address before calculating availability
```

Customer-site availability must not be presented as authoritative until Scheduling has a normalized server-owned location. Browser-supplied coordinates, travel durations, or a boolean claiming that a destination was verified are never authoritative.

Phase 4B.4 owns the actual Messaging-backed verification behavior. The non-blocking offer review boundary intentionally exists before the real hold so verification can be inserted there without making Messaging a Scheduling dependency.

22E2A establishes the verification foundation behind that boundary without changing the public hold endpoint yet. `PublicBookingDestinationVerificationService` owns short-lived cache-backed challenges and proofs bound to the opaque offer plus the current booking session. Challenge codes are stored only as keyed hashes in Scheduling state, attempts and resends are bounded, IP/destination/offer-session/challenge request rates are limited, proof tokens are short-lived and single-use, and neither challenges nor proofs create Contacts, holds, Appointments, or Project State records.

Delivery crosses a neutral app-level `DestinationVerificationTransport` contract. When both Scheduling and Messaging are enabled, `AppServiceProvider` binds the Messaging bridge and registers a recipient gate that permits only transactional `scheduling_public_booking` verification messages for active ordinary public offers. When Messaging is disabled or has no deliverable verification channel, the neutral unavailable transport exposes no channels and Scheduling remains usable. This verification path does not query, grant, revoke, or otherwise mutate marketing consent.

22E2B completes that boundary. The public offer review surface exposes issue, verify, and resend transitions only when at least one eligible verification channel is currently deliverable. The browser submits the requested channel/destination and later the one-time code, but never receives the challenge ID or proof token as booking authority. Laravel session state keeps the challenge/proof handle under the opaque offer identity, and `CreatePublicBookingHoldRequest` explicitly prohibits caller-authored challenge IDs, proof tokens, `verified` flags, and other verification authority.

`CreatePublicBookingHoldAction` independently checks whether verification is currently required, validates the server-owned proof against the locked ordinary public offer and current booking session, consumes that proof exactly once, and only then delegates to `CreateBookingHoldAction`. The real hold action remains authoritative for current service/public status, location commitment, host assignment, resources, capacity, exact interval, and travel fit. A proof therefore cannot reserve capacity by itself or bypass a race that makes the slot unavailable. An idempotent replay of an already-created hold remains replayable after the original proof has been consumed. When no eligible verification transport exists, the direct offer-to-hold path remains available.

Project State now treats Scheduling as an optional schema-activated section. Durable Scheduling configuration, availability, Appointments, attendees, lifecycle history, and Appointment-owned resource occupancy survive a controlled clean rebuild. `bookable_slot_offers` and `booking_holds` remain transient export blockers, and destination-verification challenges/proofs remain cache/session state rather than Project State data.

Import clears environment- or integration-local `hostable`, `locationReference`, `createdBy`, and lifecycle-actor morphs that cannot be safely remapped from the transfer document. Canonical Scheduling-owned location snapshots remain authoritative. Core-backed primary-attendee and source-context references are remapped only for explicitly supported Core subject types; unsupported polymorphic subjects fail closed during Project State validation.

## Responsibility

Scheduling answers:

```text
What can be booked?
Which hosts can deliver it?
When is it available?
What capacity remains?
Who is attending?
Where does it happen when that matters?
What is the lifecycle state of the appointment?
```

Scheduling stays vertical-neutral. It may support consultations, coaching calls, music lessons, pet-service appointments, studio bookings, internal meetings, or other bookable interactions without owning their vertical meaning.

## Owns

Scheduling owns:

```text
bookable_services
scheduling_hosts
bookable_service_hosts
scheduling_availability_windows
appointments
appointment_attendees
appointment_lifecycle_events
bookable_slot_offers
booking_holds
```

Scheduling also owns:

```text
service duration, interval, notice, horizon, buffer, capacity, and confirmation policy
host identity and capacity
service-to-host eligibility
availability and blackout rule evaluation
read-only bookable-slot calculation
appointment lifecycle and reschedule lineage
appointment-related source context
opaque expiring slot offers and short-lived booking holds
hold-aware availability, explicit hold release, and atomic hold-to-Appointment conversion
transaction-time slot, occupancy, capacity, and idempotency revalidation
reschedule-aware offer issuance with one trusted source-Appointment exclusion
atomic hold-to-reschedule replacement with attendee and vertical-subject preservation
appointment lifecycle transitions and neutral automation event emission
configured-host appointment-type-first public service discovery and bounded availability presentation
service-specific prerequisite collection before authoritative customer-site availability
non-blocking public slot offers with deterministic hidden-host selection and immutable location commitment snapshots
separate authoritative public offer-to-hold conversion with full capacity/resource/travel revalidation
public attendee capture, safe Contact resolution, and replay-safe hold-to-Appointment completion
```

Scheduling does not own message delivery, consent, task lifecycle, portal accounts, form definitions, commerce records, reusable Location identity, address/geocoding provider contracts, or provider adapter internals outside Scheduling-owned calendar, meeting, and travel-resolution contracts.

## Consumes

Scheduling may consume these modules through public seams when enabled:

```text
Core
Messaging
Tasks
InternalNotifications
Portal
Forms
Commerce
Location
Integrations/adapters
```

Expected usage:

```text
Core -> contact-linked appointments
Messaging -> customer-facing reminders and lifecycle messages
InternalNotifications -> team-facing scheduling alerts
Tasks -> manual work generated from appointment outcomes
Portal -> authenticated customer schedule views or booking entry
Forms -> intake submissions associated with booking flows
Commerce -> optional paid-booking order/payment state
Location -> optional enrichment or reusable saved-place identity through a future app-level bridge when both modules are enabled; Scheduling does not import or dependency-load Location and remains the owner of baseline normalization, appointment policy, snapshots, UI, and travel decisions
Integrations -> calendar and meeting-provider adapters behind Scheduling contracts
```

## Consumed by

Scheduling may be consumed by:

```text
PetServices
Music
Mortgage
FlowRoutes
Campaigns
Reporting
Portal
Forms
Documents
Commerce
Webinars through a client-specific integration layer
```

Consumers must use public Scheduling actions, services, contracts, events, or read services rather than directly mutating Scheduling tables.

## Current persistence foundation

### bookable_services

Represents something that can be scheduled.

Important policy fields:

```text
key
status
duration_mode
duration_minutes
minimum_duration_minutes
maximum_duration_minutes
slot_interval_minutes
buffer_before_minutes
buffer_after_minutes
minimum_notice_minutes
booking_horizon_days
cancellation_notice_minutes
reschedule_notice_minutes
timezone
capacity
requires_confirmation
is_public
location_type
location_details
source
meta
```

Duration policy is now first-class:

```text
fixed
    duration_minutes is the exact authoritative interval length
    minimum_duration_minutes / maximum_duration_minutes are unused

range
    starts_at + ends_at define the authoritative booking interval
    duration_minutes is the default candidate length for availability previews
    minimum_duration_minutes / maximum_duration_minutes bound accepted intervals
    maximum range duration cannot exceed the existing 366-day Scheduling search limit
```

Range mode is universal Scheduling policy rather than PetServices-specific state. A multi-day stay remains one Appointment/Hold interval; a vertical such as PetServices owns pet, vaccination, intake, feeding, medication, and pricing meaning around that interval.

Provider identity currently retained on this table is legacy foundation state. Provider connection, remote identity, and synchronization state should move to dedicated provider-owned persistence when that batch is implemented.

### scheduling_hosts

Represents a person, team, room, or other generic appointment host.

Important fields:

```text
key
name
status
hostable_type / hostable_id
timezone
capacity
email
phone
sort_order
source
meta
```

The optional `hostable` morph links a host to a Core or other allowed model without making that model own Scheduling state.

### bookable_service_hosts

Represents host eligibility for a service.

Important fields:

```text
bookable_service_id
scheduling_host_id
is_active
capacity_override
sort_order
meta
```

An inactive assignment remains explicit configuration and must not cause the service to fall back to unhosted booking.

A service with no assignment records may still be unhosted when service-wide availability exists.

### scheduling_availability_windows

Represents positive availability or a blackout.

Every rule is explicitly one of:

```text
weekly
absolute
```

Weekly rule shape:

```text
window_type = weekly
weekday
start_time
end_time
timezone
starts_at = null
ends_at = null
```

Absolute rule shape:

```text
window_type = absolute
starts_at
ends_at
timezone
weekday = null
start_time = null
end_time = null
```

Every rule targets at least one of:

```text
service only
host only
service + host
```

`is_available = false` represents a blackout or exception using the same closed shapes.

Availability rows do not store arbitrary RRULE expressions or provider remote identity. External busy intervals belong to provider synchronization/read contracts rather than reusable manual availability rules.

### appointments

Represents the local source of truth for a scheduled appointment.

Important fields:

```text
bookable_service_id
scheduling_host_id
contact_id
location_reference_type / location_reference_id
primary_attendee_type / primary_attendee_id
source_context_type / source_context_id
rescheduled_from_id
idempotency_key
status
title
description
location_type
location_details
timezone
starts_at / ends_at
confirmed_at
completed_at
no_show_at
canceled_at
cancellation_reason
source
created_by_type / created_by_id
meta
```

The optional `locationReference` morph may point to a saved Location-owned place when Location is separately installed and an explicit integration supplies that reference. Mutable Location data is never authoritative for a historical Appointment, and Scheduling does not require Location for this polymorphic field to remain null. Existing noncanonical `location_type` and `location_details` values remain readable as legacy snapshots, while new commitment behavior uses the canonical `phone`, `virtual`, `fixed`, and `customer_site` modes.

External calendar systems never own appointment lifecycle. Provider failure leaves the local Appointment valid and later synchronization work pending or failed.

### appointment_attendees

Represents people or subjects attached to an appointment. Most bookings create one primary attendee snapshot, but the table remains one-to-many so group, household, staff-assisted, or other multi-attendee appointments do not require a schema change.

The attendee identity and associated Contact are intentionally separate:

```text
attendee_type / attendee_id = the appointment subject
contact_id = the associated Core contact when one exists
```

For an ordinary one-on-one appointment, both identities may point to the same Contact. For a pet-service appointment, the polymorphic attendee and Appointment `primary_attendee` may point to a PetServices-owned pet while `contact_id` points to the owner. Scheduling stores the appointment relationship and snapshots; PetServices continues to own pet identity and domain meaning.

### appointment_lifecycle_events

Provides durable append-style appointment lifecycle history with:

```text
event_id
event_key
from_status / to_status
actor_type / actor_id
source
reason
context
occurred_at
```

Lifecycle mutation actions should update the Appointment and record the corresponding lifecycle event in the same transaction.

## Executable availability engine

The read-only availability engine consists of:

```text
AvailabilitySearch
AvailabilityInterval
BookableSlot
AvailabilityRuleResolver
AppointmentOccupancyResolver
BookingOccupancyResolver
FindBookableAvailabilityAction
```

`AvailabilitySearch` normalizes the requested UTC range, display timezone, optional host filter, evaluation time, service minimum notice, and booking horizon. It may also carry one persisted same-service Appointment as a trusted reschedule exclusion. Requests are bounded to prevent accidental unbounded rule expansion.

`AvailabilityInterval` is an internal normalized UTC interval. It retains host identity, applicable capacity, rule scope, source-window identity, and timezone provenance.

`BookableSlot` is the transport-neutral result contract. It exposes service and host identity, UTC instants, display timezone, effective capacity, remaining capacity, and source-rule provenance without exposing Eloquent models.

### Availability precedence

Within one scope:

```text
positive rules are unioned
```

Across applicable scopes:

```text
positive layers are intersected
```

At every applicable scope:

```text
blackouts are subtracted
```

For a host-specific service, applicable layers are evaluated in this order:

```text
service-wide availability
host-wide availability
service-host-specific availability
```

A missing optional layer does not eliminate availability. A configured positive layer restricts availability; when that layer has no matching interval in the requested range, no slot survives that layer.

Blackouts apply even when their scope has no positive rule of its own.

### Weekly timezone and DST behavior

Weekly rules are interpreted as wall-clock times in the rule timezone and then converted to UTC.

A local time that does not exist because of a daylight-saving transition is skipped rather than silently shifted to another wall time.

Appointment duration and slot interval represent elapsed minutes. A slot crossing a DST transition may therefore display a larger or smaller wall-clock jump while retaining its configured elapsed duration.

### Slot alignment

Candidate starts align to `slot_interval_minutes` on the service timezone wall-clock grid.

Fixed-duration candidates must be continuously covered for their full configured duration. Range-duration candidates use availability windows as admissible check-in and check-out boundaries instead: the stay may continue across closed hours between those boundaries while Appointment/Hold/resource occupancy still spans the complete authoritative start/end interval. Range-duration callers may supply an explicit candidate duration derived from the requested start/end interval; when no override is supplied, `duration_minutes` remains the default preview duration. For range candidates, availability-window capacity is resolved from the check-in/check-out boundaries while service, host, assignment, Appointment/Hold, and resource capacity are evaluated across the full stay interval.

### Effective capacity

Effective slot capacity is the lowest applicable configured limit from:

```text
service capacity
host capacity
service-host assignment capacity_override
availability-window capacity
resource-derived capacity, when the service has active requirements
```

Remaining capacity is calculated independently for each limiting dimension across blocking Appointments and effectively active BookingHolds:

```text
service/service-host occupancy consumes service, assignment, and window capacity
all appointments and active holds on a host consume host capacity
resource snapshots consume only their named host resources
converted holds transfer their resource snapshot to the Appointment atomically
released, expired, and elapsed active holds do not consume capacity
```

Appointments in these states consume capacity:

```text
pending
scheduled
confirmed
```

Appointments in these states do not consume future capacity:

```text
canceled
completed
no_show
```

The candidate appointment's buffers and each existing appointment service's buffers are applied before testing coarse overlap. Resource occupancy snapshots store their own buffered UTC range so later service-buffer edits do not retroactively change an existing commitment. A reschedule search excludes only its explicitly supplied same-service source Appointment from both coarse and resource occupancy. Every other Appointment and active hold continues to consume capacity normally.

### Resource-aware occupancy and selective overlap

Scheduling now owns a normalized resource model:

```text
scheduling_resources
scheduling_host_resources
bookable_service_resource_requirements
scheduling_resource_occupancies
```

`scheduling_resources` stores durable resource identities such as:

```text
physical_presence
phone_attention
room
vehicle
crew
equipment
```

Resource keys are durable identities. Status is `active`, `inactive`, or `archived`; source is `manual`, `system`, or `provider`. Resource configuration is database-owned and is manageable through the authenticated Scheduling resource workspace.

`scheduling_host_resources` defines the active capacity a host owns for a resource. `bookable_service_resource_requirements` defines the positive quantity consumed by one Appointment of a service. Both retain explicit inactive rows rather than interpreting omission as fallback behavior.

For each active requirement, total resource-derived Appointment capacity is:

```text
floor(active host resource capacity / service requirement quantity)
```

Remaining resource-derived capacity is:

```text
floor(
    (active host resource capacity - overlapping resource occupancy quantity)
    / service requirement quantity
)
```

The lowest result across all required resources applies. A required resource closes availability when the resource is inactive or archived, the service is unhosted, the host lacks an active capacity row, the capacity is invalid, or the requirement quantity exceeds host capacity.

Resources refine rather than replace overall concurrency. A contractor host may therefore have:

```text
overall host capacity: 2
physical_presence capacity: 1
phone_attention capacity: 1
```

An on-site service requiring `physical_presence × 1` may overlap a phone service requiring `phone_attention × 1`, while two simultaneous physical-presence Appointments remain blocked.

### Resource commitment snapshots

Resource requirements are resolved and locked whenever capacity is committed:

```text
direct Appointment creation
BookingHold creation
```

A resource-requiring service must use a persisted active host. The transaction locks the current active service requirements, required resource identities, and host resource-capacity rows before exact-slot revalidation. The resulting occupancy stores only normalized operational facts:

```text
resource identity
host identity
Appointment or BookingHold identity
quantity
buffered occupancy start/end UTC instants
```

It does not copy names, labels, host capacities, service configuration, or JSON requirement snapshots.

An active hold owns the immutable resource snapshot for the reservation. Ordinary conversion and reschedule conversion transfer those same occupancy rows from the hold to the resulting Appointment; they do not recalculate against possibly changed service requirements. Direct Appointment retries and converted-hold retries do not create duplicate occupancy rows.

Changing a service requirement or host capacity affects future commitments only. Existing Appointment snapshots remain durable. Active hold snapshots remain authoritative until conversion, release, or expiration.

Temporary hold resource rows are removed transactionally when a hold is released or expires. Conversion transfers them to the Appointment, leaving no terminal hold occupancy. `ExpireBookingHoldsJob` cleans resource occupancy in the same transaction that marks due holds expired.

Services with no active resource requirements retain the previous coarse-capacity behavior exactly.

### Resource configuration workspace

The authenticated CRM resource workspace manages the normalized Batch 20 resource contract without mutating existing Appointment or hold snapshots.

It supports:

```text
manual scheduling-resource creation and update
immutable resource keys
active, inactive, and archived resource status
manual host resource-capacity rows
manual service resource-requirement rows
positive active capacities and quantities
explicit inactive-row preservation
provider/system row visibility without CRM mutation
optimistic stale-form protection
configured service-host resource ceilings and closed reasons
```

Resource, host-capacity, and service-requirement mutations use closed request fields and transactional row locks. CRM-created rows always use `source = manual`; callers cannot submit source, metadata, timestamps, deleted state, or occupancy identities.

Provider- and system-owned resource identities and association rows are read-only. A manual association may target a provider- or model-backed host or a provider-backed service without changing ownership of that target. Updating host capacities bumps the host version; updating service requirements bumps the service version.

An active host capacity or service requirement requires an active, non-deleted resource and a positive capacity or quantity. Existing manual rows omitted from a complete sync are retained as inactive. A never-configured inactive selection does not create a row. A resource cannot be archived while any active host capacity or service requirement still references it.

The workspace calculates the configured resource ceiling for each active service-host assignment from the same normalized capacities and requirements consumed by runtime. It surfaces closed states for inactive targets, inactive resources, missing host capacities, and quantities that exceed capacity. This is a configuration diagnostic only; authoritative date/time availability remains the server-resolved availability preview because Appointments, active holds, availability rules, notice, horizon, and other capacity ceilings are time-dependent.

Changing resource configuration affects future commitments only. Existing `scheduling_resource_occupancies` rows remain immutable operational snapshots and are never rewritten by the configuration workspace.

### Fixed buffers and travel-aware occupancy

`buffer_before_minutes` and `buffer_after_minutes` remain fixed service-level elapsed-minute buffers. For physical hosted work, the availability engine now treats those buffers as occupied setup/parking/safety time and separately requires enough resolved travel time between adjacent physical commitments.

Location is a silent supporting module. Scheduling must not wait for, or create, a standalone Location product before implementing customer-site booking.

The consumer-owned responsibility split is:

```text
Scheduling
    owns service location policy
    owns the public/CRM address-collection experience
    owns closed baseline address validation and deterministic text normalization
    owns whether authoritative availability may be shown
    owns Appointment and BookingHold location snapshots
    owns travel-time policy, fallback, and availability decisions
    owns reservation/direct-creation revalidation

Location, when separately enabled through an optional app-level bridge
    may enrich Scheduling-owned address facts with coordinates, timezone, precision, and confidence
    may attach reusable saved-place identity when durable reuse is intentional
    does not become required for Scheduling booking or Appointment lifecycle
```

`SchedulingLocationSnapshotResolver` creates canonical fixed or customer-site address snapshots using Scheduling-owned deterministic normalization, validates canonical phone/virtual/fixed/customer-site commitment details, and preserves existing noncanonical snapshots only as a compatibility read path. It does not import Location or create a Location row.

`BookingHold` now stores `location_type` and `location_details` as the authoritative location commitment. Ordinary hold conversion and rescheduling copy that immutable hold snapshot into the Appointment. Later edits to a BookableService or reusable saved Location cannot rewrite the held or historical facts. Customer-site direct creation and hold creation require a normalized booking-specific snapshot; fixed, phone, and virtual snapshots are derived from server-owned service configuration.

Phase 4B.2B2 closes the service-location authoring and booking-input surfaces. CRM configuration now accepts only the canonical `phone`, `virtual`, `fixed`, and `customer_site` modes. Fixed services require a Scheduling-normalized address; virtual services may carry a URL; customer-site services store only service-level label/instructions and collect the actual address per booking. Public and CRM customer-site submissions accept only raw address fields and reject caller-authored coordinates, provider identity, formatted address, precision, confidence, or location snapshots. Scheduling normalizes those raw facts before any BookingHold or direct Appointment commitment.

Travel-aware Scheduling uses estimated travel time rather than straight-line distance. For every hosted physical candidate, availability checks both adjacent directions:

```text
previous physical commitment → candidate location
candidate location → next physical commitment
```

The physical commitment set includes active `pending`, `scheduled`, and `confirmed` Appointments plus active BookingHolds. Holds participate because reservation safety must prevent two individually valid offers from becoming simultaneous commitments that leave the same host without enough travel time. Phone and virtual commitments do not create travel constraints.

The required gap is the resolved travel duration after the existing Appointment/Hold occupancy buffers are applied. A timed site-work commitment therefore needs a start, end, and canonical physical location snapshot; a date-only marker is insufficient. Same-address physical commitments resolve to zero additional travel under the built-in fallback and remain subject to ordinary capacity, resource, and buffer rules.

Scheduling owns the provider-neutral `TravelTimeResolver` extension contract. `SchedulingTravelTimeResolver` uses an explicitly bound provider when an app-level integration supplies one and otherwise falls back to `ConservativeTravelTimeResolver`. The built-in fallback returns zero minutes for the same normalized address and otherwise uses `scheduling.travel.conservative_minutes`, currently 45 minutes, bounded by `scheduling.travel.maximum_minutes`, currently 240 minutes. The maximum also bounds the neighboring commitment query required to evaluate candidate slots safely.

A richer routing or geographic integration may bind `TravelTimeResolver` without changing Scheduling's dependency graph. Optional Location enrichment may help that app-level integration obtain coordinates or other provider-neutral geographic facts, but Scheduling does not import or dependency-load Location and remains authoritative for whether the candidate fits.

`FindBookableAvailabilityAction` attaches resolved travel minutes before/after to server-side `BookableSlot` objects for internal ranking while keeping the existing public slot serialization contract compact. `CreateBookingHoldAction` and `CreateAppointmentAction` rerun travel-aware exact-slot availability inside their existing lock-backed transactions, so an offer or earlier availability result cannot bypass a newly created adjacent Appointment or active hold.

Travel, location, and resource requirements are never accepted from browser-authored travel-duration or verification fields and are not copied into arbitrary `meta` payloads.

### Host resolution

When a host filter is supplied, only that active, actively assigned host is evaluated.

Without a host filter:

```text
active service-host assignments are evaluated independently
returned slots retain scheduling_host_id
inactive assignments are excluded
an existing but inactive assignment does not become an unhosted slot
services with no assignment rows may produce unhosted service-wide slots
```

Round-robin host selection is not part of the read-only engine.

## Booking safety boundary

Availability results remain advisory snapshots rather than reservations.

The implemented booking-safety layer consists of:

```text
BookableSlotOffer
BookingHold
IssueBookableSlotOfferAction
IssuePublicBookingSlotOfferAction
CreateBookingHoldAction
CreatePublicBookingHoldAction
CompletePublicBookingAction
ReleaseBookingHoldAction
ConvertBookingHoldToAppointmentAction
ExpireBookingHoldsJob
```

### bookable_slot_offers

A slot offer is an opaque, server-issued, expiring identity for one exact slot. It snapshots:

```text
offer_id
bookable_service_id
scheduling_host_id
reschedule_appointment_id
starts_at / ends_at
display_timezone
capacity / remaining_capacity
location_type / location_details
source_scopes
source_window_ids
issued_at
expires_at
consumed_at
```

The caller receives only the opaque `offer_id`. Public or CRM booking actions must not accept caller-authored service, host, start, end, capacity, timezone, normalized location, or rule-provenance values as authoritative booking input.

`IssueBookableSlotOfferAction` revalidates the supplied server-side `BookableSlot` before persisting the offer. When a canonical commitment location is known, the offer snapshots that location with the exact candidate so later hold creation can revalidate the same commitment instead of accepting browser-authored location state. An ordinary offer stores no reschedule identity. A reschedule-scoped offer locks one persisted source Appointment, verifies that it belongs to the service, remains in a reschedulable state, and has no existing replacement, then stores that identity in `reschedule_appointment_id`. An offer may be consumed only once and cannot create a hold after `expires_at`.

### booking_holds

A booking hold is a short-lived capacity reservation with:

```text
hold_id
bookable_slot_offer_id
bookable_service_id
scheduling_host_id
appointment_id
idempotency_key
status
starts_at / ends_at
occupancy_starts_at / occupancy_ends_at
capacity
location_type
location_details
held_at
expires_at
released_at
converted_at
```

Supported statuses are:

```text
active
converted
released
expired
```

`CreateBookingHoldAction` accepts:

```text
offer_id
idempotency_key
optional canonical customer_site location snapshot for trusted internal compatibility paths
```

For public booking, the selected offer is the location trust boundary: customer-site offers carry the canonical normalized address snapshot that produced their availability result, while phone, virtual, and fixed commitments are re-derived from locked service configuration and checked against any offer snapshot. The optional action argument remains available only for trusted internal compatibility paths and is accepted only for a `customer_site` service. A customer-site reschedule may reuse the source Appointment's immutable customer-site snapshot when the address is unchanged.

It locks the offer, service, optional host, optional assignment, optional reschedule source Appointment, relevant blocking appointments, and overlapping active holds in a deterministic transaction. It reruns exact-slot availability, applies current buffers and capacity, rejects stale or consumed offers, and prevents separate offers from over-reserving one slot.

The same idempotency key returns the original hold for the same offer and commitment location. It is rejected when reused for another offer or another customer-site address.

### Public reservation transaction

The public booking surface is appointment-type-first and progressive:

```text
1. choose one active public service
2. provide only prerequisites required by that service
3. view or choose against authoritative server-evaluated availability
4. select one short-lived non-blocking BookableSlotOffer
5. verify one reachable transactional destination when an eligible transport exists
6. revalidate the selected offer and current booking rules
7. create the real capacity-consuming BookingHold
8. review and complete the booking
```

Service selection remains route-bound by the stable public service key. Phone and virtual services require no customer location prerequisite. Fixed-location services derive their canonical location entirely from server-owned service configuration. A `customer_site` service first accepts only raw address fields:

```text
address_line_1
address_line_2
city
region
postal_code
country
```

`PublicBookingController` normalizes those fields through the Scheduling-owned resolver and stores the canonical customer-site snapshot in the visitor session under a service-scoped key. That session value is prerequisite state only; it does not reserve capacity. Caller-authored normalized or enrichment fields such as `location_type`, `location_details`, `formatted_address`, coordinates, timezone, precision, confidence, or provider identity are rejected. The browser never receives the normalized snapshot as a trusted hidden field.

Customer-site fixed-duration availability is not rendered until that server-owned normalized snapshot exists. The snapshot is then supplied to `FindBookableAvailabilityAction`, so the displayed times already include the same travel-aware location commitment that will be used when an offer and later hold are created. Changing the raw address runs normalization again and replaces the service-scoped prerequisite snapshot before another availability decision.

Fixed-duration services post only the selected server-issued UTC `starts_at` value to the offer endpoint. Range-duration services post only local `range_starts_at` / `range_ends_at` wall times in the service timezone. `SchedulingLocalDateTimeResolver` continues to reject nonexistent spring-forward values and ambiguous repeated-hour values rather than normalizing or guessing. The server derives fixed end times, validates range minimum/maximum duration, checks range boundary availability, resolves the deterministic eligible host, and never accepts browser-authored host, end time, capacity, source-window, or offer provenance.

`IssuePublicBookingSlotOfferAction` reruns exact current availability and then issues one opaque `BookableSlotOffer`. For customer-site work, the offer snapshots the canonical location used by travel-aware availability. The offer does **not** create a `BookingHold`, consume host/service capacity, create resource occupancy, or extend its expiration merely because the visitor reviews it. This is the intentional pre-verification selection state required by the progressive flow.

The offer review page is capability-addressed by opaque `offer_id` and exposes only visitor-safe service/time/location presentation plus authoritative expiration. It does not expose host identity, capacity, availability provenance, normalized location payloads, resource state, challenge IDs, or proof tokens. When an eligible transactional verification channel exists, the page requires one reachable email or SMS destination to be verified before the hold transition appears. Challenge/proof authority remains in server-owned cache/session state and is bound to the offer plus current booking session. When no eligible verification channel exists, Scheduling remains independently usable and shows the direct hold path.

Creating the real hold is a separate POST to the offer capability. `CreatePublicBookingHoldRequest` accepts only a UUID `idempotency_key` and explicitly prohibits browser-authored verification state. `CreatePublicBookingHoldAction` verifies that the offer is an ordinary public-booking offer. If at least one eligible destination-verification channel is currently available, it also requires a valid server-owned proof bound to that offer and booking session and consumes the proof exactly once before delegating to `CreateBookingHoldAction`. `CreateBookingHoldAction` then re-locks and revalidates the service, host assignment, offered location commitment, travel fit, capacity, resources, and exact interval before consuming the offer and creating occupancy. A race that made the selected interval unavailable after offer issuance or after destination verification therefore fails without creating a hold.

Public hold idempotency is now scoped to the opaque offer plus replay key. Repeating the same offer and key returns the original hold. Reusing a key for another offer is rejected. An expired or already-consumed offer cannot create another hold.

The public hold page is capability-addressed by the opaque `hold_id`, marked `noindex`, and renders only service name, local date/time, timezone, effective status, absolute expiration, authoritative remaining seconds, and the held customer-site formatted address when one exists. It does not expose host, capacity, offer, occupancy, or availability-rule details.

### Public booking completion

The active hold page submits only:

```text
name
email
phone
```

`CompletePublicBookingRequest` rejects caller-authored Contact, Appointment, service, host, timing, status, confirmation, capacity, offer, and source fields. `CompletePublicBookingAction` normalizes the attendee snapshot and passes a lazy booking-data resolver into `ConvertBookingHoldToAppointmentAction`.

The resolver runs only after the ordinary offer, service, host, hold, terminal-state, and expiration checks succeed under the conversion transaction. A converted replay returns the existing Appointment before the resolver runs, so a retry cannot create or resolve another Contact. Released, expired, missing, reschedule-scoped, or otherwise invalid holds cannot create Contacts.

`ResolveContactByEmailAction` belongs to Core. It lowercases and validates the email, returns an existing Contact without changing its name, phone, source, subsource, or metadata, and creates a new Contact only when no normalized email match exists. The contacts email unique constraint remains the final concurrency authority; duplicate-insert races reload the winning Contact. Submitted attendee values are stored on `appointment_attendees` as the booking-time snapshot even when an established Contact is reused.

For services not requiring confirmation, completion creates a `scheduled` Appointment and accepted primary attendee. For services requiring confirmation, completion creates a `pending` Appointment and invited primary attendee. The same opaque `GET /book/{holdId}` capability renders the resulting visitor confirmation without exposing Contact IDs, Appointment IDs, host identity, capacity, or attendee PII. Scheduling remains functional without Messaging.

### Reschedule transaction

A reschedule-scoped offer is the only authority for which Appointment may be ignored during replacement-slot calculation. The caller still submits only:

```text
offer_id
idempotency_key
```

The caller cannot nominate an Appointment to exclude. `CreateBookingHoldAction` resolves that identity from the locked offer, re-locks the source Appointment, verifies that it still belongs to the service, remains `pending`, `scheduled`, or `confirmed`, and has not already produced a replacement, then excludes exactly that Appointment from occupancy revalidation. Competing Appointments, active holds, host capacity, service capacity, assignment overrides, availability-window capacity, and all non-source buffers remain authoritative.

`RescheduleAppointmentAction` accepts an `AppointmentRescheduleData` containing the opaque hold ID, transport-neutral lifecycle context, and an explicit confirmation-preservation decision. It locks the authoritative offer, service, optional host, hold, source Appointment, and source attendees; enforces `bookable_services.reschedule_notice_minutes` unless force authorization is supplied; and completes the replacement in one transaction.

The replacement copies the source Appointment's Contact, polymorphic primary subject, source context, location reference, title, description, timezone, and attendee snapshots. Its location snapshot comes from the authoritative reschedule hold; customer-site reschedules reuse the source snapshot unless a later booking flow deliberately supplies a newly normalized address. This keeps the common Contact-only one-on-one path simple while preserving future vertical-owned subjects such as pets without adding a Scheduling dependency on Pet Services.

The replacement host and start/end interval come only from the active reschedule hold. The source Appointment becomes `canceled` with the reschedule reason, and its active attendee rows become canceled. No standalone `appointment.canceled` lifecycle or automation event is emitted for that internal replacement step.

Only one direct replacement may reference a source Appointment. The existing `rescheduled_from_id` lineage is enforced by a database unique constraint and by source-row locking. Retrying the same converted hold returns the existing replacement; another hold for the already-replaced source is rejected.

For services not requiring confirmation, the replacement becomes `scheduled` and its primary attendee becomes accepted at reschedule time. For services requiring confirmation, the default replacement becomes `pending` with an invited primary attendee and no response timestamp. A caller may explicitly preserve confirmation only when the source Appointment was already confirmed; the replacement then becomes `confirmed` and the primary attendee remains accepted.

### Expiration contract

`booking_holds.expires_at` is authoritative. A hold consumes capacity only while:

```text
status = active
expires_at > now()
```

Correctness never depends on cleanup timing. `ExpireBookingHoldsJob` runs every minute and marks due active rows as `expired` for housekeeping and reporting, but new hold attempts immediately ignore an elapsed hold even before that job runs.

The public hold review renders the absolute server-provided expiration and derives its countdown from that timestamp. Refreshing or reopening the page does not restart or extend the hold. An elapsed active row is presented as expired immediately even before the cleanup job changes its stored status.

### Hold release and conversion

`ReleaseBookingHoldAction` releases an active hold explicitly, treats repeated release requests idempotently, marks an elapsed active hold expired, and rejects release after conversion.

`ConvertBookingHoldToAppointmentAction` uses the hold itself as the conversion identity for ordinary bookings. It locks the offer, hold, and authoritative service/host records, validates conversion eligibility, then resolves either directly supplied or lazy `AppointmentBookingData`. It creates the Appointment, one primary attendee snapshot, and the initial lifecycle plus neutral automation event in one transaction, then marks the hold converted and links the Appointment. A retry returns the already-created Appointment before lazy booking data is resolved. Reschedule-scoped holds are rejected and must be completed through `RescheduleAppointmentAction`, preventing an accidental unrelated second Appointment.

The caller may provide a Core Contact and a separate polymorphic primary attendee. This preserves the common one-on-one path while supporting vertical-owned subjects such as pets without adding a Scheduling dependency on the vertical module. The existing one-to-many attendee relationship remains available for future additional participants.

Services with `requires_confirmation = false` create a `scheduled` Appointment, an accepted primary attendee with `responded_at` set to booking time, and a `scheduled` lifecycle event. Services requiring confirmation create a `pending` Appointment, an invited primary attendee with no response timestamp, and a `created` lifecycle event whose target status is `pending`.

Conversion copies the held start/end interval and immutable held location snapshot plus current service-owned host identity and operational timezone from authoritative Scheduling records. Caller-provided service, host, time, capacity, or location values are never authoritative conversion inputs.

### Non-hold appointment creation

`AppointmentCreationData` and `CreateAppointmentAction` provide the authoritative path for CRM, imports, provider adapters, FlowRoutes, and other trusted callers that need to create an Appointment without first creating a public booking hold.

The caller supplies:

```text
persisted BookableService
optional explicit SchedulingHost
starts_at
optional explicit ends_at for range-duration services
AppointmentBookingData, including an optional normalized customer-site location
idempotency_key
AppointmentLifecycleContext
```

The action reloads and locks the service, then requires an explicit active host assignment whenever the service has any assignment rows. A service with no assignment rows may be created unhosted. Host selection is never silently delegated to the public surface's deterministic first-slot behavior.

For fixed-duration services, the server derives the end time from the configured exact duration. Range-duration direct creation requires an explicit end time, validates whole-minute duration boundaries plus the service minimum/maximum range, and revalidates that exact interval through the executable availability engine. It locks the selected service, explicit host and assignment, relevant blocking Appointments, and active BookingHolds before creation. This preserves service capacity, assignment capacity, availability-window capacity, buffers, minimum notice, booking horizon, host capacity, and resource occupancy across the complete interval.

Direct creation derives phone, virtual, and fixed snapshots from locked service configuration. Customer-site creation requires the optional normalized location carried by `AppointmentBookingData`. It snapshots the resolved location and current timezone, creates one primary attendee snapshot, applies the same `requires_confirmation` policy as hold conversion, and records the initial lifecycle plus neutral automation event in the same transaction.

`appointments.idempotency_key` is nullable and unique. Repeating a matching key returns the original Appointment, including a soft-deleted historical row, without creating another attendee or event. Reusing the key for another service, host, start time, Contact, or polymorphic primary subject is rejected. Nullable keys preserve imported, provider-originated, and legacy records that do not participate in this direct-creation replay contract.

### CRM Scheduling workspace

`SchedulingReadService` provides the first read-side boundary for CRM Scheduling. It returns active services, active assigned hosts, bounded date availability, and upcoming operational Appointments without placing Scheduling query rules in the controller or Blade view.

The authenticated `/scheduling` workspace is guarded by `module:scheduling` and appears through the module-driven CRM navigation registry. It presents upcoming `pending`, `scheduled`, and `confirmed` Appointments, highlights the pending-confirmation count, and provides a quick creation flow that does not require an attendee to already exist as a Contact.

The operator explicitly chooses one attendee mode:

```text
Existing Contact
    search and select an existing Core Contact

New person
    enter name + email and optional phone
    resolve the normalized email through Core ResolveContactByEmailAction
    reuse an existing Contact when that email already exists
    otherwise create the Contact with source=crm and subsource=scheduling

Do not add to Contacts
    require an attendee name
    allow optional email, phone, and appointment context
    create the Appointment with a snapshot-only primary attendee and no Contact identity
```

Selecting **New person** does not grant Messaging consent or imply marketing permission. Contact creation/resolution and Appointment creation run inside one CRM transaction so a newly created Contact does not survive when the Appointment fails final availability or business-rule validation. Snapshot-only booking is a supported operator workflow; it intentionally lacks Contact-linked CRM history and communication until a later explicit linking workflow exists.

The creation flow then chooses the active service, explicit active assigned host when required, and any booking-specific customer-site address. Fixed-duration services choose a date and one exact server-issued start instant. Consecutive available starts are summarized into human-readable ranges such as `9:00 AM–11:30 AM, every 15 minutes`, but the browser still submits one exact authoritative `starts_at`. Range-duration services continue to submit exact local check-in/check-out values.

`StoreAppointmentRequest` validates the explicit attendee mode, requires a selected Contact only for existing-Contact mode, requires name + email for new-person Contact creation, and requires at least a name for snapshot-only booking. It also retains the closed service/host/time/address contracts. `SchedulingController` resolves attendee identity through Core when needed and supplies the resulting Contact or attendee snapshot to `AppointmentBookingData`; `CreateAppointmentAction` remains authoritative for final commitment and availability revalidation.

Snapshot-only idempotency compares the persisted primary attendee name/email/phone as part of replay identity when no Contact or polymorphic primary attendee exists. Reusing the same appointment idempotency key for a different snapshot attendee is rejected rather than silently returning another person's Appointment. A service with exactly one active eligible host may still be preselected for convenience, but that host identity remains explicit and is revalidated by `CreateAppointmentAction`.

The workspace links each operational row to an authenticated Appointment detail page. `SchedulingReadService::appointmentDetail()` composes the service, host, Contact, attendee snapshots, creator, reschedule lineage, and chronological lifecycle history so the controller and Blade view do not rebuild Scheduling queries.

The detail surface exposes only semantic lifecycle actions that are meaningful for the currently displayed state. Confirmation, cancellation, completion, and no-show submissions invoke the existing lock-backed actions with the authenticated CRM user as actor and `crm` as source. UI visibility is presentation only; stale or forged requests are still rejected by `TransitionAppointmentStatusAction`.

Cancellation requires a human-entered reason. When the service cancellation-notice deadline has passed, the form requires an explicit override checkbox; the controller converts that authorization to `AppointmentLifecycleContext::force` and records the CRM surface and action in lifecycle provenance. Completion and no-show controls appear only after the appointment starts.

The detail surface also links eligible `pending`, `scheduled`, and `confirmed` Appointments into the authenticated CRM reschedule workspace. The workspace keeps the original service authoritative, requires an explicit active assigned host when the service has assignments, and calculates date availability with the source Appointment excluded from occupancy. Every other Appointment, active hold, buffer, capacity limit, blackout, minimum-notice rule, and booking horizon remains enforced.

`RescheduleAppointmentToSlotAction` is the trusted exact-slot coordinator for CRM and future internal surfaces. It accepts the source Appointment, selected start instant, optional explicit host, UUID replay key, lifecycle context, and explicit confirmation-preservation choice. It resolves matching replay keys before issuing another offer, then coordinates reschedule-aware availability, `IssueBookableSlotOfferAction`, `CreateBookingHoldAction`, and `RescheduleAppointmentAction` inside one outer transaction.

The browser submits only:

```text
scheduling_host_id
starts_at
idempotency_key
reschedule_reason
preserve_confirmation
override_reschedule_notice
```

Service identity, duration, end time, offer and hold identities, replacement status, attendee copying, original cancellation, lifecycle events, automation events, and lineage remain server-owned. Fixed-duration rescheduling uses the service's current exact duration. Range-duration rescheduling preserves the source Appointment's full start/end duration and validates that interval against the current range policy before searching or committing a replacement. The reason is required. Confirmation preservation is offered only when the original is confirmed and the service requires confirmation. Rescheduling after the configured notice deadline requires an explicit override, which is preserved in lifecycle and automation provenance.

A successful reschedule redirects to the replacement Appointment. Matching replay submissions return that same replacement without creating another offer, hold, attendee set, lifecycle event, or automation event. Reusing the replay key for another source Appointment, host, or start time is rejected.

The CRM rescheduling workspace now supplies a small suggested-open-times set in addition to the operator's selected-date availability. Suggestions preserve the source Appointment's authoritative service, historical location snapshot, selected eligible host, resource requirements, availability rules, notice/horizon limits, and reschedule exclusion. They are ordered first by resolved adjacent travel burden and then by temporal proximity to the original Appointment, so a richer `TravelTimeResolver` can naturally improve geographic/travel convenience without changing the reschedule workflow. The final submitted time still runs through `RescheduleAppointmentToSlotAction`, offer issuance, hold creation, and transaction-time revalidation; suggestions grant no booking authority.

Optional Location enrichment may improve the app-level travel resolver when Location is separately enabled, but baseline suggestions and travel-fit decisions remain Scheduling-owned and work without Location.

The Scheduling module also contributes a Contact-page panel through Core's module-filtered `ContactPanelProvider` seam. `SchedulingContactPanelProvider` uses bounded Scheduling-owned queries keyed only by `appointments.contact_id`; Core does not gain Scheduling relationships or query knowledge.

The panel shows the next operational Appointment, other upcoming `pending`, `scheduled`, or `confirmed` Appointments, pending-confirmation attention, and recent `completed`, `canceled`, or `no_show` outcomes. Service, host, primary attendee ordering, and reschedule lineage are loaded by `SchedulingReadService`, and every row links to the authoritative CRM Appointment detail surface rather than duplicating lifecycle controls.

The panel's Schedule Appointment action links to `/scheduling?contact_id={contact}`. `SchedulingController` validates that Contact identity, preserves it through service, host, and date selection, initializes the existing Core Contact autocomplete state, and keeps the Contact selected after successful creation. The browser still submits the ordinary `contact_id`; the query parameter grants no additional authority.

### CRM Scheduling configuration workspace

The authenticated `/scheduling/configuration` workspace provides the first CRM-owned setup surface for durable Scheduling identity and service policy. It manages manual `scheduling_hosts`, manual `bookable_services`, and `bookable_service_hosts` assignments without exposing raw metadata, provider identity, polymorphic host ownership, or synchronization fields.

`SchedulingConfigurationWriter` is the sole mutation boundary for this workspace. It creates manual records with server-owned `source`, null provider identity, null hostable identity, and null metadata. Existing keys are immutable because they are durable references used by public booking, configuration, and future integrations.

A host is editable only when it is a non-deleted manual record with no polymorphic `hostable` identity. A service is editable only when it is a non-deleted manual record with no provider, external ID, or external URL. Provider-, system-, and model-owned rows remain visible with usage counts but are read-only. Controller validation also rejects caller-authored `source`, provider fields, hostable fields, metadata, timestamps, and other unknown configuration input.

Host and service updates carry the record's current `updated_at` value. The writer reloads and locks the row, verifies that version, and rejects stale forms before applying changes. This is optimistic concurrency for ordinary CRM edits; it does not replace the lock-backed booking and lifecycle transactions.

Host status and service status use explicit `active`, `inactive`, and `archived` values. The workspace does not hard-delete configuration. Making a service inactive or archived also makes it non-public so a stale public visibility flag cannot keep an unavailable service discoverable.

Service policy editing includes:

```text
duration mode: fixed | range
fixed exact duration or range default/minimum/maximum duration
slot interval
buffers
minimum booking notice
booking horizon
cancellation and reschedule notice
service timezone
canonical location mode: phone | virtual | fixed | customer_site
optional location label and instructions
virtual URL only for virtual services
normalized fixed address only for fixed services
capacity
confirmation requirement
public visibility
sort order
```

The duration editor is closed around the first-class service contract. Fixed mode stores one exact `duration_minutes` value and clears range bounds. Range mode requires default, minimum, and maximum durations, enforces `minimum <= default <= maximum`, and caps the maximum at the Scheduling 366-day search limit. Switching modes therefore cannot leave stale range bounds attached to a fixed service.

The browser never submits raw `location_details` JSON or provider/geocoding facts. `SchedulingConfigurationController` rejects unknown location modes and type-incompatible fields. `SchedulingConfigurationWriter` rebuilds the closed Scheduling-owned location details for each edit: phone/customer-site may retain only label/instructions, virtual may additionally retain URL, and fixed stores the canonical normalized address plus optional label/instructions. Changing location modes therefore cannot preserve stale hidden fields from the prior mode.

Assignment synchronization is transactional. Existing assignment rows omitted from a submission or explicitly disabled are retained with `is_active = false`; they are not deleted, and their presence continues to prevent accidental fallback to unhosted booking. A new inactive host does not create an unnecessary assignment row. Active assignments require an active, non-deleted host and may carry a positive capacity override plus sort order. The service is touched after synchronization so concurrent assignment forms become stale.

`SchedulingReadService` supplies ordered configuration collections, source ownership, editability, assignment state, availability-window counts, and Appointment usage counts. Controllers and Blade views do not rebuild those Scheduling queries.

The host/service workspace links to a separate availability-rule workspace. Calendar visualization, provider synchronization, and reminder management remain deferred.

### CRM availability workspace

The authenticated `/scheduling/configuration/availability` workspace uses a service-first business authoring layer over the existing generalized `scheduling_availability_windows` runtime. A normal operator chooses the service whose hours are being managed and works with three concepts:

```text
Regular hours
    recurring weekly booking hours for the service

Special hours
    one date whose booking hours intentionally replace the normal weekly schedule

Time off
    one whole date or one part of a date that must be unavailable
```

Regular hours support zero or more ranges per weekday, including split days such as `09:00-12:00` and `13:00-17:00`. The normal flow derives the service timezone and server-owned Scheduling rule fields instead of asking the operator to choose rule scope, rule shape, source ownership, sort identity, or raw precedence mechanics.

`SchedulingAvailabilityConfigurationWriter` remains the sole CRM mutation boundary. Its business authoring methods synchronize only the simple manual service-wide rows represented by the normal UI. Existing row identities are reused when possible, unchanged rows are not rewritten, and only surplus rows are soft-archived. This prevents ordinary edits from creating unbounded replacement-row history while preserving the existing non-destructive configuration model.

Special hours are true date-specific replacements rather than additive positive windows. The writer represents the requested local hours with complementary service-wide absolute unavailable windows for the rest of that local date. The existing availability resolver therefore continues to produce the intended result without adding a second precedence engine to the CRM layer. Saving new special hours for the same date reuses the existing simple absolute rows where possible and archives only surplus rows.

Whole-day time off is represented as one local-date unavailable interval. Partial time off adds a bounded unavailable interval for that date. Removing a one-off date change soft-archives the simple manual absolute rows for that date so the recurring weekly schedule becomes authoritative again.

Local authoring remains strict around wall-clock correctness. Weekly hours remain recurring wall-clock values in the service timezone. Absolute special-hours/time-off input is resolved through `SchedulingLocalDateTimeResolver` to authoritative UTC instants; nonexistent spring-forward values and ambiguous repeated-hour values continue to fail rather than being guessed or normalized.

The workspace lists upcoming one-off changes in business terms and provides a **Test availability** surface. The test uses `SchedulingReadService::availabilityForDate()` and the existing `FindBookableAvailabilityAction`; it does not recreate availability logic in Blade or in the controller. Results therefore reflect the actual runtime combination of hours, one-off changes, staff assignments, capacity, resource occupancy, existing Appointments/holds, notice/horizon policy, and other active constraints. Fixed-duration results are grouped only for presentation: consecutive start instants with the same service/host/timezone/capacity context become one visible start-time range, and gaps or capacity changes split the ranges. The underlying `BookableSlot` values remain intact for exact booking.

The generalized raw rule editor remains available under an Advanced disclosure for capabilities that the simple service schedule does not replace, including staff-specific rules, service-host-specific rules, explicit capacity overrides, provider/system-owned diagnosis, and unusual scheduling policies. Provider- and system-owned rows remain visible but read-only. Manual advanced rows retain optimistic `updated_at` mutation guards, soft archive/restore behavior, durable service-host assignment validation, and strict local absolute-time handling.

The underlying executable availability contract is unchanged:

```text
positive rules union inside one scope
present positive scopes intersect
all applicable unavailable windows subtract
occupancy/capacity/resource/travel policy then constrains resolved slots
```

Those mechanics remain an internal/runtime contract. They are not normal client-facing authoring vocabulary.


## Appointment lifecycle state machine

The implemented lifecycle layer consists of:

```text
AppointmentLifecycleContext
AppointmentRescheduleData
TransitionAppointmentStatusAction
ConfirmAppointmentAction
CancelAppointmentAction
CompleteAppointmentAction
MarkAppointmentNoShowAction
RescheduleAppointmentAction
```

`AppointmentLifecycleContext` carries transport-neutral actor, source, reason, occurrence time, optional confirming attendee, force authorization, and compact provenance. Controllers, public links, provider callbacks, CRM actions, or optional integrations may call the semantic actions without embedding transport behavior in Scheduling.

Supported transitions are:

```text
pending -> confirmed | canceled | completed | no_show
scheduled -> confirmed | canceled | completed | no_show
confirmed -> canceled | completed | no_show
completed | canceled | no_show -> terminal
```

Repeating the same terminal or confirmation action is idempotent. A conflicting transition from a terminal outcome is rejected. Completion and no-show cannot be recorded before the Appointment starts. Cancellation enforces `bookable_services.cancellation_notice_minutes` unless the caller supplies explicit force authorization.

Confirmation updates the identified AppointmentAttendee, or the primary attendee when no explicit attendee is supplied, from `invited` or `tentative` to `accepted` and records `responded_at`. An already accepted attendee preserves the original response timestamp. A supplied attendee must belong to the Appointment.

This confirmation action is deliberately transport-neutral. When InboundMessaging is enabled and client configuration opts into appointment confirmations by text, a later integration may correlate configured replies such as `Y`, `YES`, or `CONFIRM` to the exact Appointment and attendee, then invoke `ConfirmAppointmentAction` with `source = sms_reply`. Scheduling does not parse inbound text and does not depend on InboundMessaging. Clients without InboundMessaging retain the full appointment lifecycle.

Canceling an Appointment marks its `invited`, `accepted`, and `tentative` attendee rows canceled. Completion and no-show do not overwrite attendee-level outcomes, preserving truthful group-appointment behavior even though most current appointments are one-on-one.

Every initial creation and successful lifecycle transition records append-only `appointment_lifecycle_events` history and a durable neutral automation outbox event in the same transaction. Current event vocabulary is:

```text
appointment.created
appointment.scheduled
appointment.confirmed
appointment.canceled
appointment.completed
appointment.no_show
appointment.rescheduled
```

Automation payloads contain structural identities, statuses, times, and provenance. They do not duplicate attendee names, email addresses, or phone numbers. `appointment.rescheduled` uses the replacement Appointment as its canonical subject and carries both original and replacement identities, hosts, statuses, and times.

## Messaging, tasks, and automation

Scheduling owns appointment communication timing and intent. Messaging owns templates, consent, suppression, channel eligibility, delivery, retries, and evidence.

Push notification support belongs to Messaging as another delivery channel. Scheduling must not hard-code email and SMS as the only possible channels.

### Public destination verification

Public booking requires control of one reachable transactional destination before creating a capacity-consuming hold whenever the neutral destination-verification transport currently exposes at least one eligible channel.

"Messaging can deliver" means the channel is runtime supported, provider enabled, valid for the submitted destination, and eligible for the public-booking verification purpose. Merely enabling the Messaging module is not enough. When both email and SMS are eligible, the visitor may choose one; verification of both channels is not required. When no eligible channel is deliverable, Scheduling remains independently usable and continues to rely on its ordinary throttles and server-authoritative booking checks.

Verification is transactional security activity. It must not grant marketing consent, revoke consent, or imply permission for later marketing communication.

The implemented verification contract includes:

```text
short-lived single-use challenge
hashed code or secret storage; never raw challenge storage
resend cooldown
maximum attempts
per-IP, per-destination, and per-challenge throttling
expiration and invalidation after success
server-owned verified state
```

Sending a challenge does not create an Appointment, Contact, or capacity-consuming `BookingHold`. The public flow first issues a short-lived non-blocking slot offer, verifies one destination when required, consumes the offer/session-bound proof, then revalidates all trusted booking inputs before creating the hold. A browser-authored `verified` field, challenge ID, or proof token is never accepted as evidence.

Scheduling may create follow-up work through Tasks public actions. It must not write Tasks internals directly.

Scheduling should record its own state first and then emit neutral automation events through:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Implemented neutral event vocabulary:

```text
appointment.created
appointment.scheduled
appointment.confirmed
appointment.canceled
appointment.completed
appointment.no_show
appointment.rescheduled
```

FlowRoutes listens through the generic automation-event seam. Scheduling does not depend on FlowRoutes.

## Provider boundary

External calendar and meeting providers are adapters behind Scheduling-owned contracts.

Providers may:

```text
supply free/busy intervals
create or update remote calendar events
create meeting links
return synchronization results and remote identity
```

Providers may not:

```text
own Appointment lifecycle
be treated as the booking source of truth
write Scheduling tables directly outside Scheduling services
make local appointment validity depend on immediate provider success
```

Provider persistence should separately represent connections, remote event identity, synchronization operations, attempts, status, errors, retries, and reconciliation.

## Public seams

Implemented:

```text
PublicBookingController catalog, availability, reservation, attendee completion, and hold-confirmation surface
ResolveContactByEmailAction
FindBookableAvailabilityAction
TravelTimeResolver
SchedulingTravelTimeResolver
TravelFeasibilityResolver
IssueBookableSlotOfferAction
CreateBookingHoldAction
CreatePublicBookingHoldAction
CompletePublicBookingAction
ReleaseBookingHoldAction
ConvertBookingHoldToAppointmentAction
AppointmentCreationData
CreateAppointmentAction
RescheduleAppointmentToSlotAction
RescheduleAppointmentAction
ConfirmAppointmentAction
CancelAppointmentAction
CompleteAppointmentAction
MarkAppointmentNoShowAction
TransitionAppointmentStatusAction
```

Implemented CRM workspace seams:

```text
SchedulingReadService
SchedulingConfigurationWriter
SchedulingAvailabilityConfigurationWriter
SchedulingResourceConfigurationWriter
SchedulingController
SchedulingConfigurationController
SchedulingAvailabilityController
SchedulingResourceController
AppointmentController
StoreAppointmentRequest
CancelAppointmentRequest
RescheduleAppointmentRequest
CRM Scheduling creation, detail, lifecycle, reschedule, Contact-panel, host/service configuration, availability-rule, and resource-configuration workspaces
```

Planned:

```text
AppointmentReminderScheduler
```

Public actions should exist before another module or surface directly creates or mutates Scheduling records.

## FlowRoutes integration

Scheduling integrates with FlowRoutes through the ownership-preserving automation extension pattern.

Scheduling owns its business records, lifecycle, public business actions, and neutral automation events. FlowRoutes owns route structure, progression, correlation, resume behavior, and created-artifact references in FlowRoutes-owned state.

Do not add `flow_route_*` foreign keys to Scheduling artifacts merely for provenance symmetry.

## Deferred work

Deferred after the resource configuration workspace:

```text
Phase 4B.2A — COMPLETE: define the Scheduling-owned location/snapshot boundary and retain Location as a separate optional silent capability
Phase 4B.2B1 — COMPLETE: add Scheduling-owned deterministic address normalization and canonical phone/virtual/fixed/customer-site snapshots, persist authoritative BookingHold location snapshots, copy hold snapshots into Appointment conversion/rescheduling, and require booking-specific customer-site snapshots without creating Location rows
Phase 4B.2B2 — COMPLETE: add closed CRM service-location authoring and customer-site public/CRM raw-address collection with server-owned normalization before commitment
Phase 4B.2C — COMPLETE: add Scheduling-owned provider-neutral travel-time resolution, conservative fallback, adjacent Appointment/active-hold checks, transaction-time revalidation, and admin reschedule slot suggestions that preserve the source booking criteria; optional app-level geographic enrichment may improve travel ranking without making Location a dependency
Phase 4B.2D1 — COMPLETE: add first-class fixed/range service-duration policy, module schema v2, explicit range interval validation, check-in/check-out boundary availability, full-stay hold/capacity/resource occupancy, direct range creation, and range-preserving reschedule runtime
Phase 4B.2D2 — COMPLETE: expose closed CRM range-service authoring plus public/internal check-in/check-out input, strict service-timezone wall-time resolution, and range-specific reschedule presentation; fixed-location PetServices stays do not require Location, and PetServices continues to own pet/compliance/feeding/medication meaning
Phase 4B.3 — COMPLETE: restructure public booking around appointment-type-first progressive prerequisites, require canonical customer-site location before travel-aware fixed-slot availability, issue a short-lived non-blocking location-bound offer before the real hold, and preserve fixed/range server authority without exposing host/capacity/provenance state
Phase 4B.4 — COMPLETE: add optional Messaging-backed email/SMS destination verification after non-blocking offer selection and before the capacity hold, with server-owned challenge/proof state, single-use offer/session-bound proof enforcement, no marketing-consent side effects, and a graceful no-Messaging path
Scheduling Project State transfer support — COMPLETE: optional schema-activated durable transfer with transient offer/hold/verification exclusion and reference-safe restore semantics
calendar views
provider connection and synchronization persistence
external free/busy adapters
meeting-link generation
appointment reminder scheduling
paid booking integration
round-robin or weighted host routing
Reporting dashboards
vertical-specific Scheduling interpretation
client-specific webinar booking entry
```