# Scheduling Module

Scheduling is a current universal module.

Scheduling owns reusable appointment and booking capability that can be used by multiple verticals without pushing appointment state into Core or vertical-specific tables.

## Client-facing expectation

Scheduling should follow the Engage Core product barometer:

```text
A client-facing scheduling task should be completable in 10-15 minutes total, and common appointment scheduling should usually take far less.
```

Scheduling an appointment for a known person on a known day should feel closer to a 30-second task than a configuration workflow.

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
Configure availability patterns.
Wire reminders.
Connect external calendar providers.
Attach forms/tasks/portal behavior.
```

Scheduling should not become a generic calendar-builder product for clients to maintain.

## Responsibility

Scheduling should answer:

```text
What can be booked, when can it be booked, who is attending, where does it happen when that matters, and what is the lifecycle state of the appointment?
```

Scheduling should stay vertical-neutral.

It may support dog training sessions, consultations, coaching calls, music lessons, studio bookings, internal meetings, customer-facing appointments, or other bookable interactions without owning the vertical meaning of those interactions.

## FOSS feature-shape assumptions

Before proposing schema, Scheduling was evaluated against common patterns in mature open-source and open-source-adjacent scheduling systems.

Those systems commonly support:

```text
- bookable services or event types
- appointment or reservation records
- customers / attendees
- staff / providers / hosts
- availability windows
- timezone handling
- reschedule and cancellation lifecycle
- calendar views
- external calendar sync
- public booking links or booking pages
- reminders and notifications
- automation hooks / webhooks / workflows
- intake questions or custom fields
- resource booking
- team scheduling or routing
- optional payment collection
- reporting / exports
```

Engage Core should use those products as feature-shape references, not as implementation sources.

The durable conclusion is that Scheduling should have a roomy, generic foundation for services, availability, appointments, and attendees, while consuming other Engage Core modules for delivery, manual work, portal access, forms, commerce, location, and automation reactions.

## Owns

Scheduling owns:

```text
bookable_services
scheduling_availability_windows
appointments
appointment_attendees
```

Scheduling should also own, when implemented:

```text
appointment status/lifecycle behavior
availability/rule evaluation
reschedule/cancel rules
appointment-related domain events
generic booking-page orchestration, if generic enough
appointment reminder orchestration requests
appointment-related task orchestration requests
optional saved-location references on appointments
```

Scheduling should not own message delivery, task lifecycle, portal accounts, form definitions, commerce/order records, geocoding, or vertical-specific meaning.

## Does not own

Scheduling does not own:

```text
notification delivery
internal notification preferences
task assignment/digest lifecycle
customer portal identity/auth
form definitions/submissions
payments/orders/products
external calendar adapter internals
location normalization/geocoding
vertical-specific appointment outcomes
pet-specific training goals
music-specific lesson curriculum
mortgage-specific consultation outcomes
```

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
Messaging -> appointment reminders/customer notifications
InternalNotifications -> team-facing scheduling alerts
Tasks -> manual follow-up work generated from appointment outcomes
Portal -> customer self-booking and customer-facing schedule views
Forms -> intake questions/submissions attached to appointment flows
Commerce -> paid booking/order/payment records
Location -> optional saved appointment places, service-area checks, venue reuse, or radius eligibility
Integrations -> external calendar/provider adapters behind Scheduling-owned contracts
```

## Consumed by

Scheduling may be consumed by:

```text
PetServices
Music
Mortgage
FlowRoutes
Campaigns
Broadcasts
Reporting
Portal
Forms
Documents
Commerce
Location
```

Consumers should use public Scheduling actions/services/contracts/events/read services rather than directly mutating Scheduling internals.

## Public seams to add later

The first foundation slice does not need full actions yet.

Likely future public seams:

```text
CreateAppointmentAction
RescheduleAppointmentAction
CancelAppointmentAction
CompleteAppointmentAction
MarkAppointmentNoShowAction
FindBookableAvailabilityAction
SchedulingReadService
AppointmentReminderScheduler
AppointmentTaskOrchestrator
AppointmentAutomationEventEmitter
```

Public actions should exist before other modules directly create or mutate Scheduling records.

## Automation events

Scheduling should use the existing app-level automation event seam when appointment outcomes become automation-worthy.

Current seam:

```text
App\Support\AutomationEvents\Data\AutomationEventData
App\Support\AutomationEvents\Events\AutomationEventRecorded
```

Likely future Scheduling automation events:

```text
appointment.created
appointment.scheduled
appointment.confirmed
appointment.rescheduled
appointment.canceled
appointment.completed
appointment.no_show
```

Scheduling should emit automation events after it records its own domain state.

FlowRoutes should listen to `AutomationEventRecorded`, not Scheduling-specific events.

Good:

```text
Scheduling records appointment.completed
Scheduling emits AutomationEventRecorded(appointment.completed)
FlowRoutes reacts through the generic automation event seam
```

Bad:

```text
Scheduling imports FlowRoutes
FlowRoutes adds a Scheduling-specific listener
Producer module calls FlowRouteExternalEvent directly
```

## Messaging, tasks, and notifications

Scheduling products commonly include reminders and notifications.

In Engage Core, Scheduling should orchestrate appointment reminder intent, but Messaging and InternalNotifications should deliver messages or team alerts.

