# Scheduling Module

Scheduling is a current universal module.

Scheduling owns reusable appointment and booking capability that can be used by multiple verticals without pushing appointment state into Core or vertical-specific tables.

## Product expectation

Scheduling should follow the Engage Core product barometer:

```text
A client-facing scheduling task should be completable in 10-15 minutes total, and common appointment scheduling should usually take far less.
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
Assign hosts.
Configure availability and blackout rules.
Connect external calendar providers.
Wire reminders and follow-up behavior.
```

Scheduling should not become a generic calendar-builder product for clients to maintain.

## Universal public booking surface

Scheduling should provide every client with an optional generic public booking surface where visitors can select a public service and book directly.

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

All future public booking, cancellation, and reschedule URLs should resolve from that configured base URL.

The universal booking surface is separate from CRM, Portal, and Webinars. A webinar-triggered booking journey may add source context, eligibility, and tailored copy through a thin client-specific integration layer, but it must consume generic Scheduling contracts and must not shape Scheduling around Webinars.

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
appointment-related domain and automation event intent when implemented
```

Scheduling does not own message delivery, consent, task lifecycle, portal accounts, form definitions, commerce records, geocoding, or provider adapter internals.

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
Location -> optional reusable saved places and service-area checks
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
duration_minutes
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

The optional `locationReference` morph allows a saved Location-owned place without creating a Scheduling-to-Location module dependency. Freeform `location_type` and `location_details` remain valid for virtual, phone, one-off, provider-generated, or not-yet-normalized locations.

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

`AvailabilitySearch` normalizes the requested UTC range, display timezone, optional host filter, evaluation time, service minimum notice, and booking horizon. Requests are bounded to prevent accidental unbounded rule expansion.

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

A candidate must be continuously covered for its full `duration_minutes`. When it crosses adjacent interval segments with different capacity limits, the lowest capacity across the covered segments applies.

### Effective capacity

Effective slot capacity is the lowest applicable configured limit from:

```text
service capacity
host capacity
service-host assignment capacity_override
availability-window capacity
```

Remaining capacity is calculated independently for each limiting dimension across blocking Appointments and effectively active BookingHolds:

```text
service/service-host occupancy consumes service, assignment, and window capacity
all appointments and active holds on a host consume host capacity
converted holds stop consuming capacity as holds because their Appointment replaces that occupancy atomically
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

The candidate appointment's buffers and each existing appointment service's buffers are applied before testing overlap.

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
CreateBookingHoldAction
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
starts_at / ends_at
display_timezone
capacity / remaining_capacity
source_scopes
source_window_ids
issued_at
expires_at
consumed_at
```

The caller receives only the opaque `offer_id`. Public or CRM booking actions must not accept caller-authored service, host, start, end, capacity, timezone, or rule-provenance values as authoritative booking input.

`IssueBookableSlotOfferAction` revalidates the supplied server-side `BookableSlot` before persisting the offer. An offer may be consumed only once and cannot create a hold after `expires_at`.

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

`CreateBookingHoldAction` accepts only:

```text
offer_id
idempotency_key
```

It locks the offer, service, optional host, optional assignment, relevant appointments, and overlapping active holds in a deterministic transaction. It reruns exact-slot availability, applies current buffers and capacity, rejects stale or consumed offers, and prevents separate offers from over-reserving one slot.

The same idempotency key returns the original hold for the same offer and is rejected when reused for another offer.

### Expiration contract

`booking_holds.expires_at` is authoritative. A hold consumes capacity only while:

```text
status = active
expires_at > now()
```

Correctness never depends on cleanup timing. `ExpireBookingHoldsJob` runs every minute and marks due active rows as `expired` for housekeeping and reporting, but new hold attempts immediately ignore an elapsed hold even before that job runs.

A future browser countdown should render the absolute server-provided expiration. Refreshing or reopening the page must not restart or extend the hold.

### Hold release and conversion

`ReleaseBookingHoldAction` releases an active hold explicitly, treats repeated release requests idempotently, marks an elapsed active hold expired, and rejects release after conversion.

`ConvertBookingHoldToAppointmentAction` uses the hold itself as the conversion identity. It locks the hold and authoritative service/host records, creates the Appointment, one accepted primary attendee snapshot, and the initial lifecycle event in one transaction, then marks the hold converted and links the Appointment. A retry returns the already-created Appointment.

The caller may provide a Core Contact and a separate polymorphic primary attendee. This preserves the common one-on-one path while supporting vertical-owned subjects such as pets without adding a Scheduling dependency on the vertical module. The existing one-to-many attendee relationship remains available for future additional participants.

Services with `requires_confirmation = false` create a `scheduled` Appointment and a `scheduled` lifecycle event. Services requiring confirmation create a `pending` Appointment and a `created` lifecycle event whose target status is `pending`.

Conversion copies the held start/end interval plus current service-owned host identity, location snapshot, and operational timezone from authoritative Scheduling records. Caller-provided service, host, time, capacity, or location values are never authoritative conversion inputs.

## Messaging, tasks, and automation

Scheduling owns appointment communication timing and intent. Messaging owns templates, consent, suppression, channel eligibility, delivery, retries, and evidence.

Push notification support belongs to Messaging as another delivery channel. Scheduling must not hard-code email and SMS as the only possible channels.

Scheduling may create follow-up work through Tasks public actions. It must not write Tasks internals directly.

Scheduling should record its own state first and then emit neutral automation events through:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Planned neutral event vocabulary:

```text
appointment.scheduled
appointment.rescheduled
appointment.canceled
appointment.completed
appointment.no_show
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
FindBookableAvailabilityAction
IssueBookableSlotOfferAction
CreateBookingHoldAction
ReleaseBookingHoldAction
ConvertBookingHoldToAppointmentAction
```

Planned:

```text
CreateAppointmentAction for non-hold CRM/manual creation
RescheduleAppointmentAction
CancelAppointmentAction
ConfirmAppointmentAction
CompleteAppointmentAction
MarkAppointmentNoShowAction
SchedulingReadService
AppointmentReminderScheduler
AppointmentAutomationEventEmitter
```

Public actions should exist before another module or surface directly creates or mutates Scheduling records.

## FlowRoutes integration

Scheduling integrates with FlowRoutes through the ownership-preserving automation extension pattern.

Scheduling owns its business records, lifecycle, public business actions, and neutral automation events. FlowRoutes owns route structure, progression, correlation, resume behavior, and created-artifact references in FlowRoutes-owned state.

Do not add `flow_route_*` foreign keys to Scheduling artifacts merely for provenance symmetry.

## Deferred work

Deferred after the booking transaction foundation:

```text
non-hold CRM/manual appointment creation
reschedule and cancellation actions
CRM Scheduling workspace
public service selection and booking pages
SCHEDULING_APP_URL routing and setup validation
calendar views
provider connection and synchronization persistence
external free/busy adapters
meeting-link generation
appointment reminder scheduling
appointment automation event emission
paid booking integration
resource booking
round-robin or weighted host routing
Reporting dashboards
vertical-specific Scheduling interpretation
client-specific webinar booking entry
```