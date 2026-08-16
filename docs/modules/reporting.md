# Reporting Module

This module reference owns the detailed responsibility, dependency, privacy, collection, aggregation, and report-contract notes for Reporting. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

## Module identity

Reporting is a current optional universal module.

```text
Architecture tier:   universal module
Product surface:     loud
Standalone value:    yes
Primary users:       CRM operators, client owners, implementation/support operators
Primary surfaces:    CRM Reporting workspace; report-specific drill-down surfaces
Hard dependency:     Core only
```

Reporting must remain useful across mortgage, music, pet services, events, commerce, scheduling, and future verticals without importing their private models or absorbing their business logic.

The module is deliberately not an analytics platform clone. Its purpose is to answer concrete Engage Core questions using privacy-safe first-party behavior plus authoritative producer-owned business outcomes.

## Product goal

Reporting should answer questions such as:

```text
How many likely-human visits reached this public experience?
Where did those visits come from?
Where did people stop in the funnel?
Which validation failures are common?
How many locally completed registrations finalized successfully with the provider?
Were confirmation messages planned and delivered as expected?
How many registrants joined and attended?
Which configured answer choices were selected?
How do first-party outcomes compare with externally reported campaign measurements?
```

The first committed report is the Webinar traffic and conversion funnel.

Reporting should favor a small number of trustworthy, explainable measures over a large dashboard of ambiguous counters.

## Responsibility

Reporting owns:

```text
privacy-safe first-party browser observations
Reporting session correlation
Reporting event-definition validation
observation deduplication and normalization
traffic classification stored for Reporting use
attribution normalization for Reporting use
Reporting-owned aggregate/read models
projection checkpoints and rebuild behavior
report query/read services
Reporting CRM controllers and views
report-specific filters and denominator definitions
later generic external measurement imports/comparisons
Reporting retention and pruning policy
```

Reporting does not own:

```text
Contact identity or merge semantics
Webinar registrations, waitlists, joins, attendance, provider finalization, or answers
Messaging consent, ScheduledMessage lifecycle, delivery attempts, or provider outcomes
Campaign enrollment/progression
Broadcast recipient lifecycle
Commerce orders/purchases
Scheduling appointments
Forms submissions
FlowRoute execution
AutomationEventRecorded
Nginx access logs or Laravel operational logs
provider credentials
cross-domain visitor identity
advertising-platform user profiles
```

Producer modules remain authoritative for their own durable business facts.

Reporting must never mutate another module's state merely to make a report easier to calculate.

## Dependency and contribution boundary

Reporting depends only on Core.

It must not add direct dependencies on Webinars, Messaging, Campaigns, Broadcasts, Commerce, Scheduling, Forms, Events, Mortgage, Music, PetServices, or another optional module.

Producer modules must also continue functioning when Reporting is disabled.

The intended integration direction is:

```text
shared Reporting contracts/registry
    always available as app-level infrastructure

Reporting disabled
    -> browser/business observation recorder resolves to no-op behavior
    -> producer modules continue normally

Reporting enabled
    -> Reporting binds the recorder implementation
    -> Reporting reads registered producer contributors
    -> Reporting owns persistence/projections/report surfaces
```

Producer modules may depend on neutral shared Reporting contracts under `app/Support/Reporting`, but they must not import `App\Modules\Reporting` runtime internals.

Reporting may consume producer facts only through registered public read contributors, DTOs, public query services, or another deliberately documented neutral Reporting seam.

Good:

```text
Webinars service provider
    -> registers a Webinar reporting contributor with shared registry

Reporting
    -> asks registered contributors for normalized durable facts
```

Bad:

```text
Reporting
    -> WebinarRegistration::query()
    -> ScheduledMessage::query()
    -> provider-specific Zoom tables/payloads
```

The neutral shared observation seam is implemented under `app/Support/Reporting` through `ReportingObservationRecorder`, `ReportingEventDefinitionContributor`, `ReportingEventDefinition`, `ReportingObservationData`, and `ReportingEventDefinitionRegistry`. The app-level default recorder is a no-op; enabling Reporting replaces only that implementation. Producer modules therefore never need to import `App\Modules\Reporting`.

## Two input paths

Reporting uses two deliberately different input paths.

### 1. Reporting-owned browser observations

Reporting owns a same-origin public ingestion endpoint and lightweight browser client for behavior that does not already exist as an authoritative domain record.

