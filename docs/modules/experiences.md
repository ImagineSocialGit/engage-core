# Experiences Module

Experiences is a planned optional vertical module.

Experiences owns post-purchase and operational fulfillment for special-access packages such as VIP, backstage, meet-and-greet, soundcheck, tour-bus, hospitality, or other managed experiences.

Experiences is not Scheduling and is not Commerce.

A concise distinction:

```text
Scheduling
    books an appointment time from availability

Commerce
    presents and sells an offer, then records the purchase

Experiences
    fulfills the purchased package and manages access/participants
```

Experiences should be built only after Events has a stable public contract and Commerce has a Shopify-capable purchase contract.

## Product barometer

Experiences should follow the Engage Core product barometer:

```text
If the client-facing task cannot realistically be completed in Engage Core in 10-15 minutes total, it should usually not be a client-facing workflow.
```

Appropriate client/admin work:

```text
See who purchased a VIP package.
Review the participants attached to that package.
Send or reissue management access.
View package benefits and fulfillment state.
Scan or validate an Experience credential.
Check in an eligible participant.
Resolve a participant or entitlement issue.
```

Developer/operator work:

```text
Define reusable package/benefit structures.
Map Commerce variants to Experience packages.
Configure public host deployment.
Configure credential and scanning policy.
Contribute Messaging, FlowRoutes, Tasks, Reporting, or Music behavior.
```

## Responsibility

Experiences should answer:

```text
What special-access package was acquired?
For which occurrence is it valid?
Who is the purchaser?
Who are the participants?
What benefits or access does the package grant?
What credentials were issued?
What has been checked in or fulfilled?
```

Experiences remains operational and post-purchase.

## Owns

Experiences should own, when implemented:

```text
Experience definitions
Experience occurrences
Experience package/entitlement definitions
purchaser package grants
participant slots and participant identity snapshots
benefits and benefit fulfillment
management access grants
QR or other Experience credentials
Experience-specific check-in and scanning
Experience manifests
Experience operational lifecycle
Experience-owned public management and scanning surfaces
neutral Experience automation events
```

The exact first-slice table design must be finalized after the Events and Commerce contracts are stable.

Likely concepts include:

```text
Experience
    reusable experience concept

ExperienceOccurrence
    dated operational occurrence
    may stand alone
    may optionally reference one Event through an Experiences-owned nullable event_id

ExperiencePackage
    package or entitlement definition

ExperienceGrant
    acquired/manual entitlement for a purchaser

ExperienceParticipant
    participant identity and slot assignment

ExperienceBenefit
    included access or fulfillment unit

ExperienceCredential
    QR or other access credential

ExperienceCheckIn
    Experience-owned access/check-in result
```

These names are architectural concepts, not an approved migration manifest.

## Does not own

Experiences does not own:

```text
public storefront discovery
public product or offer pages
product/variant catalog authority
pricing
inventory
cart
checkout
payment processing
Commerce order state
Shopify webhooks
generic Event identity or lifecycle
Event attendance reconciliation
appointment availability or appointment lifecycle
artist, lineup, setlist, or tour meaning
Messaging delivery
FlowRoute execution
Task lifecycle
```

Experiences must not become a second checkout, a second Event catalog, or an appointment scheduler.

## Dependency direction

Expected dependency direction:

```text
Core
Events
Commerce
└── Experiences
```

Experiences may depend on Core for purchaser/contact identity, Events for optional Event-linked occurrences and promotion gates, and Commerce for purchased-item identity and purchase-confirmed signals.

Experiences may optionally consume through public seams:

```text
Messaging
Tasks
FlowRoutes
InternalNotifications
Reporting
Location
Portal-style access infrastructure if a reusable seam exists
Integrations
```

Music may consume or extend Experiences. Experiences must not depend on Music-specific models for universal package/credential behavior.

## Events boundary

An ExperienceOccurrence may reference at most one Event through an Experiences-owned nullable `event_id`.

```text
standalone ExperienceOccurrence
    no Event required

Event-linked ExperienceOccurrence
    references one canonical Event
    inherits Event schedule/context as defined by the public contract
```

Events does not import or understand Experiences.

An Event-linked Experience inherits the Event promotion gate as a hard upstream rule.

```text
Event promotion blocked
-> Event-linked Experience cannot be publicly promoted or sold

Event promotion allowed
-> Experiences and Commerce may evaluate their own remaining readiness
```

Experience operational management may continue after purchase even when public promotion is no longer allowed. Promotion gating must not erase or strand valid existing entitlements.

Experience check-in must not automatically create Event attendance. A later explicit policy may call the Events attendance action only when the client has defined that an Experience check-in is authoritative Event attendance evidence.

## Commerce boundary

Commerce exclusively owns:

```text
storefront discovery
public offer pages
product presentation
Shopify product/variant identity
cart creation
checkout redirection
purchase and order reconciliation
purchase-confirmed signal
```

Experiences owns everything operational after the purchase creates or confirms an entitlement.

Expected flow:

```text
Engage Core Commerce offer page
-> Shopify cart and hosted checkout
-> Shopify webhook/order reconciliation in Commerce
-> Commerce emits purchase-confirmed outcome
-> Experiences grants the mapped package
-> purchaser manages participants/access
-> staff scans and fulfills the Experience
```

The package-to-Commerce mapping belongs to Experiences or an explicit Experiences-owned mapping table because Experiences owns the meaning of the purchased variant.

Commerce must not create:

```text
Experience grants
participant slots
benefits
QR credentials
check-ins
fulfillment records
```

