# PetServices Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable module backlog in this directory's `TODO.md` when one exists.

PetServices is a current optional vertical foundation.

The first implemented foundation owns durable pet identity and pet-service business facts without exposing a CRM or public surface yet.

## Responsibility

PetServices answers vertical questions such as:

```text
Which pet belongs to which Core contact?
What pet profile facts are known?
What training goals are active?
What behavior observations have been recorded?
What vaccination records exist and have they been verified?
```

PetServices owns this business meaning. Core remains the person/contact system of record.

## Current schema

PetServices currently owns:

```text
pets
pet_contact_links
pet_training_goals
pet_behavior_notes
pet_vaccinations
```

`pets` is the durable pet identity.

`pet_contact_links` connects a pet to one or more Core contacts and records the business role, primary-owner/guardian preference, active state, and relationship dates without changing the Core contact schema.

Training goals, behavior notes, and vaccination records belong directly to the pet. Their `meta` fields are extension space, not a substitute for future first-class fields when a concept becomes durable product behavior.

## Dependencies

PetServices directly depends on:

- Core

The initial foundation deliberately does not depend on Scheduling, Relationships, Documents, Portal, Forms, Tasks, Messaging, Campaigns, Broadcasts, FlowRoutes, Reporting, or Integrations.

Future PetServices capabilities may consume those modules through explicit module dependencies or provider-neutral integration seams when the owning behavior is implemented.

## Scheduling boundary

Scheduling owns appointment type, host, availability, capacity, time, location commitment, attendee mechanics, booking holds, and appointment lifecycle.

PetServices owns the pet.

A later Scheduling integration may use a Pet as a vertical-owned appointment subject through Scheduling's existing polymorphic attendee/primary-attendee boundary. Scheduling must not gain `dog_name`, `breed`, `vaccination_status`, `training_goal`, or other pet-specific columns.

Service prerequisites such as “Training Evaluation must be completed before Board & Train” belong to Scheduling as generic appointment-type prerequisite behavior.

Pet-domain eligibility such as vaccination requirements, age rules, or behavior suitability remains PetServices-owned even when it affects whether a Scheduling service can be booked.

## Relationships boundary

The Relationships module models business relationships held by Core contacts. It is not the owner of pet identity and must not be used to represent a dog as though it were another Contact relationship type.

Pet-to-person ownership/guardianship is represented by `pet_contact_links`.

## Documents boundary

Documents owns uploaded files.

PetServices may later associate a vaccination or other pet record with Documents-owned evidence through an integration/link boundary. PetServices should not duplicate document storage or add a Documents dependency merely to retain pet facts.

## Future vertical capabilities

PetServices may later own:

- dog training programs
- trainer assignments when the assignment has pet-training-specific meaning
- vaccination requirement rules
- booking-subject discovery/authoring for pets
- pet-specific booking eligibility providers
- pet-service-specific workflow definitions
- pet-service-specific FlowRoute definitions
- pet-service-specific form/document templates and interpretation rules
- pet-focused CRM/contact-adjacent surfaces

These should be added only when their owning behavior is implemented.

## Migration ownership

Vertical-specific migrations live in:

```text
database/migrations/verticals/pet-services
```

PetServices is a normal schema-owning module scope in the shared module migration registry. Runtime startup must not run its migrations merely because code is present.

## Boundary examples

Good:

```text
PetServices owns Pet and PetVaccination.
Core owns the pet owner's Contact.
Scheduling owns appointment time/status.
Documents owns an uploaded vaccination record.
PetServices decides whether pet-domain evidence satisfies a pet-service requirement.
```

Bad:

```text
Core contacts get dog_name, breed, vaccination_status, or training_goal columns.
Scheduling owns dog behavior/training data.
Relationships represents a dog as a Contact relationship.
PetServices stores appointment availability or document file bytes.
```