Examples:

```text
page view
CTA click
modal open
form start
form submit attempt
normalized validation failure
request throttled outcome
bot-protection outcome
```

Browser observations are untrusted input.

The browser may propose only fields permitted by the selected event definition. The server owns trusted values such as effective host/surface, received time, normalized path, classification, definition version, and any server-known Webinar/page context.

Current transport:

```text
POST /_reporting/observations
```

The route is Reporting-owned, stateless, and registered only while Reporting is enabled. It does not use Laravel's browser session or a persistent analytics cookie. The request must carry an exact same-origin `Origin`; when `Sec-Fetch-Site` is present it must also be `same-origin`. Public eligibility is defined by the selected versioned event definition: both the normalized surface and exact `browser_hosts` entry must match the request. A definition with no `browser_hosts` cannot be collected through the browser endpoint.

The generic browser client lives at `resources/js/reporting/client.js`. It performs no automatic page tracking. Producer/public-surface code must deliberately call it with an approved event definition. It stores only one opaque random token in `sessionStorage`; if storage or secure browser randomness is unavailable, collection falls back to an uncorrelated page-only observation.

Throttling may use the request IP transiently. Rate-limit cache keys use only a one-way hash scoped to the request host, and that value is never persisted into Reporting. Public responses expose only bounded status/error codes and never Reporting row IDs or submitted values.

### 2. Producer-owned durable fact contributors

Reporting does not manufacture browser events for durable business outcomes that already have a canonical source.

Examples:

```text
Webinar local registration completion
waitlist completion
provider registration finalization
trusted join evidence
attendance finalization
Webinar answer distributions
Messaging confirmation planning/deduplication
ScheduledMessage sent/skipped/failed outcomes
Campaign enrollment or conversion outcomes introduced later
```

Those facts are projected through producer-owned Reporting contributors or public read seams.

This keeps the report tied to authoritative domain state rather than copied historical payloads.

## Explicitly excluded input paths

### Automation events are not the Reporting event bus

`AutomationEventRecorded` exists for business automation/control-flow outcomes. Reporting must not reuse it as a generic analytics stream.

A fact may independently be useful to both automation and Reporting, but each consumer should use the seam appropriate to its purpose.

### Operational logs are not Reporting storage

Nginx access logs, Laravel JSON logs, Horizon output, PHP-FPM slow logs, and request IDs are operational observability.

They are not Reporting observations and must not be imported into Reporting.

Operational logging has a separate short-retention privacy boundary. Reporting may use request correlation while diagnosing an incident, but the Reporting data model must not become a log warehouse.

## Initial durable Reporting concepts

Reporting currently owns these durable tables:

```text
reporting_sessions
reporting_observations
reporting_daily_metrics
reporting_projection_checkpoints
```

A later external-comparison slice may add:

```text
reporting_external_measurements
```

Their bounded privacy-first columns and indexes are implemented in the Reporting module migration. The semantic ownership below remains the durable contract.

### `reporting_sessions`

One short-lived first-party host-scoped correlation window.

It may persist only the bounded facts required to group observations and preserve initial attribution, such as:

```text
opaque session identity/hash
host/surface
started_at
last_seen_at
absolute_expires_at
landing path
referrer host
allowlisted attribution dimensions
coarse device/browser/OS classification
traffic class + classifier provenance
```

It must not become a visitor profile.

### `reporting_observations`

Append-style normalized interaction evidence.

At minimum, an observation needs stable concepts for:

```text
event UUID
versioned event definition identity
occurred/received time
session reference nullable
trusted host/surface/path context
normalized bounded properties
traffic classification/provenance
relevant report dimensions
```

Do not persist arbitrary browser JSON, full request objects, raw form values, complete URLs, provider payloads, or Eloquent model snapshots.

### `reporting_daily_metrics`

Privacy-safe, query-efficient aggregates derived from Reporting observations and producer facts.

The first aggregates should support the committed Webinar report and its filters rather than becoming a speculative universal metric cube.

Expected bounded dimensions include only those needed by current reports, such as:

```text
date
metric key/version
series/occurrence identity when applicable
source/campaign/content attribution
traffic class
device class
page revision
```

High-cardinality or personal identifiers do not belong in the default daily aggregate key.

### `reporting_projection_checkpoints`