Experiences must not infer a successful purchase from a browser return URL. It acts only on authoritative Commerce reconciliation or an explicit authorized manual grant.

## Shopify boundary

Experiences does not integrate directly with Shopify.

```text
Shopify adapter
    belongs behind Commerce contracts

Commerce
    normalizes and confirms the purchase

Experiences
    consumes Commerce's provider-neutral purchase identity/outcome
```

This keeps Experience fulfillment provider-neutral and prevents Shopify-specific identifiers from becoming the operational entitlement contract.

## Public surface

Experiences should use one client-configured public host, for example:

```text
vip.[ROOT_DOMAIN]
```

The exact host is deployment configuration and must not be hard-coded.

Use separate route groups and access controls on the same public surface:

```text
/manage/...
    purchaser package management
    participant entry/update
    credential access
    package instructions

/scan/...
    staff scanning
    manifest lookup
    check-in/fulfillment actions
```

The public Experiences host is post-purchase and operational only.

It must not contain a public Experience catalog or storefront. Commerce owns public discovery and purchasing.

Complimentary or manual grants may use direct Experience management links without creating a public catalog entry.

## Purchaser and participant identity

The purchaser is normally linked to a Core Contact through the Commerce order/customer reconciliation path.

Participants may differ from the purchaser.

Experience participant records should preserve only the identity and operational fields required to manage the entitlement. Do not automatically promote every participant to a Core Contact.

A later explicit opt-in or reconciliation process may link a participant to a Contact when justified.

## Package and benefit modeling

Experience packages should describe durable operational meaning rather than copy full Commerce product snapshots.

Good:

```text
Commerce variant identifies what was sold.
Experiences mapping identifies which package is granted.
ExperiencePackage defines participant capacity and included benefits.
ExperienceGrant records the purchaser's acquired entitlement.
```

Bad:

```text
Copy the full Shopify product, variant, price, and order payload into Experience JSON.
Let Commerce own participant and benefit fulfillment.
```

Benefits should be normalized when they need independent capacity, fulfillment, check-in, or reporting. Avoid a generic unbounded `meta` payload for core entitlement facts.

## Credentials and scanning

Experience credentials are access artifacts, not tickets.

They prove access to an Experience-owned package or participant grant according to Experience policy. They do not prove external venue admission or ticket ownership.

Scanning should be:

```text
idempotent
permission-gated
bounded to the selected client/Experience occurrence
clear about already-used, invalid, revoked, or not-yet-valid credentials
auditable without storing redundant full request payloads
```

The scanner must remain isolated from the CRM admin surface while using explicit staff access controls.

## Messaging boundary

Experiences may request transactional messages such as:

```text
management-access invitation
participant instructions
credential delivery
schedule or access update
post-Experience follow-up
```

Messaging owns templates, consent rules, scheduling, provider delivery, attempts, and suppressions.

Experiences owns the business trigger, recipient/context selection, and token data.

## FlowRoutes and Tasks boundary

Experiences remains functional without FlowRoutes.

FlowRoutes may coordinate optional processes through Experiences public actions and neutral events.

Examples:

```text
purchase confirmed
-> grant package
-> request participant details
-> create staff preparation Tasks
-> wait for required participant information
-> send instructions
-> notify staff before occurrence
```

Tasks owns Task lifecycle. Experiences may provide subjects, templates, or action contributions without writing Task tables directly.

## Music boundary

Music owns:

```text
artist association
VIP terminology specific to an artist or tour
show/tour context
music-specific package interpretation
music-specific presets and messaging strategy
```

Experiences owns reusable entitlement, participant, credential, scanning, and fulfillment behavior.

Good:

```text
Music associates an artist/show context with an Experience occurrence.
Experiences manages the purchased VIP package and access.
Commerce records the Shopify purchase.
Events owns the canonical show Event.
```

Bad:

```text
Events stores VIP participant slots.
Commerce scans credentials.
Music owns generic QR credential infrastructure.
```

## Automation events

Experiences should record its own state first and emit neutral automation events through the shared outbox.

Likely future keys:

```text
experience.grant_created
experience.participant_added
experience.participant_completed
experience.credential_issued
experience.checked_in
experience.benefit_fulfilled
experience.grant_cancelled
```

Exact keys should be introduced only with implemented actions and proven consumers.

## Project State

Experiences durable operational state must be transferred by a dedicated Project State section before production use.

Transfer should include canonical package definitions, occurrences, grants, participant state, benefits, credentials, check-ins, and other required durable state according to the final schema.

Do not transfer:

```text
credentials or secrets that are deployment configuration
ephemeral signed URLs
reconstructible QR render artifacts
short-lived scan sessions
provider access tokens
```

Do not ship production Experiences while its durable tables remain under must-be-empty policies.

## Implementation prerequisites

Before the Experiences foundation begins, complete:

```text
1. stable Events public contracts and promotion gate
2. Events Project State support
3. Commerce product-variant and offer contracts
4. Shopify cart/checkout and authoritative order reconciliation
5. Commerce purchase-confirmed provider-neutral signal
6. Commerce Project State support
```

Subject-first FlowRoutes and contributor-based Contact filters are prerequisites only for the optional automation/audience integrations that require them; they are not required for the core Experience entitlement and scanning foundation.

## Implementation status

Current repository status:

```text
Experiences module directory: not present
Experiences tables: not present
Experiences Project State section: not present
Experiences public host/routes: not present
Commerce-to-Experiences grant integration: not present
```

This document is the canonical boundary reference. Exact schema and file manifests must be confirmed after the Events and Commerce implementation contracts are stable.