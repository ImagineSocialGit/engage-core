

# Possible Scenarios

This document grounds Engage Core's abstract module architecture in concrete cross-industry workflows.

The goal is not to turn Core into an industry-specific product. The goal is to keep universal modules generic enough to support real vertical scenarios while letting vertical modules own their domain facts.

## Why this file exists

Engage Core uses universal modules such as Core, Messaging, Campaigns, Broadcasts, Tasks, Workflow, FlowRoutes, Webinars, Scheduling, Documents, Forms, Portal, Commerce, and Location.

Vertical or client-specific modules should contribute domain meaning, presets, labels, task templates, route presets, automation events, and subject records without forcing their fields into Core.

Concrete scenarios help verify that architecture decisions remain useful for real industries instead of becoming too abstract to operate.

## Universal pattern

Most concrete workflows should follow this shape:

```text
Domain module records a real-world event or state.
Domain module emits AutomationEventRecorded with a stable event key.
FlowRoutes starts or resumes selected routes through trigger bindings/event_wait.
FlowRoutes calls other modules only through public actions/services/contracts.
Tasks, Messaging, Campaigns, Documents, Scheduling, Portal, Commerce, and vertical modules own their own business state.
```

FlowRoutes owns routing, route selection, route instance state, route plans, plan items, progress items, capability availability, and route-created artifact provenance.

Producer modules do not import FlowRoutes and do not construct FlowRouteExternalEvent directly.

## Music / Artist platform scenarios

### Merch purchase follow-up

A fan buys a merch item from the artist store.

Expected shape:

```text
Commerce or Music records purchase.
Commerce/Music emits AutomationEventRecorded(commerce.purchase.completed or music.merch.purchased).
FlowRoutes starts the selected merch route.
The route may:
    update fan/customer status or segment;
    enroll the contact into a merch-interest Campaign;
    send a thank-you or product-care message;
    create an internal task for high-value/VIP purchase follow-up;
    wait for a future related-product release event.
```

The merch route should not require Core contacts to own music-specific product-interest fields.

Good ownership split:

```text
Commerce owns purchase facts and external order IDs.
Music owns artist-specific fan/customer meaning and product-interest categories.
Campaigns owns the Campaign journey.
Messaging owns message templates and delivery.
FlowRoutes owns route selection, automation path, and route-created artifact provenance.
```

Future release targeting could be driven by a later event such as:

```text
music.release.announced
music.product.released
commerce.product.available
```

The route or campaign should target contacts through Music/Commerce interest facts rather than broad contact-only assumptions.

### Show attendance or show signup follow-up

A fan attends a show and signs up for the newsletter at the merch table, QR code, or check-in surface.

Expected shape:

```text
Music, Events, or Forms records show signup/attendance.
The module emits AutomationEventRecorded(music.show.signup or music.show.attended).
FlowRoutes starts a selected show/location route.
The route may:
    tag/segment the fan as show-acquired;
    enroll the fan in a regional show-notification Campaign;
    send a welcome message referencing the show;
    wait for the next show announced in the same geographic area.
```

Good ownership split:

```text
Music owns show/fan meaning.
Forms may own the signup form submission.
Location owns reusable geographic targeting concepts.
Campaigns/Messaging own outreach.
FlowRoutes owns route logic and route instance state.
```

This scenario depends on future Music/Event/Location/Commerce facts, but the FlowRoutes architecture is compatible with it.

### New release or tour announcement

A new album, single, merch drop, or show is announced.

Expected shape:

```text
Music records release/show announcement.
Music emits AutomationEventRecorded(music.release.announced or music.show.announced).
FlowRoutes starts selected announcement routes.
Campaigns/Broadcasts/Messaging deliver outreach to eligible contacts.
```

Eligibility should come from Music/Commerce/Location facts, such as prior merch purchase, prior show attendance, release-interest category, or location radius.

## PetServices scenarios

### Dog behavior training route

A pet-service business starts a training program for a contact's dog.

Expected shape:

```text
PetServices records dog/training-program enrollment.
PetServices emits AutomationEventRecorded(pet.training.started).
FlowRoutes starts a subject-scoped route for contact + dog.
The route may:
    create trainer tasks;
    schedule follow-up appointments;
    send owner homework messages;
    wait for a training-session-completed event;
    repeat or insert route instance plan items if the dog needs more work.
```

Good ownership split:

```text
PetServices owns dogs, training programs, goals, behavior notes, and trainer-specific rules.
Scheduling owns appointments/sessions.
Tasks owns task lifecycle.
Messaging owns communication.
FlowRoutes owns subject-scoped route instance progress and route-created provenance.
```

The route should be scoped to the dog subject, not just the contact, because one contact may have multiple dogs.

### Vaccination or waiver request

A boarding/training business needs documents before service.

Expected shape:

```text
PetServices or Documents records required document state.
FlowRoutes creates a document request through Documents public actions.
FlowRoutes waits for document.completed or document.approved.
When complete, the route advances to scheduling or service-readiness steps.
```