Durable idempotency/rebuild state for Reporting projectors.

A checkpoint should identify the projector and its version and preserve enough cursor/window state to make retries and rebuilds deterministic.

Projection code must be safe to rerun without double-counting.

### `reporting_external_measurements`

Deferred until the external-platform comparison slice.

This table will store normalized aggregate measurements from configured external platforms, not external visitor identities or raw platform event feeds.

The schema must remain provider-neutral. Meta, Google, TikTok, or another platform may be adapters; no vendor should define the Reporting domain model.

## Event-definition contract

Browser observations use an explicit versioned definition registry.

Each definition owns:

```text
stable event key
definition version
allowed public surfaces/contexts
exact browser host allowlist for public collection
allowed property keys
property types/enums/bounds
trusted server-derived fields
whether a session is optional/expected
whether the event is eligible for primary funnel metrics
```

Rules:

```text
unknown event key/version
    reject

unknown property
    reject rather than silently retaining arbitrary data

invalid property type/value
    reject

oversized event/payload/property
    reject before persistence

same event UUID replayed with equivalent normalized content
    dedupe idempotently

same event UUID replayed with conflicting normalized content
    reject as a conflict
```

Executable Reporting configuration now owns hard payload/property ceilings. Runtime code clamps those values to the documented privacy/security maximums even if client config is broadened incorrectly.

Browser-supplied timestamps may be retained only as bounded event timing context. Server receipt time remains authoritative for ingestion and abuse controls.

## Initial browser event vocabulary

The first browser vocabulary should remain generic enough to support other public experiences while carrying trusted Webinar context when used on Webinar pages.

Committed event meanings:

```text
page.view
    a public page was actually rendered/viewed by the browser client

cta.click
    a configured primary/secondary CTA was activated

modal.open
    a meaningful configured interaction surface was opened

form.start
    the visitor meaningfully began interacting with the form

form.submit_attempt
    the browser submitted the form

form.validation_failed
    a submission attempt returned normalized validation failure information

request.throttled
    the server rejected the relevant public action due to its scoped throttle

bot_protection.result
    a configured bot-protection mechanism produced a bounded normalized outcome
```

Names may be finalized as PHP constants/enums during implementation, but their meanings must not be silently broadened.

Durable completions such as `webinar registration completed` are producer facts, not browser-trusted events.

## Browser property safety

Reporting browser events may contain only bounded, non-sensitive properties required for the report.

Good examples:

```text
CTA key
form key
form field key that failed validation
normalized validation reason code
page revision
component/placement key
```

Do not send or persist:

```text
first/last name
email
phone
street address
free-form form answers
loan/application data
message body
raw validation input
full query string
signed URL/token
full referrer URL
full user agent
raw IP address
browser fingerprint data
```

A validation failure may report that `email` failed `invalid_format`; it must not report the attempted email value.

## Session and identity policy

Reporting uses anonymous first-party session correlation, not persistent visitor tracking.

Initial session policy:

```text
scope
    current first-party host only

inactivity boundary
    30 minutes

absolute maximum
    4 hours

persistent visitor identity
    none

cross-host stitching
    none

cross-domain stitching
    none

fingerprinting
    forbidden
```

The browser-side storage mechanism is an implementation detail, but it must be ephemeral and first-party. Do not set a long-lived analytics visitor cookie merely to improve return-visitor reporting.

If browser session storage is unavailable, blocked, or deliberately disabled:

```text
record eligible page-scoped observations without a Reporting session
preserve the observation as uncorrelated/page-only traffic
never synthesize a visitor identity from IP, user agent, headers, or other fingerprint material
```

Reporting must not silently attach an anonymous browser session to a Contact because an email/phone later appears in a form.

A future report may correlate Contact identity only through a deliberate producer-owned, consent-compatible business relationship and a separately documented Reporting requirement. The first Webinar funnel does not require browser-session Contact identity.

## Request identity

Operational request IDs may help diagnose a specific ingestion or registration problem, but they are not visitor identity.

If a Reporting observation retains a request correlation reference, it must be bounded and used only for short-lived diagnostics. It must not become the key used to stitch sessions across hosts or systems.

## IP address policy

Reporting does not persist raw IP addresses.

The application/network layer may use an IP transiently for security controls such as rate limiting or configured bot protection, but Reporting persistence must discard it.