When Scheduling schedules a customer-facing appointment message through Messaging, the scheduled message should use Messaging's existing recipient/context split:

```text
recipient_type / recipient_id
    Who receives the scheduled message.

context_type / context_id
    What domain record the scheduled message is about.
```

Example:

```text
Appointment reminder
    recipient = Contact
    context = Appointment
```

Scheduling should not ask Messaging for new `subject_type` / `subject_id` columns. `scheduled_messages.context_type` / `scheduled_messages.context_id` is already the canonical scheduled-message "about this record" morph.

Good:

```text
Scheduling -> Messaging public action/service for customer reminder scheduling
ScheduledMessage recipient = Contact
ScheduledMessage context = Appointment
Scheduling -> InternalNotifications public action/service for team alerts
```

Bad:

```text
Scheduling owns message consent
Scheduling owns scheduled_messages
Scheduling adds duplicate subject_type / subject_id fields to Messaging
Scheduling owns team notification preferences
```

Scheduling products commonly create follow-up work.

In Engage Core, Scheduling may create appointment-related Tasks through public task-facing actions when Tasks is enabled.

Good:

```text
Scheduling -> CreateTaskAction
```

Bad:

```text
Scheduling writes directly to tasks table internals without a public task action/service
```

## Forms, portal, commerce, and location

Scheduling products commonly include intake questions.

In Engage Core, Forms should own form definitions, versions, submissions, values, and review state. Scheduling may reference or require a form but should not own configurable form behavior.

Scheduling products commonly include customer self-booking.

In Engage Core, Portal should own customer account access and customer-facing shells. Scheduling may contribute booking screens through Portal extension points later.

Scheduling products commonly include paid bookings.

In Engage Core, Commerce should own products, orders, order items, and normalized purchase/payment records. Scheduling may reference payment/commerce state later through public Commerce seams.

Scheduling products commonly include calendar sync and locations.

External calendar adapters belong under `app/Integrations` behind Scheduling-owned contracts/managers. Reusable service-area/radius/location behavior belongs to Location.

Appointments may optionally reference a saved `Location` record for reusable offices, venues, service addresses, or other normalized places. That relationship is optional. Scheduling still works with `location_type` and `location_details` when Location is not enabled or when the appointment uses a one-off, virtual, phone, or freeform location.

This is a schema relationship, not a feature-visibility dependency. Scheduling should not require the Location module to be explicitly enabled for normal appointment scheduling.

## Schema foundation

The first Scheduling foundation should add these tables:

```text
bookable_services
scheduling_availability_windows
appointments
appointment_attendees
```

These tables are intentionally roomy but generic.

They include generic fields such as:

```text
status
source
provider
external_id
external_url
starts_at / ends_at where appropriate
confirmed_at / completed_at / no_show_at / canceled_at where appropriate
meta json
timestamps
soft deletes
```

They avoid vertical-specific columns and UI-specific assumptions.

## Table notes

### bookable_services

Represents something that can be scheduled or booked.

Examples:

```text
Consultation
Dog training session
Music lesson
Studio booking
Coaching call
Internal appointment type
```

Important fields:

```text
key
name
description
status
duration_minutes
buffer_before_minutes
buffer_after_minutes
location_type
location_details
capacity
requires_confirmation
is_public
sort_order
source
provider
external_id
external_url
meta
```

### scheduling_availability_windows

Represents reusable availability or unavailability windows.

It may apply to a bookable service, a provider/owner via morphs, or both.

Important fields:

```text
bookable_service_id
owner_type / owner_id
timezone
weekday
starts_at / ends_at
start_time / end_time
capacity
rrule
is_available
source
provider
external_id
meta
```

### appointments

Represents a scheduled booking/appointment record.

Important fields:

```text
bookable_service_id
contact_id
location_id nullable
primary_attendee_type / primary_attendee_id
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
provider
external_id
external_url
created_by_type / created_by_id
meta
```

`location_id` is optional and points to a saved Location-owned place when available. `location_type` and `location_details` remain the freeform/fallback path for one-off, virtual, phone, customer-address, provider-supplied, or not-yet-normalized locations.

Cancellation and rescheduling stay on `appointments` for the first foundation slice. A separate audit table can be added later only if lifecycle audit requirements justify it.

### appointment_attendees

Represents people or subjects attached to an appointment.

Important fields:

```text
appointment_id
attendee_type / attendee_id
contact_id
name
email
phone
role
status
responded_at
joined_at
canceled_at
meta
```

`contact_id` is optional convenience/context. It does not make Scheduling own contact identity.

## Deferred work

Deferred until needed:

```text
admin Scheduling UI
customer booking UI
Portal extension points
availability engine
calendar views
external calendar sync
appointment reminder scheduler
appointment task orchestration
appointment automation event emitter
paid booking integration
resource booking
round-robin/team routing
Scheduling presets
Reporting dashboards
vertical-specific Scheduling interpretation
```

## Open questions

```text
Should appointment reminder definitions live in Messaging config under transactional:scheduling?
Should Scheduling use a generic AppointmentReminderScheduler or defer until real reminders are needed?
Should availability support resources as a first-class concept, or is owner/service enough for now?
Should paid booking state be linked directly to Commerce orders or through a future booking-payment pivot?
Should appointment attendees later support PortalUser directly once Portal exists?
```
