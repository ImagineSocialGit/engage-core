# Scheduling Booking Subjects and Prerequisites

Scheduling owns appointment timing, availability, capacity, holds, and appointment lifecycle. It does not own the business meaning of every thing that may receive a service.

This foundation makes that boundary explicit.

## Booking subjects

Every `BookableService` has a provider-neutral `booking_subject_key`.

The backward-compatible built-in default is:

```text
generic
```

`generic` accepts the existing polymorphic primary-attendee behavior, including persisted non-Contact subjects and snapshot-only attendees. This preserves current Scheduling creation/import behavior while making subject policy explicit.

Scheduling also provides an explicit `contact` subject key for Appointment Types that should accept only Core Contacts. Future authoring can use the narrower key when that distinction is useful without rewriting existing services.

Other modules may contribute additional subject providers through `BookingSubjectProviderRegistry`. Scheduling depends only on the `BookingSubjectProvider` contract and never imports a vertical model merely because that model may be booked.

A provider answers only the minimum universal question needed by Scheduling:

```text
Does this persisted model represent a valid subject for this booking-subject key?
```

Public field collection, subject creation, subject lookup, and vertical-specific eligibility belong to later integration contracts rather than this base provider.

The durable appointment subject remains the existing polymorphic `appointments.primary_attendee` relationship. `AppointmentBookingData` continues to fall back to the Core Contact for ordinary person bookings, while a caller may supply a different persisted primary attendee for a vertical-owned subject.

## Service prerequisites

`bookable_service_prerequisites` expresses generic service-to-service completion requirements.

A prerequisite states:

```text
appointment type being booked
required prior appointment type
number of required completions
optional validity window in days
active/inactive state
sort order
source/meta
```

Prerequisites are evaluated against the actual persisted booking subject, not merely the Contact who submits or pays for the booking.

For example, if a future pet-service integration books a dog as the primary attendee, an Evaluation completed by Dog A cannot qualify Dog B even when both dogs belong to the same Contact.

Only appointments with:

```text
status = completed
completed_at <= evaluation time
matching primary_attendee_type
matching primary_attendee_id
```

count toward the requirement. When `valid_for_days` is set, the completion must also fall inside that rolling window.

Multiple active prerequisites are AND requirements: all must be satisfied. `required_completions` controls repeated completion of one prerequisite service.

## Subject compatibility

A prerequisite service and the Appointment Type that requires it must use the same `booking_subject_key`.

This prevents ambiguous rules such as requiring a person-scoped appointment to qualify a pet-scoped appointment through an accidental Contact match. Cross-subject business rules, when genuinely needed, require an explicit integration policy rather than hidden Scheduling behavior.

Setup validation reports:

- Appointment Types whose booking-subject provider is unavailable;
- self-referencing prerequisites;
- cross-subject prerequisites;
- invalid completion counts;
- invalid validity windows.

Runtime evaluation also fails closed when these structural rules are violated.

## Module boundary

This foundation is intentionally vertical-neutral.

A future PetServices integration may contribute a `pet` booking-subject provider and public booking behavior without adding a PetServices import to Scheduling. Pet identity, ownership, breed, behavior, training goals, vaccinations, and other pet-service meaning remain PetServices-owned.

Scheduling prerequisites remain about prior Appointment Type completion only. Pet-specific requirements such as vaccination validity or behavior eligibility belong to PetServices and should be composed through a separate eligibility/integration seam.

## Not included here

This foundation does not add:

- Appointment Type authoring controls for choosing a subject;
- prerequisite authoring UI;
- public booking fields for non-contact subjects;
- creation or lookup of pets or other vertical subjects;
- pet-specific eligibility rules;
- package/session entitlements or payment requirements;
- Buddy-specific configuration.

Those surfaces can now build on a durable subject identity and prerequisite contract instead of inventing client-specific columns or coupling Scheduling to a vertical.