Do not hash an IP and treat the hash as a privacy-safe visitor ID. A stable IP hash is still an identity-like tracking primitive and is outside the initial contract.

Existing producer tables that currently retain IP or user-agent provenance remain owned by those producers. Reporting must not ingest those values merely because they exist.

## User-agent and device policy

The full user-agent string may be parsed transiently at ingestion.

After parsing, discard the full string.

Reporting may retain only bounded coarse classifications needed for useful filtering, for example:

```text
device class
browser family
OS family
likely automation classification/provenance
```

Do not retain exact browser build strings or a high-cardinality device signature unless a later security/reporting requirement proves it necessary.

## Traffic classification

Reporting must not pretend uncertain traffic is definitively human or bot.

Initial classes:

```text
likely_human
likely_automated
unknown
```

Store enough bounded provenance to explain a classification, such as:

```text
classifier key/version
reason code(s)
confidence/bucket when the classifier supplies one
```

The exact classifier implementation may use narrow FOSS-derived device/bot parsing patterns, but no external analytics platform is required.

Browser code cannot assert `likely_human` itself.

The current classifier is the bounded server-owned `request_signals_v1` implementation. It parses the full request user agent transiently, retains only coarse device/browser/OS families, and discards the full string. Recognized browser-family syntax plus same-origin Fetch Metadata may classify as `likely_human`; explicit automation/headless/crawler request signatures classify as `likely_automated`; missing or ambiguous signals remain `unknown`. The classifier is deliberately conservative and versioned so a later parser can replace it without changing historical meaning.

Imported, page-only/uncorrelated, unknown, and likely-automated traffic remain visible in Reporting. They do not silently enter the primary likely-human conversion denominator.

## Attribution contract

Reporting stores only the minimum attribution facts required to answer the initial report.

Allowed concepts:

```text
landing path without query string
referrer host only
allowlisted UTM/source fields
bounded campaign/content identifiers
approved external click identifiers only after keyed hashing
```

Do not retain:

```text
complete referrer URL
complete landing URL with query string
arbitrary query parameters
raw advertising click identifiers
signed tokens
email/phone identifiers encoded in URLs
```

### Landing path

Store the normalized path only. Remove query strings and fragments.

### Referrer

Reduce referrer to its host/origin classification. Do not persist the full path/query.

### UTM/source dimensions

Only configured allowlisted keys are accepted. Values must be length-bounded and normalized before persistence.

The initial implementation is expected to support conventional source/medium/campaign/content-style dimensions, but the executable config is authoritative for the exact allowlist.

### Platform click identifiers

A raw external click ID is not needed for normal Reporting reads.

If a concrete external comparison or reconciliation workflow later needs one, Reporting may store a server-keyed hash of an explicitly approved identifier so repeated observations can be compared without retaining the raw value.

Do not hash every query parameter speculatively.

### Attribution model

The first Webinar funnel uses the session's landing attribution for session-based conversion reporting.

Uncorrelated/page-only observations may still be reported by their own normalized request attribution, but they remain outside the primary session denominator unless the metric explicitly says otherwise.

Do not silently switch between first-touch, last-touch, and platform-reported attribution inside one metric. A report must label the model it uses.

## Page revision

Public experience changes can materially affect conversion.

Reporting therefore supports a bounded trusted `page_revision` dimension supplied by server/config context when available.

It is a reporting dimension, not source-control identity and not arbitrary browser text.

The initial Webinar report should allow filtering by page revision so a redesign does not silently blend incompatible funnel behavior.

## Privacy and data minimization

Reporting is privacy-first by contract.

Rules:

```text
no sale of Reporting data
no covert personal-data collection
no fingerprinting
no persistent cross-session visitor profile by default
no cross-domain identity stitching by default
no raw IP persistence
no full user-agent persistence
no raw form-value persistence
no free-form Webinar answer text in Reporting
no arbitrary URL/query persistence
no copied provider payload archives
```

Privacy constraints are part of the schema contract, not optional UI settings.

Client-specific reporting configuration may narrow collection further. It must not broaden these protections silently.

## Retention contract

Initial defaults:

```text
raw interaction observations
    45 days

failure/abuse diagnostics needed for trend analysis
    90 days

session correlation token/hash
    remove once correlation is no longer required for retained raw observations/projection repair

privacy-safe daily aggregates
    25 months
```

Retention is based on the data's purpose, not on storage convenience.

