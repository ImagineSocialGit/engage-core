# Scheduling Booking Subjects, Eligibility, and Prerequisites

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

Other modules or installed packages may contribute additional subject providers through `BookingSubjectProviderRegistry`. Scheduling depends only on the `BookingSubjectProvider` contract and never imports a vertical model merely because that model may be booked.

A subject provider answers only the minimum universal question needed by Scheduling:

```text
Does this persisted model represent a valid subject for this booking-subject key?
```

The durable appointment subject remains the existing polymorphic `appointments.primary_attendee` relationship. `AppointmentBookingData` continues to fall back to the Core Contact for ordinary person bookings, while a caller may supply a different persisted primary attendee for a vertical-owned subject.

## Booking-subject eligibility policy

An Appointment Type may also store a provider-neutral JSON `booking_subject_policy`. Empty or null policy preserves existing Scheduling behavior and requires no eligibility provider.

When a non-empty policy is present, the provider registered for the Appointment Type's `booking_subject_key` through `BookingSubjectEligibilityProviderRegistry` owns the meaning and validation of that policy. Scheduling treats the payload as opaque business rules.

The eligibility provider receives the persisted booking subject plus the exact appointment start, appointment end, and rule-evaluation time. This matters for rules whose truth changes across the appointment window. For example, a vertical may require a credential or health record to remain valid through the end of a multi-day service instead of merely being valid when the booking is created.

Subject eligibility is enforced by `BookableServiceBookingRuleGuard`, so direct appointment creation and booking-hold conversion use the same rule path. A configured policy fails closed when its provider is unavailable, its payload is invalid, or the selected subject does not satisfy it.

This eligibility contract is intentionally separate from `BookingEligibilityProvider`. That existing provider family belongs to code-based booking offers and resolves offer qualification to a Core Contact identity. Subject eligibility answers a different question: whether the actual persisted booking subject may receive this Appointment Type.

Setup validation reports Appointment Types that have a non-empty subject policy but no matching eligibility provider, plus policies rejected by their provider.

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

For example, when PetServices books a dog as the primary attendee, an Evaluation completed by Dog A cannot qualify Dog B even when both dogs belong to the same Contact.

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
- Appointment Types whose subject-policy provider is unavailable;
- invalid provider-owned subject policies;
- self-referencing prerequisites;
- cross-subject prerequisites;
- invalid completion counts;
- invalid validity windows.

Runtime evaluation also fails closed when these structural rules are violated.

## Module boundary

This foundation is intentionally vertical-neutral.

PetServices, when installed, contributes the `pet` booking-subject provider and may contribute the matching subject-eligibility provider. Pet identity, ownership, breed, behavior, training goals, vaccinations, age rules, and other pet-service meaning remain package-owned. Scheduling sees only an opaque policy and the provider-neutral eligibility contract.

Scheduling prerequisites remain about prior Appointment Type completion only. Pet-specific vaccination or age requirements do not become Scheduling concepts.

## Not included here

This foundation does not add:

- Appointment Type authoring controls for choosing a subject;
- subject-policy authoring UI;
- prerequisite authoring UI;
- public booking fields for non-contact subjects;
- creation or lookup of vertical subjects;
- vertical-specific policy semantics;
- package/session entitlements or payment requirements;
- client-specific configuration.

Those surfaces can build on durable subject identity, subject eligibility, and prerequisite contracts without inventing client-specific columns or coupling Scheduling to a vertical.