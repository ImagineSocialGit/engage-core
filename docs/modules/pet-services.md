# PetServices Module

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

PetServices is a planned vertical module.

PetServices should own pet-service and dog-training-specific business meaning.

PetServices may own, when implemented:

- pets/dogs
- pet profiles
- dog training programs
- training goals
- behavior notes
- trainer assignments, if domain-specific
- vaccination requirement rules, if domain-specific
- pet-service-specific workflow definitions
- pet-service-specific FlowRoute definitions
- pet-service-specific form/document templates and interpretation rules

PetServices may consume:

- Core
- Scheduling
- Portal
- Forms
- Documents
- Tasks
- Messaging
- Campaigns
- Broadcasts
- FlowRoutes
- Reporting
- Integrations

PetServices must not push pet-specific state into Core contacts.

Vertical-specific migrations should live in:

    database/migrations/verticals/pet-services

Good:

    PetServices owns DogProfile
    Scheduling owns appointment time/status
    Documents owns uploaded vaccination record
    PetServices decides whether that record satisfies a dog-training requirement

Bad:

    Core contacts get dog_name, breed, vaccination_status, or training_goal columns
    Scheduling owns dog behavior/training data