Raw deletion must not race aggregation.

Before a raw observation window is deleted:

```text
all required projectors for that window must be complete
projection checkpoints must prove completion
required aggregate rows must exist or be reproducibly rebuilt from another authoritative source
```

If a projector is behind or failed, pruning must stop rather than destroy the only source needed to create the report.

Failure/abuse diagnostics retained beyond ordinary raw interaction retention must remain normalized and must not become a loophole for retaining raw personal/request data.

## First committed report: Webinar traffic and conversion

The first Reporting UI is a Webinar traffic/conversion workspace.

Required filters:

```text
date range
WebinarSeries
Webinar occurrence
source/referrer class
campaign/content attribution
device class
page revision
traffic class
```

Useful sections:

```text
traffic summary
funnel/drop-off
validation failures
source attribution
registration/provider health
confirmation/message health
join/attendance outcomes
configured question-answer distributions
```

The UI should expose uncertain/unclassified traffic rather than burying it.

## First-report denominator contract

Metric names alone are not sufficient. Every rate must have a fixed, visible denominator.

### Registration conversion

```text
denominator
    likely-human browser-observed landing sessions

numerator
    denominator sessions correlated to at least one authoritative local registration completion
```

This session-based numerator prevents repeated submissions/completions inside one session from producing conversion greater than 100%.

Uncorrelated registrations remain visible in a separate count and do not silently enter this primary conversion rate.

### Validation failure rate

```text
denominator
    form submission attempts

numerator
    submission attempts that produced one or more normalized validation failures
```

The report may additionally break failures down by safe field key and reason code.

### Provider completion rate

```text
denominator
    eligible completed local registrations

numerator
    registrations whose authoritative provider-finalization state completed successfully
```

The Webinar contributor owns the definition of eligible and authoritative finalization state.

### Confirmation planning rate

```text
denominator
    eligible completed local registrations

numerator
    registrations for which the authoritative Messaging/Webinar planning path produced the intended terminal planning outcome
```

The exact mapping of scheduled, deliberately deduplicated/consolidated, skipped, or failed planning outcomes must be resolved from the current Messaging authority during the producer-projection slice. Reporting must not infer it from copied ScheduledMessage metadata.

### Join rate

```text
denominator
    registrations whose occurrence reached its scheduled start

numerator
    those registrations with trusted join evidence
```

Do not use page hits on a join URL as authoritative join evidence when Webinars has a stronger trusted join signal.

### Attendance rate

```text
denominator
    registrations in occurrences whose attendance was authoritatively finalized

numerator
    registrations authoritatively classified as attended
```

The report should also show missed/unresolved counts. Occurrences without authoritative attendance finalization do not silently count as missed.

## Funnel visibility outside the primary denominator

The primary conversion denominator is intentionally strict.

The report must separately expose:

```text
unknown traffic
likely-automated traffic
page-only traffic with no Reporting session
local registrations with no correlatable browser session
imported/offline registrations when applicable
```

These categories are useful operationally. They simply must not be mixed invisibly into the likely-human browser conversion rate.

## Webinar registration-question reporting

Reporting may aggregate configured choice answers using only:

```text
question_key
answer_key
WebinarSeries identity
Webinar occurrence identity
question/definition version
count
```

`answer_text` remains exclusively owned by Webinars and must not be copied into Reporting.

Free-form answers are not part of the initial Reporting question-distribution feature.

Question-version identity matters so renamed/reordered answer labels do not rewrite historical meaning.

## Messaging and delivery reporting boundary

Messaging remains the source of delivery execution truth.

Reporting must use current immutable Messaging authority such as stable relationships/read services over:

```text
message/template/chain identities
delivery attempts
terminal ScheduledMessage outcomes/outbox authority
```

Do not build a second copied message-history layer inside Reporting.

In particular, do not use old broad `ScheduledMessage.payload` or metadata snapshots as historical delivery authority.

The first Webinar report needs message-health facts only to answer questions such as:

```text
Was confirmation planning completed?
How many intended confirmations reached sent/skipped/failed terminal outcomes?
Are failures concentrated in one channel/provider/reason class?
```

Provider payloads and attempt history remain Messaging-owned.

## External platform comparison

External comparison is a later slice after first-party Reporting is trustworthy.

Goal:

```text
compare first-party sessions/conversions/outcomes
against externally reported impressions, clicks, spend, or other aggregate campaign measurements
```