Good ownership split:

```text
Documents owns file/request/review state.
PetServices owns which documents are required for pet services.
FlowRoutes owns waiting/resume logic.
```

## Mortgage scenarios

### Webinar attendee to mortgage follow-up

A contact attends a home-buyer or loan-specific webinar.

Expected shape:

```text
Webinars records attended outcome.
Webinars emits AutomationEventRecorded(webinar.attended).
FlowRoutes starts selected webinar-attended routes.
The routes may:
    move the contact to attended_webinar status;
    enroll the contact in the attended nurture Campaign;
    create a loan-officer task;
    send a transactional replay/follow-up message.
```

Good ownership split:

```text
Webinars owns registration, attendance, replay/join facts.
Workflow/Core own contact status state.
Campaigns owns nurture sequences.
Messaging owns message delivery and templates.
Tasks owns internal follow-up tasks.
FlowRoutes owns selected route behavior.
```

### Missed webinar to alternate nurture

A contact registers but misses the webinar.

Expected shape:

```text
Webinars records missed outcome.
Webinars emits AutomationEventRecorded(webinar.missed).
FlowRoutes starts selected missed-webinar routes.
The routes may:
    move the contact to missed_webinar status;
    enroll the contact in a missed-webinar nurture sequence;
    send a replay or reschedule prompt;
    create a task only for high-intent contacts.
```

### Document collection route

A mortgage contact reaches a point where bank statements, pay stubs, or disclosures are needed.

Expected shape:

```text
Mortgage or Workflow records milestone.
FlowRoutes creates document requests through Documents public actions.
FlowRoutes waits for document.completed or document.approved events.
Tasks may be created for loan officer review.
Messaging may remind the borrower.
```

Good ownership split:

```text
Mortgage owns loan-specific milestones and requirements.
Documents owns document request/upload/review state.
Tasks owns review task lifecycle.
Messaging owns reminders.
FlowRoutes owns route plan and wait/resume behavior.
```

## Imported-contact and permission scenarios

### Imported contacts need opt-in invitations

A client imports legacy contacts who need permission/consent before normal outreach.

Expected shape:

```text
Core owns import batches and import-batch visibility.
Messaging owns permission invitation lifecycle and one-time email-only invitation behavior.
Broadcasts can target import batches for normal sends but do not own permission invitation bypasses.
FlowRoutes may later consume neutral permission_invitation.accepted events if a real workflow needs it.
```

Permission invitations should remain a Messaging-owned consent workflow, not a generic Broadcast bypass.

## Generic route examples

### One route, multiple points

A selected route may perform multiple actions from one trigger.

Example:

```text
Prospect status changed
→ selected Prospect route
    → create internal follow-up task
    → enroll nurture campaign
    → send immediate confirmation message
    → wait for task.completed
    → advance status or continue campaign
```

These are route points in one route, not unrelated active routes competing for the same trigger.

### Task-completed event wait

A route creates a task and waits for that task to be completed.

Safe shape:

```text
FlowRoutes create_task point creates the Task through Tasks public actions.
Tasks records completion.
Tasks emits AutomationEventRecorded(task.completed).
FlowRoutes resumes the matching event_wait.
```

Safe matching rules:

```text
Do not resume task.completed waits through contact-only fallback.
If the route created exactly one Task before the wait, FlowRoutes may resume from the unambiguous route-created Task artifact.
If the route created multiple Tasks before the wait, the event_wait must define explicit correlation.
```

Example explicit correlation:

```php
'correlation' => [
    'task.task_template_key' => 'route.follow_up',
    'task.flow_route_progress_id' => '{flow_route_progress.id}',
]
```

## What this validates about the architecture

These scenarios confirm the target architecture:

```text
Core remains generic.
Vertical modules own domain facts.
Universal modules own reusable operational primitives.
FlowRoutes orchestrates but does not own other modules' business state.
Automation events are neutral seams.
Route progress can be contact-scoped or contact + subject-scoped.
Route-created artifacts carry uniform provenance.
Client/operator UI can eventually present concrete consequences without exposing raw internals.
```

## What still needs future work

These scenarios also clarify what is not done yet:

```text
Music vertical domain model.
PetServices vertical domain model.
Commerce/store integration and purchase facts.
Show/event attendance model.
Location-radius targeting model.
Documents/Scheduling/Form/Portal scenario-specific route presets.
Product-complete new Route creation and remaining focused Routes authoring/discovery UX.
Config/setup validation for preset references, event keys, route point definitions, task-template refs, campaign refs, messaging refs, available fields, capability availability, and unsupported module combinations.
```

Phase 6 config/setup validation should use scenarios like these to ensure future presets fail clearly when they reference unavailable modules, missing templates, missing campaign keys, unsupported point types, invalid tokens/fields, unavailable capabilities, or unsafe route/event_wait correlation.
