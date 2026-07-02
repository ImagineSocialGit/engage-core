# Engage Core Project Organization

This document is a quick orientation map for Engage Core. It classifies the project into Core, universal modules, vertical modules, and integrations/adapters.

Use `module-boundaries.md` for detailed ownership and dependency rules. Use `TODO.md` for actionable implementation backlog.

## Layers

```text
Core
Universal modules
Vertical modules
Integrations/adapters
```

## Product Principles

Engage Core product decisions should also follow `product-principles.md`.

The core barometer is:

```text
Can the client realistically complete this task in Engage Core in 10-15 minutes total?
```

If yes, the task can be client-facing.

If no, the task should usually be developer/operator work, automated, preconfigured, preset-driven, hidden behind a simpler action, or split into a guided workflow that only asks the client for business decisions they are qualified to make.

Engage Core should make clients feel like:

```text
I have to do X.
I know where to click.
I can finish it in a few minutes.
I did not have to learn a new software category.
```

This means universal modules should provide reusable capability foundations while client-facing UX stays action-oriented and preset-driven.

Examples:

```text
Client-facing: draft a Broadcast, select recipients, schedule an appointment, send an existing form, review a submission.
Developer/operator-facing: build a form, design a Campaign, configure a FlowRoute, define document requirements, wire integrations.
```

## Core

Core is the required identity/contact foundation.

Core should almost never change. If a new universal module appears to require a Core change, first ask whether the need can be handled through an existing Core extension point, a new generic registry/seam, or a module-owned relation instead of new Core domain state.

Core owns:

- Contacts
- Contact statuses
- Contact tags
- Notes
- Contact imports and import registry/seams
- Generic contact lookup
- Generic contact filters for stable Contact-owned facts
- Contact show extension registries

Core should not own:

- messaging consent or delivery state
- scheduled appointments
- customer portal accounts
- form submissions
- document uploads
- commerce orders or purchases
- pet/dog data
- music/fan-specific data
- mortgage-specific data
- automation route progress
- campaign enrollment state
- task lifecycle state

Decision test:

```text
If it is not needed by almost every client install, it probably does not belong in Core.
```

## Universal Modules

Universal modules are reusable capability modules. They may be disabled for many clients, but they are not tied to a single business vertical.

### Current universal modules

| Module | Responsibility |
| --- | --- |
| Messaging | Outbound/scheduled email/SMS, consent, revocations, suppressions, gates, payloads, providers, opt-outs, permission invitations. |
| InboundMessaging | Inbound SMS/email webhooks, inbound message recording, inbound classification/routing. |
| InternalNotifications | Team members, notification preferences, internal notification gates/routing. |
| Tasks | Manual human actions/dependencies, task templates, task assignment/responsibility, task digests. |
| Workflow | Contact workflow/profile state and status transition services. |
| FlowRoutes | Automation/control-flow routes, route points, waits, event waits, route progress. |
| Campaigns | Enrolled multi-step message journeys and campaign progression. |
| Broadcasts | One-time/batch sends and recipient bookkeeping. |
| Webinars | Webinar series, webinars, registrations, waitlists, provider behavior, attendance, replay/follow-up orchestration. |
| Reporting | Reporting queries, dashboards, data objects, and report surfaces. |
| Scheduling | Appointments, availability, booking/reschedule/cancel behavior, appointment reminders. |
| Portal | External/customer accounts, portal auth, account invitations, contact-account links, customer-facing shell. |

### Planned universal modules

| Module | Responsibility | Example uses |
| --- | --- | --- |
| Forms | Form definitions, versions, schemas, submissions, submission review state. | Dog intake forms, mortgage intake, music booking inquiries, webinar questionnaires. |
| Documents | Document requests, uploaded document records, review events, document lifecycle state. | Vaccination records, waivers, mortgage docs, music contracts/assets. |
| Commerce | Normalized products, orders, order items, customer/contact links, purchase events, provider sync state. | Shopify purchase history, product-based filters, fan/customer segmentation. |
| Location | Contact locations, addresses, geocoding-derived coordinates, markets/regions, radius/service-area filters. | Show-radius targeting, dog trainer service areas, appointment eligibility. |

Universal modules should expose public actions/services/contracts/events where other modules need them. Other modules should not write directly to their internals when a public seam exists.

## Vertical Modules

Vertical modules compose Core and universal modules into a domain-specific product. They own business-specific language, records, workflow meaning, rules, and presets.

Vertical modules should not push domain-specific fields into Core contacts.

### Current vertical modules

| Module | Responsibility |
| --- | --- |
| Mortgage | Mortgage stages, contact mortgage profiles, mortgage-specific state, LOS/domain-specific behavior, mortgage presets. |

### Planned vertical modules

| Module | Responsibility | Universal modules it likely consumes |
| --- | --- | --- |
| PetServices | Pets/dogs, pet profiles, training goals, training programs, behavior notes, pet-service-specific rules/workflows. | Scheduling, Portal, Forms, Documents, Tasks, Messaging, Campaigns, Broadcasts, FlowRoutes, Reporting. |
| Music | Music-specific fan/customer meaning, release/fan strategy, music product interest categories, show/release logic, music-specific segmentation. | Commerce, Messaging, Campaigns, Broadcasts, FlowRoutes, Location, Scheduling, Portal, Reporting. |

## Integrations / Adapters

Integrations/adapters connect modules to external providers. They are not modules.

Adapters should live behind the owning module's contracts, managers, resolvers, or provider services.

| Adapter/integration | Owning/using module |
| --- | --- |
| Resend | Messaging |
| Telnyx | Messaging / InboundMessaging |
| Twilio | Messaging / InboundMessaging |
| Zoom | Webinars |
| Shopify, later | Commerce |
| External calendar providers, later | Scheduling |
| Geocoding/address providers, later | Location |
| LOS providers such as Arive, later | Mortgage |

## Classification Rule

Use this rule when deciding where a new capability belongs:

```text
Core = required identity/contact foundation.
Universal module = reusable capability many verticals can use.
Vertical module = business-domain-specific concepts/rules/language.
Integration = external provider adapter behind the owning module.
```

Examples:

| Need | Belongs in |
| --- | --- |
| Contact name/email/phone/source | Core |
| Appointment booking | Scheduling |
| Customer login/account | Portal |
| Intake questionnaire | Forms |
| Uploaded file/document request | Documents |
| Shopify purchase sync | Commerce + Shopify adapter |
| Dog profile/training goals | PetServices |
| Music fan/release strategy | Music |
| Radius-based targeting | Location |

## Core Change Standard

Before changing Core for a new module, ask:

1. Is this fact needed by almost every client install?
2. Is this a stable Contact-owned fact rather than module behavior?
3. Can the module own the data through its own table and link to Contact?
4. Can Core expose a generic registry/contract/filter seam instead of owning the domain state?
5. Would this change make Core import or understand a feature/vertical module?

Prefer:

```text
Core seam + module-owned data
`````

Avoid:

```text
New Core columns for feature or vertical state
```