The external measurement layer must be provider-neutral.

A provider adapter may normalize platform dimensions into a generic measurement record. Reporting should not become Meta-specific, Google-specific, or dependent on one ad platform.

Do not use external platform identity to create a persistent Engage Core visitor profile.

## Security and ingestion controls

The public Reporting endpoint must be treated as an abuse surface.

The observation-foundation implementation must include:

```text
same-origin/public-surface validation
configured host/surface allowlisting
CSRF/origin strategy appropriate to the transport
scoped throttling
strict event-definition/property validation
hard payload/property limits
UUID idempotency/conflict handling
server-owned classification/context
safe failure responses
```

Do not let Reporting ingestion become a generic arbitrary JSON endpoint.

Failures should not echo sensitive submitted values back into logs or Reporting rows.

## Reporting configuration contract

The executable contract now lives in `config/reporting.php`. Keep these concerns bounded rather than growing an ad hoc analytics configuration surface.

Current top-level concerns:

```text
reporting.collection
    browser_enabled

reporting.session
    inactivity_minutes = 30
    absolute_minutes = 240
    bounded ephemeral token length

reporting.ingestion
    request/event/property limits
    occurred_at tolerance
    allowed sources
    per-IP and per-session public rate limits

reporting.attribution
    canonical UTM allowlist
    bounded path/host/value lengths
    explicitly approved click-ID keys and dedicated hash key

reporting.classification
    browser_classifier = request_signals_v1

reporting.retention
    raw_observations_days = 45
    diagnostics_days = 90
    daily_aggregate_months = 25

reporting.events
    versioned event definitions including surfaces, exact browser_hosts, session mode, property schema, and funnel eligibility
```

Runtime module availability remains owned by `client/[CLIENT_KEY]/config/modules.php`.

Do not add `REPORTING_ENABLED` as a competing environment toggle.

Secrets used to key any approved external click-ID hash belong in environment/secret configuration, never in client source config.

## Projection and rebuild contract

Reporting projections must be deterministic and idempotent.

Rules:

```text
projector identity is stable and versioned
re-running the same source window does not double-count
checkpoint movement occurs only after the intended write succeeds
rebuilds may replace/recompute the affected aggregate window deliberately
raw pruning cannot outrun required projections
producer facts are read through stable producer seams
```

A schema or metric-definition change that alters historical meaning should use a new metric/projector version or an explicit rebuild strategy rather than silently rewriting old rows under the same identity.

## Testing contract

Reporting implementation must prove more than happy-path dashboard rendering.

Required test classes across the phased implementation include:

```text
module dependency/boundary tests
Reporting-disabled no-op behavior
browser event-definition validation
payload/property size rejection
UUID replay idempotency and conflict handling
session inactivity and absolute expiry
page-only fallback
no raw IP/full-UA persistence
attribution normalization and query stripping
traffic classification provenance
projection idempotency/rebuild behavior
retention refusing to prune unprojected windows
Webinar denominator correctness
uncorrelated/unknown/automated traffic separation
producer-contributor boundary tests
question distribution excluding answer_text
external measurement normalization later
```

Tests should use generic fixtures. Do not make Slam Dunk, Rob, or another client name/copy part of the Reporting domain contract.

## Project State

The four current Reporting foundation tables are explicitly classified `resettable` in Project State. They are not exported/imported as durable client configuration today:

```text
reporting_sessions
    ephemeral correlation resets

reporting_observations
    privacy-limited raw observations reset during the foundation phase

reporting_daily_metrics
    aggregate transfer remains deferred until retained projections are populated

reporting_projection_checkpoints
    derived-work coordination resets
```

A later retained-history requirement may deliberately revise the aggregate transfer contract, but it must do so explicitly rather than silently treating all Reporting data as Project State.

## Existing producer privacy debt

Reporting must not normalize unrelated producer privacy debt into its own schema.

If Webinars or another module currently retains raw IP/user-agent provenance for registration, waitlist, consent, or abuse purposes, that storage remains the owning module's responsibility and should be handled in the producer persistence/privacy audit.

Reporting must not copy those values simply because they are available.

## FOSS pattern references

Mature open-source analytics projects are useful as narrow feature-shape references only.

The current architectural influences are:

```text
Snowplow
    stable event identity and schema/version validation patterns

Plausible
    constrained event properties and privacy-oriented IP-discarding patterns

Umami
    lightweight first-party privacy-focused analytics patterns

Matomo DeviceDetector
    coarse device/browser/bot parsing patterns
```

Engage Core is not adopting any of these platforms wholesale.

A FOSS feature becomes an Engage Core requirement only when a concrete workflow needs it, ownership is clear, and the smallest durable contract fits the product.

## Phased implementation

### Phase 1 — Contract lock

Complete when this document and the related TODO/roadmap references are updated.

Locks:

```text
module ownership/dependency direction
shared no-op/contributor architecture
initial table concepts
privacy/session/identity rules
attribution rules
event-definition rules
retention defaults
first Webinar report and denominator definitions
implementation phases
```

### Phase 2 — Observation foundation

Complete. Implemented:

```text
shared Reporting contracts/registry + no-op recorder
Reporting config
Reporting migrations/models
idempotent observation ingestion service
session resolution
attribution normalization
projection checkpoint foundation
setup validation
Project State policy for new tables
focused boundary/privacy/schema tests
```

No Webinar interaction instrumentation is part of this phase.

### Phase 3 — Public transport and traffic classification

Complete. Implemented:

```text
same-origin public observation endpoint
lightweight browser client
per-event exact browser-host and surface allowlisting
stateless same-origin Origin enforcement
scoped per-IP/per-session throttling and payload limits
transient user-agent parsing
coarse server-owned request-signals-v1 traffic classification
sessionStorage-only anonymous token with page-only fallback
safe non-authoritative failure responses
```

### Phase 4 — Webinar behavioral funnel

After reconciling the fresh Webinar/frontend source:

```text
landing view
CTA click
modal open
form start
submission attempt
normalized validation failures
local completion correlation
throttling outcome
bot-protection outcome
page revision/context
```

Do not make browser events authoritative for completed registration state.

### Phase 5 — Durable producer projections

Add producer contributors for the authoritative facts required by the report.

Initial producers:

```text
Webinars
Messaging
```

Initial facts:

```text
local completed registration
provider finalization
confirmation planning/deduplication/outcomes
trusted join evidence
attendance finalization
safe configured answer distributions
```

### Phase 6 — Initial Reporting UI

Build the Webinar traffic/conversion report with the committed filters, denominator labels, funnel/drop-off, validation, attribution, questions, provider health, and message health.

### Phase 7 — External platform comparison

Add provider-neutral external measurement import/adapter support only after the first-party report is stable.

## Current status

Current repository state through Phase 3:

```text
Reporting depends only on Core
four Reporting foundation tables/models are owned and registered
shared observation/event-definition seams are app-level and no-op when Reporting is disabled
idempotent normalized observation recording is implemented
host-scoped ephemeral sessions and attribution normalization are implemented
POST /_reporting/observations is the generic stateless public transport
resources/js/reporting/client.js is the generic fail-open browser client
public collection requires an event-definition surface plus exact browser_hosts match
request classification is server-owned and persists only coarse bounded results
no Webinar-specific Reporting instrumentation exists yet
no producer-fact projections exist yet
no Reporting CRM UI exists yet
current Reporting dependency cone has no detected module-boundary violations
```

Phase 4 is the first producer-specific use case: Webinars will contribute definitions/instrumentation through neutral Reporting seams without becoming a Reporting dependency and without Reporting importing Webinar internals.

## Deferred possibilities

These are not current requirements:

```text
persistent person/visitor profiles
cross-domain attribution stitching
multi-touch attribution models
revenue attribution across every module
warehouse/BI export pipelines
arbitrary custom event builders
custom SQL report builders
real-time streaming dashboards
session replay
heatmaps
full clickstream capture
raw external ad-platform event ingestion
AI-generated analytics narratives
```

Add one only when a concrete client workflow proves the value and the privacy/ownership contract remains explicit.

## Open implementation decisions

The following remain intentionally open until their concrete phases provide authoritative producer/runtime sources:

```text
exact producer-fact contributor DTO/registry shape
exact Messaging state mapping for confirmation planning success/coverage
projection/rebuild/pruning operational controls beyond the checkpoint foundation
exact external measurement dimensions/provider adapter contract
```

Do not resolve these by inventing compatibility fields or generic metadata. Resolve each against the concrete implementation source and tests when its phase begins.