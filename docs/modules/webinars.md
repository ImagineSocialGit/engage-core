# Webinars Module

## Config and token contracts

Webinar schedule profiles and post-event automation are covered by registered closed contracts.
`source_version` is numeric. `replay_available` is a supported optional post-event automation
event. The Webinar token source/context providers expose real non-sensitive model columns and
explicit computed links.

`webinar.status` is not a valid token because the `webinars` table has no such column. Waitlist
source data uses `source_page`. Join tokens, playback tokens/passcodes, provider settings, raw
provider data, and arbitrary `meta` remain excluded; join/cancel/registration/playback URLs are
available only in producer contexts that explicitly compute or supply them.


Webinars contributes the `webinar` Messaging consent domain. Exact `webinar` scope plus the
`webinar_` prefix cover Webinar-related message scopes such as `webinar_waitlist` and
`webinar_nurture`, while Messaging remains the owner of consent storage, normalization, gates,
revocation, and acknowledgement resolution.

### Canonical waitlist registration token

Waitlist availability messages use the canonical `{webinar_registration_url}` token.

The obsolete `{webinar_waitlist_registration_url}` token is not supported and must not be reintroduced.

When `WebinarMessageData` is created from a waitlist signup, `{webinar_registration_url}` resolves to the signed, contact-specific local waitlist registration URL. Do not create a second waitlist-specific registration token merely because the producer context is a waitlist signup.

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable backlog in `docs/TODO.md`.

Webinars is optional.

Webinars owns:

- webinar series
- webinars
- webinar registrations
- webinar waitlist signups
- webinar provider behavior
- webinar reminders
- webinar follow-ups
- webinar attendance recording
- webinar post-event behavior
- webinar schedule profiles and schedule profile items
- webinar contact panels

Zoom is not a module.

Zoom is an adapter used by Webinars.

Webinars may depend on:

- Core
- Messaging

Webinars may use Messaging to send registration confirmations, reminders, waitlist notices, and post-webinar transactional follow-ups. Webinar surfaces may collect consent, but consent-domain storage and consent acknowledgements are Messaging-owned.

## Provider synchronization and metadata ownership

Provider list results must carry explicit reconciliation authority through a
`ProviderWebinarSnapshot`. A title-filtered Zoom result with no exact matches is
non-authoritative because it does not prove that the provider series is empty.
Malformed or incomplete provider pagination is also non-authoritative.

A non-authoritative snapshot may import valid returned webinars, but it must not
identify local webinars as missing. An authoritative snapshot may report missing
candidates for operator review, but provider synchronization must never delete or
archive local webinars automatically. Removal is a separate, explicit workflow.

Provider-owned metadata belongs under this namespace:

```text
webinar.meta.provider.key
webinar.meta.provider.data
```

Synchronization replaces only `provider.key` and `provider.data`. Application-owned
metadata, including normalized webinar data, automation events, and locally recorded
playback or follow-up evidence, must survive provider refreshes. Zoom recording lookup
uses `provider.data.zoom_uuid`; the legacy flat `meta.zoom_uuid` key is read only as a
compatibility fallback and is removed after a successful Zoom synchronization.

## Provider cancellation reconciliation

The local Webinar registration is the source of truth for cancellation. A public
cancellation commits the local `cancelled` status, `cancelled_at`, cancellation
provenance, and scheduled-message skips before any provider request is attempted.
Provider outages must never roll back or hide that local outcome.

Provider cancellation is a durable, queued reconciliation workflow. Its state belongs
under `webinar_registration.meta.provider_cancellation` and records status, provider,
queue/provider attempt counts, timestamps, failure stage, and error class/code. Raw
exception messages and provider payloads are not persisted in this state.

The supported states are `pending`, `cancelling`, `succeeded`, `failed`, and
`not_required`. A cancellation is not required only when the Webinar has no usable
provider/external identity. A provider-backed Webinar with a missing registrant
identifier is a retryable failure, because registration synchronization may still
publish that identifier. Recent pending/cancelling claims suppress duplicate work;
stale claims may be reclaimed. Provider deletion must be idempotent, including treating
an already-absent remote registrant as success.

The CRM Webinar list exposes failed and pending provider cancellations. Operators may
requeue a failed registration without changing the already-committed customer-facing
cancellation or emitting a second cancellation event.

## Attendance reconciliation authority

Provider attendance results carry explicit reconciliation authority through
`ProviderAttendanceSnapshot`. A non-authoritative snapshot may contain valid positive
attendance evidence. Webinars applies that evidence to matched registrations, but it
must leave every unmatched registration unresolved. Only an authoritative snapshot may
finalize unmatched active registrations as missed.

Zoom participant-report responses with no participant records are non-authoritative.
Elapsed time does not convert an empty response into proof that nobody attended. Invalid
payloads, invalid participant items, and incomplete pagination are also
non-authoritative. Zoom attendance snapshots are fetched directly for reconciliation;
transient empty or incomplete results are not cached.

An explicitly authoritative empty snapshot remains representable for a provider or
reviewed workflow that can genuinely prove zero attendance. A Webinar with no
registrations may complete attendance processing without provider authority because
there is no registration outcome to classify.

Attendance reconciliation state is stored under
`webinar.meta.normalized.post_event`. The state records the provider, last check time,
record count, snapshot authority/reason, readiness, and finalization time/reason. An
unresolved result must not set `attendance_recorded_at`. CRM Webinar history exposes
unresolved reasons for operator follow-up.

Positive attended evidence takes precedence over a prior missed classification when a
later provider snapshot is reconciled. Recording an attended outcome remains idempotent
for registrations already recorded as attended.

## Post-webinar follow-up outcome accounting

Post-webinar follow-up planning must produce a durable result for every Webinar
registration before the Webinar-level `normalized.post_event.follow_ups_dispatched_at` checkpoint is written. Planning state
belongs under `webinar_registration.meta.post_event_follow_up` and records the attendance outcome,
attempt count, per-channel result, scheduled-message IDs, timestamps, and a safe failure
reason/error class/code.

The terminal planning states are `scheduled` and `not_applicable`. Cancelled
registrations, disabled outcome areas, unavailable or unaccepted channels, and Messaging
planning-gate rejections are explicitly not applicable. Missing Contacts, missing
definitions, and dispatch exceptions are `failed`; they remain retryable and visible.
An empty Messaging result therefore becomes an explained terminal outcome rather than
an unexplained success. A transient `planning` claim may be reclaimed after it becomes
stale.

The Webinar-level follow-up summary counts scheduled, not-applicable, failed,
in-progress, and unresolved registrations. The completion checkpoint is earned only
when no registration is failed, in progress, or unresolved. Replays use the existing
Messaging occurrence/dedupe identity, so already-created ScheduledMessage rows are
reused. This state accounts for planning only; Messaging continues to own delivery,
provider-send, skip, and failure lifecycle.

CRM Webinar history exposes failed planning outcomes and provides an idempotent queued
retry for one registration. Webinar-ended automation remains independent and is emitted
once even when follow-up planning still needs a retry.

## Truthful public registration completion

The public thank-you URL is temporary-signed and identifies the exact
`WebinarRegistration` created or resolved by the submission. It must verify that the
registration belongs to the requested WebinarSeries and must render the registered
Webinar occurrence rather than resolving the series' current next Webinar.

Public completion language is derived from durable registration-finalization state:

- `processing` means the local registration is saved but initial provider/message work is not complete.
- `confirmed` means initial registration finalization completed or a compatible legacy registration is already registered/attended.
- `delayed` means initial finalization failed or requires provider reconciliation; the attendee is told not to submit again.
- `cancelled` means the registration is no longer active.

Internal failure reasons, provider diagnostics, and reconciliation details are never
rendered publicly. Consent-acknowledgement-only work must not downgrade an initial
registration that was already completed. Processing pages may refresh the same signed
URL until durable state changes, but they must not claim provider confirmation or
message delivery before finalization completes.

## Public join-link interaction safety

The stable `{webinar_join_url}` points to a public GET route identified by the
registration's opaque join token. GET and HEAD requests are strictly read-only: they may
resolve whether a destination exists and render the Webinar occurrence, but they must not
record `join_clicked_at`, increment join counters, skip reminders, or redirect directly to
the provider.

The confirmation page creates a short-lived relative signed POST URL. Only that POST is a
trusted join interaction. After signature and CSRF validation, Webinars records compatible
`join_clicked_at` and `join_click_count` metadata plus structured first/last confirmation
evidence under `meta.join_interaction`, skips only pending messages whose resolved behavior
contains `skip_when_join_clicked = true`, and redirects to the provider URL.

Cancelled registrations and registrations without a usable provider/local join URL cannot
continue. Repeated valid POSTs may record repeated confirmed interactions, but the first
confirmation timestamp is preserved and reminder suppression remains idempotent. Scanner,
preview, and prefetch GETs therefore cannot manufacture attendance-like evidence or suppress
a live reminder.

## Registration finalization durability

A successful public submission commits the local WebinarRegistration, consent transitions,
and a durable `webinar_registration.meta.registration_finalization` intent before provider
synchronization or registration-message planning begins. Queue dispatch is a recoverable
handoff rather than the only record that work remains.

Finalization modes are `initial_registration` and `consent_acknowledgements`. Persisted
consent-transition identities allow a queued worker to rebuild only the acknowledgements
that became active during the committed registration transaction. Initial finalization may
plan confirmation/reminder messages only after provider synchronization is either successful
or explicitly not required.

Supported finalization states are:

```text
pending
queued
processing
completed
failed
reconciliation_required
```

Pending, stale queued, and stale processing states are recoverable through the scheduled
registration-finalization recovery job. Queue-dispatch failure records safe exception
class/code evidence and a future retry time without exposing raw exception messages. Retry
exhaustion becomes a terminal `failed` state rather than disappearing from queue history.

Provider synchronization distinguishes safe retries from ambiguous submissions. A provider
rate-limit response may be retried. A definitive client-side provider rejection is terminal.
Connection loss, timeout-like responses, unexpected exceptions after submission begins, and
stale in-flight submissions become `reconciliation_required`; they must not be automatically
posted to the provider again because the remote registration may already exist. Confirmation
planning remains blocked until that state is reconciled.

The recovery scheduler may requeue only locally recoverable finalization work. It must not
requeue terminal failures or reconciliation-required provider outcomes. Operator visibility
and manual reconciliation controls are separate CRM recovery behavior.

### Operator finalization recovery

The CRM Webinar index has a dedicated registration-recovery view. It includes upcoming or
ended webinars whenever a registration remains `failed` or `reconciliation_required` and
shows recovery controls only for those unresolved registrations. Successful registrations
must not expose retry or reconciliation actions.

A terminal `failed` finalization may be retried by an authenticated operator only when no
ambiguous provider submission remains. The retry records operator identity and prior failure
reason, resets locally safe provider failure state, and returns through the same durable queue
handoff used by initial registration.

A `reconciliation_required` registration must be checked directly in the provider before any
new submission is authorized. The operator records exactly one of two outcomes:

- **Provider registration exists:** record the provider registrant identifier and join URL,
  mark provider synchronization successful, and queue finalization to plan confirmations
  without another provider POST.
- **Provider registration is absent:** record the verification outcome, clear stale local
  provider data, authorize one safe resubmission, and queue finalization.

Both outcomes preserve operator identity, timestamp, optional notes, and the prior ambiguity
reason under finalization/provider-sync reconciliation metadata. Reapplying reconciliation
after the registration leaves the reconciliation-required state must be rejected idempotently.

## Public registration presentation and config ownership

The public Webinar registration experience is configuration-driven and may differ substantially between clients.

`WebinarRegisterPageConfig` resolves two separate presentation buckets:

```text
landing
registration
```

The public registration view passes the resolved registration content, style, and runtime tokens into the registration modal.

Registration-owned presentation includes the modal's:

```text
consent header
section headings and supporting copy
field labels/placeholders/helpers
transactional consent labels/disclosures
marketing consent labels/disclosures
legal links
registration-specific style classes
```

`consent_header` belongs to the registration content/style contract. It should not be inferred from landing-page content or restored to an older title/items shape merely because a stale test expects it.

Client copy is not a shared executable contract. Different clients may use different headings, labels, disclosures, and supporting language while still satisfying the same runtime and accessibility requirements.

### Registration consent-field contract

Registration field availability is an explicit boolean contract under the resolved registration content:

```php
'registration' => [
    'consents' => [
        'transactional' => [
            'email' => true,
            'sms' => true,
        ],
        'marketing' => [
            'email' => false,
            'sms' => false,
        ],
    ],
],
```

The four boolean leaves are required configuration decisions. Do not infer field availability from whether copy happens to exist, and do not represent availability as an empty numeric list that may merge ambiguously.

Effective presentation and acceptance are the intersection of:

```text
configured consent boolean = true
AND
Messaging channel availability exposes that channel for webinar_registrations
```

The registration modal and `StoreWebinarRegistrationRequest` must resolve the same effective client/series configuration. A consent field that is disabled by config or unavailable operationally must not render and must be rejected when manually posted. At least one effective transactional channel must remain available and selected. The phone field becomes required only when an effective SMS consent field is selected.

Current intended defaults:

```text
Core
    transactional email = true
    transactional SMS = true
    marketing email = true
    marketing SMS = true

Slam Dunk CRM
    transactional email = true
    transactional SMS = true
    marketing email = false
    marketing SMS = false

Rob the Mortgage Coach
    transactional email = true
    transactional SMS = true
    marketing email = false
    marketing SMS = false
```

These are current client decisions, not a rule that every client must share one consent layout.

### Shared registration foundation and series overrides

Registration-page configuration follows this hierarchy:

```text
config/webinars/register/content.php
    generic Core registration defaults

client/{client-key}/config/webinars/register/content.php
    shared client registration-page foundation

client/{client-key}/config/webinars/register/{series-slug}/content.php
    webinar/topic-specific overrides
```

The shared client file should own reusable page and form defaults such as registration fields, consent presentation, legal links, shared instructor identity/credentials, reviews, generic event-detail structure, common CTA defaults, and shared compliance language. A series file should contain only the positioning, proof, problem framing, urgency, topic-specific instructor framing, CTA copy, and compliance exceptions that genuinely differ for that webinar.

Topic-specific style files should normally return an empty array and inherit the shared registration style. Add a topic style override only when that series has a real visual exception.

Slam Dunk is the current reference structure for this separation. Rob keeps Rob-specific shared reviews, identity, form copy, and presentation in the shared file while Homebuyer Game Plan and VA Homebuyer Game Plan keep their topic-specific content in their own series directories. Do not collapse those topic overrides back into the shared client file.

Tests should verify:

```text
required structural keys
channel visibility and hidden-channel POST rejection
required field behavior
consent recording and accepted-channel state
accessible labels/disclosures
enabled legal links are valid absolute non-placeholder URLs
runtime rendering safety
```

Tests should not require identical prose across clients, count exact Tailwind utility strings, or make one client's presentation the canonical copy for another client.

## Webinar message/template/schedule setup

Webinars provides the owning setup surface for webinar-owned message contexts.

Messaging template presets decide what those messages say.

Webinars decide when those messages are sent and which webinar context they apply to.

The Webinars setup surface supports these lifecycle/template and consent-readiness contexts:

```text
registration confirmation
registration consent acknowledgement/readiness
reminders
waitlist availability messages
waitlist consent acknowledgement/readiness
post-attended transactional follow-up
post-missed transactional follow-up
```

For reusable Webinar lifecycle message contexts, the surface shows the current selected Messaging template, allows choosing a compatible `MessageTemplatePreset`, saves the selected `MessageTemplatePresetAssignment`, and links back to Message Templates for copy editing.

Consent acknowledgement contexts are different: they resolve through Messaging's consent-domain, opt-in-definition, and delivery-consolidation services. Do not create or select scope-specific `opt_ins` templates merely to represent registration or waitlist consent acknowledgements.

A consent acknowledgement may be delivered standalone or consolidated into a compatible Webinar lifecycle message. Webinars may show whether the acknowledgement is covered, but it does not own the acknowledgement definition or delivery policy. Schedule selection remains Webinars-owned and separate from acknowledgement copy editing.

## Webinar message readiness

Webinars owns a computed readiness service for webinar-owned message setup.

Readiness is not persisted.

Current readiness areas are:

```text
registration confirmations
registration consent acknowledgement/readiness
reminders
waitlist availability messages
waitlist consent acknowledgement/readiness
post-attended transactional follow-up
post-missed transactional follow-up
```

Readiness uses current runtime truth:

```text
Messaging DB-first reusable-template resolution with explicit supported fallback
Messaging channel availability for the surface/purpose/scope
active Webinar schedule profiles actually in use
complete schedule-profile coverage for required Webinar lifecycle messages
explicit schedule-profile disablement
missing or inactive selected schedule-profile references
conflicting active default schedule profiles
post-event outcome-message enablement
Messaging delivery-consolidation coverage and standalone fallback
```

Current states:

```text
Ready
Needs attention
Optional / disabled
```

Registration consent acknowledgement readiness is required when transactional Webinar registration messaging has at least one available channel.

Waitlist consent acknowledgement readiness is required when Webinar-waitlist marketing messaging has at least one available channel.

An acknowledgement is ready when every required intent has a valid Messaging-owned delivery path:

```text
consolidated into a compatible resolved lifecycle message
or
resolved as a standalone acknowledgement with a valid fallback
```

A zero count of standalone opt-in templates is not itself a missing-template failure when consolidation covers the acknowledgement. The UI should distinguish `Managed through delivery consolidation` from a truly missing delivery path.

The current readiness backend/surface baseline predates full delivery-consolidation presentation. Updating that presentation so it reports consolidated and fallback-covered paths accurately remains deferred product work.

When the corresponding messaging surface is unavailable, the consent acknowledgement area is `Optional / disabled` rather than a false blocker.

Consent-event acknowledgements dispatched by Messaging are Messaging-owned behavior, even when consent is collected on a Webinar surface. They resolve through the `webinar` consent domain, `ConsentOptInDefinitionResolver`, and Messaging-owned delivery-consolidation policy rather than per-scope Webinar `opt_ins` template groups. Webinars owns the registration/waitlist surface, the human-readable consent topic, and the readiness context; Messaging owns consent-domain normalization, consent-grant intent identity, acknowledgement resolution, consolidation/fallback policy, and delivery safety.

## Selectable webinar schedule profiles

Webinars supports DB-owned selectable schedule profiles for webinar-owned messages.

Schedule profiles decide whether, when, and under what Webinar lifecycle conditions messages are sent. Messaging template presets decide what those messages say and provide reusable delivery-template metadata.

Every Webinar lifecycle message dispatched through the Webinar lifecycle should get its behavior from a `WebinarScheduleProfileItem`, including immediate registration confirmations, waitlist alerts, and post-event follow-ups.

Standalone Messaging-owned consent acknowledgements are not separate Webinar schedule-profile items because the consent-granted lifecycle belongs to Messaging. When an acknowledgement is consolidated into a Webinar lifecycle message, it inherits the primary message's resolved profile timing, conditions, queue, and behavior-owner provenance rather than receiving a second profile item.

Profile items may cover categories such as:

```text
registration confirmation schedule
reminder schedule
waitlist availability schedule
post-event transactional follow-up schedule
```

Possible examples:

```text
full 10-day schedule
smoke fast schedule
last-minute only schedule
no reminders
```


Core's default Webinar cadence should remain small and vertical-neutral. The current generic baseline is:

```text
7 days
24 hours
30 minutes
live
```

Rich branded/client cadences belong in client config. Numeric/list arrays replace default lists when present, so a client reminder/profile-item list replaces the Core list rather than appending duplicate slots.

Assignments may be default/global or context-specific. The current durable selection points are webinar series and individual webinar, with individual webinar selection taking precedence over series selection.

A schedule profile item references runtime dimensions such as dispatch key, message type, channel, purpose, scope, surface, stable `message_template_key`, timing, schedule, conditions, and metadata. `source_config_path` may remain as provenance/debug location, but it is not durable template identity. The item must not embed reusable message copy.


Supported generic schedule shapes are:

```text
delay
    minutes: integer

anchored
    minutes: integer

next_day_at
    time: HH:MM
```

`next_day_at` uses `config('client.timezone')`, with application timezone fallback. Do not duplicate timezone in each schedule item. `MessageSendTimeResolver` uses an explicit anchor when provided, otherwise `triggeredAt`, as the calendar-day base.

Webinar lifecycle behavior owned by the profile item includes:

```text
timing
schedule
conditions
is_enabled
Webinar-specific skip behavior such as skip_when_join_clicked
other Webinar lifecycle flags that affect whether or when the message exists
```

Reusable Messaging templates for Webinar lifecycle messages must not duplicate those fields. A matching profile item is authoritative. If a required lifecycle template has no matching active/effective profile item, Webinars must not silently fall back to template timing or an implicit immediate send. Setup validation should report missing coverage, and runtime should safely decline the unresolved dispatch according to the Webinar contract.

Before handing a message to Messaging, Webinars resolves the active schedule profile and exact profile item. `WebinarScheduleProfileDefinitionResolver` attaches transient `resolved_behavior` and `behavior_owner` data to the matched content-only definition. `DispatchMessageAction` consumes those transient values and uses `ResolvedMessageDispatchBuilder` to combine the selected reusable Messaging template with Webinar-owned behavior. The resulting `ResolvedMessageDispatch` carries an exact `send_at`, stable logical occurrence identity when supplied, and the `WebinarScheduleProfileItem` as polymorphic behavior provenance.

Multiple reminder slots may share the same generic Messaging `message_type`, for example `message_type = reminder`. The schedule profile item key identifies the Webinar lifecycle slot, while `message_template_key` identifies the reusable Messaging template selected for that slot. `source_config_path` is provenance/debug location only and must not be used as durable matching identity. Messaging should not encode reminder timing into schedule-specific message types such as `reminder_30_minute`.

Scheduled-message payloads created by Webinars must remain compact. They should include send-ready payload fields, compact token maps, and compact context arrays. They must not include full Eloquent model arrays, loaded relationships, webinar schedule profile objects, or profile item collections. Schedule profile/source identity belongs in scheduled-message metadata.

Do not repeat the same Contact, Webinar, WebinarSeries, or WebinarRegistration snapshot under top-level payload fields, `tokens`, and `context`. Store only values required for deterministic rendering or deliberately late-bound delivery.


For post-event follow-ups, Webinars should pass `webinar.ends_at` as the schedule anchor. This keeps `next_day_at` tied to the webinar's actual ending calendar day even when provider webhook processing is delayed past midnight.

Timezone-aware send times must be normalized consistently before persistence so `ScheduledMessage.send_at` represents the same instant as the queued job delay. During production debugging, a persisted `send_at` discrepancy is not enough to prove the Redis delay is wrong: inspect Horizon `Delayed Until` and/or serialized queue delay metadata before requeueing or manipulating Redis.

Profile-owned conditions are checked when planning the message. Resolved conditions are persisted into `ScheduledMessage.meta.conditions`, and `ScheduledMessageGate` re-evaluates them immediately before provider delivery. A delayed replay follow-up must not send if a required recording/playback criterion is no longer satisfied at send time.

Webinar dispatch paths should also provide stable module-owned occurrence identity. Registration messages, waitlist notices, and post-event follow-ups should use stable logical occurrence keys based on the owning Webinar records/context rather than treating `send_at` as identity. A retry or recalculated timestamp for the same logical message occurrence should retain the same occurrence identity.


Webinar schedule profiles and profile items are DB-owned definitions with customization semantics.

Normal preset sync updates config-owned records that have not been customized, preserves customized profiles and items, and deactivates stale non-customized items that are no longer present in config. Stale customized items remain preserved. Explicit force sync may overwrite customized profiles/items and clears their customization markers.

At most one active default Webinar schedule profile should exist. Config sync must reject multiple active defaults and duplicate normalized item keys before persistence. Shared setup validation should also treat conflicting active defaults as a hard setup error for manually altered or otherwise corrupted DB state.


## Webinars setup validation ownership

Webinars contributes Webinars-owned setup checks through `WebinarsSetupValidationContributor` to the shared app-level setup validation manager.

Webinars validation uses Webinars-owned schedule/profile definitions and public Messaging validation/resolution seams rather than duplicating Messaging internals. Missing compatible Messaging definitions are hard errors; a valid definition whose channel is unavailable for the surface is a warning.

At minimum, validate:

```text
selected webinar schedule profile exists or a valid default fallback exists
schedule profile item keys are unique within a profile
schedule/timing definitions are valid
`next_day_at.time` uses strict `HH:MM` and does not embed timezone
schedule profile items reference supported channel/purpose/scope/surface/message context
every required Webinar lifecycle template/context has matching effective schedule-profile behavior
reusable Webinar Messaging templates do not duplicate schedule-profile-owned timing, schedule, conditions, enablement, or Webinar-specific skip behavior
selected Messaging template assignments are compatible and resolvable
Webinar consent-domain acknowledgement resolution is unambiguous and does not require per-scope `opt_ins`
required webinar available fields/tokens are supplied by the actual webinar runtime path
runtime-only URLs such as join/cancel/playback URLs are available for the context that uses them
schedule/profile identity remains metadata and does not leak full model graphs into scheduled-message payloads
```

A required selected schedule item or template context that cannot execute safely is a hard error. Optional disabled schedule items or intentionally omitted channels may be warnings or omitted depending on operator usefulness.

Post-webinar transactional follow-ups are not campaign nurture.

They may contain replay/recording links and should use:

    purpose = transactional
    scope = webinar
    dispatch_key = webinar_ended

Post-webinar nurture campaigns are marketing journeys and should be handled through Campaigns after FlowRoutes enrollment.

They should use:

    purpose = marketing
    scope = webinar_nurture


Webinars should not directly own Campaign enrollment routing.

Webinars should not directly create CampaignEnrollment records.

Webinars should not transition Workflow status solely to trigger Campaign enrollment.


Current outcome direction:

1. Webinars records webinar registration/attendance/outcome state.
2. Webinars resolves profile-owned lifecycle behavior for messages such as confirmations, reminders, waitlist alerts, and replay follow-ups, then hands the resulting dispatch intent to Messaging through the shared resolved-dispatch seam. Messaging-owned consent-domain acknowledgements remain outside Webinar schedule profiles and per-scope reusable Webinar templates.
3. Webinars emits `AutomationEventRecorded` for automation-worthy outcomes.
4. FlowRoutes listens to the generic automation event seam.
5. FlowRoutes maps generic automation events into `FlowRouteExternalEvent` internally.
6. FlowRoutes starts matching event-triggered routes or resumes matching `event_wait` points.
7. FlowRoutes decides whether to create tasks, change status, enroll Campaigns, cancel Campaigns, or send messages.
8. Campaigns owns Campaign enrollment/progression.
9. Messaging owns delivery/scheduling.

Current webinar automation events:

    webinar.registered
    webinar.cancelled
    webinar.attended
    webinar.missed
    webinar.ended

`webinar.ended` may be contactless.

Contactless automation events should not force contact FlowRoute progress to resume unless a contact context exists.

Good:

    DispatchMessageAction
    ScheduleMessageAction
    AutomationEventRecorded
    AutomationEventData

Bad:

    CampaignEnrollment::create(...)
    ScheduledMessage::create(...)
    Webinars directly deciding Campaign route orchestration

Webinar registration records should store webinar participation state, registration source, join token, and webinar-specific metadata.

Consent audit details such as IP address, user agent, opt-in language, and opt-in timestamp belong to Messaging consent records, not Webinar registration records.

Webinar outcome fields such as `registered_at`, `attended_at`, and `cancelled_at` belong to Webinars.

Those outcome fields are emitted through `AutomationEventRecorded`, then FlowRoutes maps them into `FlowRouteExternalEvent` internally when needed.

Webinars should not decide Campaign, Workflow, task, or FlowRoute orchestration directly.


## CRM visibility

Webinars may contribute CRM visibility through module-owned providers and views.

Current expected surfaces:

```text
Dashboard context panel for useful webinar activity.
Contact show webinar history panel.
```

These surfaces should summarize webinar activity in business terms and hide empty passive context where appropriate. They should not make Webinars decide Campaign, Workflow, Task, or FlowRoute orchestration.

## Routes webinar usage

Webinar outcomes should continue to emit neutral automation events such as:

```text
webinar.registered
webinar.attended
webinar.missed
webinar.ended
```

Routes / Assignments UI should translate those event keys into human-readable activity labels, such as:

```text
Someone registers for a webinar.
Someone attends a webinar.
Someone misses a webinar.
```

Raw event keys may be shown as secondary diagnostic metadata when useful.

A webinar outcome may start more than one independent selected Route for the same automation event. For example, one Route may change status while another starts a Campaign.

Webinars must remain the event producer and must not import FlowRoutes or Campaigns to orchestrate those consequences directly.

## Production post-event operational sequence

Use the following order when recovering or validating a production webinar follow-up flow:

```text
1. Verify Zoom capabilities required by the current provider implementation:
   registration/lookup as applicable, attendance reporting, and cloud recording lookup/access.
2. Verify attendance state.
3. Resolve duplicate/cancelled registration conflicts before follow-up dispatch when necessary.
4. Retry only the failed post-event provider job.
5. Confirm Webinar.playback_url contains the real recording URL.
6. Confirm follow_ups_dispatched_at is populated.
7. Inspect the actual ScheduledMessage rows.
8. Verify real replay URL, expected CTAs/links, recipient eligibility, statuses, and send timing.
9. Inspect Horizon Delayed Until and/or serialized queue delay metadata before touching Redis.
10. Restart Supervisor-managed Horizon after queued-job runtime code changes.
11. Surgically retry only the affected skipped/failed messages.
12. Verify final message statuses.
```

Zoom capability requirements should follow the current provider calls and the dedicated production/provider setup checklist; do not assume basic webinar access also grants participant-report or cloud-recording access.

Do not use broad ScheduledMessage resets, queue flushes, or destructive Redis commands as the normal recovery path for a narrow post-event failure.

## Dev/staging testing tools

Webinars may expose local/staging-only CRM testing tools to help operators/developers verify webinar messaging, join-click behavior, attendance outcomes, replay URLs, post-event follow-ups, and downstream FlowRoute behavior without relying on Zoom.

These tools should live behind Webinars-owned CRM controllers such as WebinarDevController and should not be available in production.

Dev testing actions may:
- list available Messaging definitions for a webinar registration;
- force-send selected confirmation/reminder definitions through Messaging public actions;
- simulate join-click behavior through the normal Webinars join resolver;
- mark one registration attended or missed and emit the normal contact-scoped automation event;
- set or clear a fake replay URL;
- dispatch post-webinar follow-ups through the normal post-event action.

Dev testing actions should still use public module seams:
- Webinars uses DispatchMessageAction for message sends.
- Webinars emits AutomationEventRecorded for attended/missed outcomes.
- Webinars does not directly create Campaign enrollments, FlowRoute progress, or ScheduledMessage rows.

The dev UI should behave like an operator console. Actions inside testing modals should use AJAX/fetch where practical so the modal, selected registration, loaded message options, and activity log are not lost after each action.

Sim Join should skip already-queued live reminders when the real definition has skip_when_join_clicked enabled. Manual dev sends are forced sends and may still send a selected reminder afterward so the exact payload can be tested.
===== END FILE: docs/modules/webinars.md =====

===== BEGIN REPLACE FILE: docs/TODO.md =====

# Engage Core TODO

## Config generation lock-in

- [x] Freeze Slam Dunk effective config and representative runtime behavior as golden fixtures.
- [x] Add shared closed config-contract primitives and register foundational, Messaging,
  Campaigns, FlowRoutes, and Webinars contracts.
- [x] Add token source/context contracts based on real columns and explicit computed providers;
  exclude arbitrary metadata and sensitive/raw values.
- [x] Prove the broad `test_everything` package sync and zero-finding setup validation.
- [ ] Run registered closed config contracts from `setup:validate` so structural rules are not
  duplicated across tests and contributor code.
- [ ] Add closed contracts for complete file envelopes, reference keys, conditional objects, and
  producer-owned campaign start payloads.
- [ ] Add strict reference and token closure validation for every exported definition.
- [ ] Generate field/token tables and contract-derived authoring references in CI.
- [ ] Build a minimal deterministic exporter and semantic round trip using Slam Dunk as the first
  full-package fixture.
- [ ] Build preview and authoring UX as consumers of the same registries and strict validator.

Detailed sequencing and open decisions are in
[`config-generation-lock-in-roadmap.md`](config-generation-lock-in-roadmap.md).

This file is intentionally disposable. Add work here when it is real but not yet ready for an implementation slice. Delete items as they are completed. Do not treat this as an architectural reference; long-lived decisions belong in `module-boundaries.md` or a feature-specific doc.

## Immediate system-wide persistence audit

- [ ] Execute [`model-persistence-bloat-audit.md`](model-persistence-bloat-audit.md) before treating compact persistence as verified.
- [ ] Build a complete inventory of model/query-builder write paths, including actions, services, jobs, listeners, sync/import code, factories used by runtime helpers, provider ingestion, and bulk updates.
- [ ] Begin with `ScheduledMessage` and Webinar message-data producers.
  - Measure actual row size.
  - Remove repeated Contact/Webinar/WebinarSeries/WebinarRegistration snapshots across top-level payload fields, `tokens`, `context`, and metadata.
  - Preserve provider-ready content, retryability, dedupe/occurrence identity, consent IDs, consolidation intent keys, conditions, and useful delivery provenance.
- [ ] Audit Campaign, Broadcast, FlowRoutes, Task, automation-event/opportunity, Forms, Documents, Commerce, inbound/provider, and reporting persistence using the same worksheet.
- [ ] Add persistence-contract and size-budget tests only after measuring representative real rows.
- [ ] Define retention/archive policy separately from payload-shape cleanup; do not prune compliance evidence or retry state merely to reduce size.

This is the next architecture audit, not deferred UX backlog.

## Run through after completing an item or system update

These are repeatable checklists. Run the relevant checklist after a production slice, config update, client setup change, or staging deployment. Do not leave one-off feature work here; put one-off work in the backlog sections below.

### UI Rules

- [ ] Use `docs/ui-ux-guide.md` when reviewing or refactoring client/operator-facing screens.
- [ ] Apply the “no what did I get myself into?” test to every client-facing screen.
  - The page should make the next action obvious before exposing module detail.
  - The page should avoid platform-cockpit sprawl: too many modules, widgets, logs, builders, raw keys, and settings at once.
  - Powerful features should default to summaries, presets, guided choices, and consequence previews.
- [ ] Continue Routes / FlowRoutes product-completion work from the implemented baseline.
  - Treat contextual Automation Opportunities as the discovery layer and Routes as the control center.
  - Preserve the locked linear Route product boundary; do not add arbitrary branching, joins, nested branch trees, connectors, generic node-editor behavior, or arbitrary jump-back loops.
  - Keep Manage Routes focused on what a Route does and Assignments focused on when it runs.
  - Preserve the current server-authoritative placement policy: Wait cannot be terminal; Change Status must be terminal.
  - Preserve explicit direct-Route Messaging eligibility; do not expose every active Messaging template.
  - Use the existing FlowRoutes-owned `ContactStatusAutomationImpactResolver` as the backend source of truth for manual-status-change consequence previews.
  - Remaining high-value gaps: new Route creation, duplication, activate/deactivate, trigger changes, clone Point from another Route, task assignment/default authoring, business-day/business-hour waits, manual-status warning UX, and contextual suggestion UX.
- [ ] Apply the AJAX/preserve-context UI pattern to other CRM row/panel/modal workflows where page reloads would frustrate operators.
  - Tasks complete/reopen/cancel/archive.
  - Broadcast recipient/detail actions where applicable.
  - Campaign enrollment controls where applicable.
  - FlowRoute selection/testing controls where applicable.

### After each production code slice

- [ ] Run focused tests for the modules touched by the slice.
  - Broadcasts.
  - Messaging.
  - Core contact filters.
  - Campaigns, FlowRoutes, Tasks, Webinars, or Modules when touched.
- [ ] Run broader adjacent-module tests before committing when module boundaries are involved.
- [ ] Confirm production code changes are architectural fixes, not test-shaped legacy preservation.
- [ ] Confirm no new direct cross-module model/table writes were introduced where a public action/service should be used.
- [ ] Confirm migrations are replacements, not modify-table migrations, while this branch remains pre-rollout.
- [ ] Run `php artisan optimize:clear` after config, route, provider, or view changes.
- [ ] Update docs only when the architecture or operator/client behavior changed.

### After each config or client-template update

- [ ] Confirm every config key is supported by the relevant template, guide, or feature doc.
- [ ] Confirm client copy uses documented tokens only.
- [ ] Confirm Messaging copy passes `MessageTemplateTokenValidator` for the exact producer context; do not use a global token allowlist.
- [ ] Confirm runtime-only URLs/tokens are not guessed or hard-coded in static config.
- [ ] Confirm Campaign presets do not own reusable message payload/copy.
- [ ] Confirm campaign variants reference Messaging-owned template presets/assignments when variant architecture is used.
- [ ] Confirm Campaign preset step message references use first-class `channel`, `purpose`, and `scope` keys.
- [ ] Confirm Messaging templates live under the expected channel/purpose/scope path.
- [ ] Confirm Webinar Messaging definition files do not reintroduce per-scope `opt_ins`; consent acknowledgements should resolve through Messaging consent domains.
- [ ] Confirm message scopes map to intentional consent domains and unknown scopes remain narrow.
- [ ] Confirm `next_day_at` schedules use strict `HH:MM` and client timezone rather than embedding timezone.
- [ ] Confirm delayed lifecycle conditions remain available for send-time revalidation.
- [ ] Confirm MessageTemplatePreset sync/assignment rules are preserved when DB-backed templates are involved.
- [ ] Confirm Task presets create DB-owned task template definitions only and do not create live tasks.
- [ ] Confirm FlowRoute presets use public action/service/capability references rather than private module internals.
- [ ] Confirm SMS visibility is controlled by config where the surface exposes channel choices.
- [ ] Confirm missing optional content/style keys do not break public pages.
- [ ] Confirm tests are tolerant of client copy changes unless exact copy is the behavior under test.
- [ ] Confirm unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
- [x] Classify config validation findings as hard errors or warnings.
- [x] Confirm hard errors block staging/client handoff.
- [x] Confirm warnings give useful operator/debug guidance without blocking safe runtime behavior.
- [ ] Confirm client config overrides preserve unspecified nested defaults where fallback is expected.
- [ ] Confirm numeric/list overrides intentionally replace default lists where that is the current merge contract; verify client reminder/profile lists do not append duplicate Core slots.
- [ ] Confirm any client-selected preset package exists in effective merged `presets.packages`; keep rich vertical/client packages in client config rather than Core.

### After each permission-invitation update

- [ ] Confirm the invitation remains email-only for the one-time bypass send.
- [ ] Confirm normal Broadcasts do not receive imported-contact bypass behavior.
- [ ] Confirm SMS opt-in remains explicit and requires a phone number when SMS is selected.
- [ ] Confirm already accepted or previously claimed/sent invitations cannot create duplicate consent rows or resend through the bypass.
- [ ] Confirm the public preference URL is injected at runtime before provider send.
- [ ] Confirm accepted consent scopes match `messaging.permission_invitations.consent.scopes`.
- [ ] Confirm client copy can change without breaking behavioral tests.

### After each SMS/channel-visibility update

- [ ] Confirm SMS provider/runtime code remains present.
- [ ] Confirm SMS is hidden from client/admin UI when disabled for that surface.
- [ ] Confirm hiding SMS does not disable backend protections.
  - Consent gates still enforce SMS rules.
  - Suppression/revocation still works.
  - Inbound STOP/HELP handling still works if provider/webhook is active.
- [ ] Confirm SMS appears only on explicitly enabled surfaces.
- [ ] Confirm permission invitation SMS opt-in remains explicit.

### Before committing a feature slice

- [ ] Review changed files for stale terminology. Internal/runtime identifiers should use `contact`; client-facing UI/copy may use the configured business noun. Also check `audience` vs `recipient` and other module-specific naming drift.
- [ ] Confirm newly added public routes/controllers follow module directory conventions.
- [ ] Confirm feature-specific docs or `TODO.md` were updated when useful.
- [ ] Confirm `module-boundaries.md` was updated only for long-lived architectural decisions.
- [ ] Confirm temporary TODO items were deleted or moved into the one-off backlog below.

### Before staging smoke tests

- [ ] Run focused tests and adjacent-module tests locally.
- [ ] Run `php artisan migrate` against staging only after confirming migration shape is final for that branch.
- [ ] Run `php artisan optimize:clear` on staging.
- [ ] Confirm module visibility and navigation match config.
- [ ] Confirm provider credentials/config are present for enabled providers.
- [ ] Confirm queue workers/Horizon are running if scheduled/send behavior is being tested.

### Roadmap tracking

- [ ] Keep `docs/client-readiness-roadmap.md` current as the focused client-readiness implementation roadmap.
  - Use `TODO.md` for disposable backlog/checklists.
  - Use the roadmap for implementation order and session planning.
  - Use the pre-prod schema-discovery ordering lens until schemas are stable enough for production rollout.
  - Prioritize remaining work by DB/schema impact first, code/module-seam impact second, and UI/UX polish third.
  - Do not treat roadmap items as temporary MVP shortcuts.
  - Delete completed roadmap items or move them back to TODO when they are no longer near-term.

## Pre-prod schema-discovery phase tracking

Use this as a disposable checklist mirror of the roadmap sequence. Keep the roadmap as the source of truth for implementation order and update this section as phases are completed, deferred, or split.

- [x] Phase 1 — Webinar schedule profiles.
  - DB-owned profiles/items exist.
  - Series/webinars can select profiles with default fallback.
  - Webinars owns timing/slot identity.
  - Messaging owns reusable copy/templates.
  - Schedule/profile/template identity belongs in meta. A fresh registration runtime dump showed broader payload/token/context duplication, so compactness remains under the immediate persistence audit.
- [x] Phase 2 — Campaign channel variants.
  - DB-owned campaign step variants exist.
  - Campaign enrollment is lifecycle.
  - Campaign step is the business moment.
  - Campaign step variant is the channel-specific delivery option.
  - `send_all_eligible` schedules multiple eligible variants.
  - `dependency_aware` is hardened for same-enrollment/same-step sibling variant states.
  - Supported dependency states include scheduled, pending, sent, skipped, failed, terminal, and unavailable.
  - Dependency checks consider same-pass scheduled siblings and persisted ScheduledMessage records.
  - Dependency checks are scoped to the same campaign enrollment, same campaign step, and required variant key.
  - Preset sync creates variants, removes stale non-customized variants, preserves customized stale variants, and protects customized campaigns.
  - Campaign/step/variant/template identity belongs in meta. Campaign rows must be measured during the system-wide persistence audit rather than assumed compact.
- [x] Phase 3 — Task templates / task defaults.
  - Audit `task_templates` table/model shape for generated/manual tasks.
  - Confirm FlowRoutes `create_task` points can reference `TaskTemplate` records.
  - Confirm task templates can define title/body/default due offsets/assigned_to/responsible_party and the then-current related-subject rules. Phase 12 supersedes the relationship shape with TaskLinks.
  - Confirm task templates are generic enough for PetServices, Mortgage, Music, Webinars, Documents, Scheduling, etc.
  - Confirm task template preset sync creates DB-owned default task templates only and does not create live tasks.
  - Confirm customized templates are preserved.
  - Decide whether direct template reference is enough or whether template assignment/selection is needed.
  - Build task template UI only if needed.
- [x] Phase 4A — FlowRoutes relationship, capability, and instance-plan audit.
  - Map FlowRoutes against current universal modules: Messaging, InboundMessaging, InternalNotifications, Tasks, Workflow, Campaigns, Broadcasts, Webinars, Reporting, Scheduling, Portal, Forms, Documents, Commerce, Location.
  - Map FlowRoutes against vertical/planned vertical modules: Mortgage, PetServices, Music.
  - Decide which modules produce automation events.
  - Decide which modules expose public actions FlowRoutes may call.
  - Decide which modules contribute point handlers.
  - Decide which modules contribute route presets.
  - Decide which modules contribute task templates.
  - Decide which modules contribute capability metadata/labels.
  - Decide which modules expose records that routes can be scoped to through subject morphs.
  - Decide how FlowRoutes knows which point types/capabilities/labels are available without importing modules directly.
  - Decide whether provider/registry/config is enough or DB-owned capability/binding tables are needed.
  - Decide whether `ContactFlowRouteProgress` needs `subject_type` / `subject_id`.
  - Decide whether reusable FlowRoute templates should seed contact/subject-specific route plans.
  - Decide whether active route plans need plan item snapshots so template edits do not unexpectedly change live instances.
  - Decide whether operators can insert/repeat/skip/cancel route instance plan items for one contact/subject.
  - Decide how event waits, task completion, appointment completion, document completion, etc. resume specific plan items.
  - Audit conclusion at that phase: subject-scoped route instances, route instance plans/items, progress/execution items, capability catalog/bindings, and durable created-artifact tracking/correlation were required before production. Phase 12 revises the Tasks-specific direct provenance coupling.
- [x] Phase 4B — FlowRoutes schema hardening.
  - Added `subject_type` / `subject_id` to `contact_flow_route_progress`.
  - Added `contact_flow_route_plans`.
  - Added `contact_flow_route_plan_items`.
  - Added `contact_flow_route_progress_items`.
  - Added `flow_route_capabilities`.
  - Added `flow_route_capability_bindings`.
  - Added direct FlowRoutes provenance fields to Tasks, ScheduledMessages, and CampaignEnrollments at that phase. Phase 12 intentionally removes the Tasks-specific structural dependency and keeps correlation in FlowRoutes-owned state.
  - Hardened blocked/cancelled/superseded runtime behavior so open plan/progress items do not remain successful-looking or resumable incorrectly.
  - Normalized route wait/resume metadata and automation-event started_at fallback behavior.
  - Added full structured FlowRoutes provenance to task-completed automation/debug paths where applicable.
  - Added producer-level provenance tests and boundary guardrails for FlowRoutes internals.
  - Superseded by the Phase 12 boundary decision: future modules should preserve created-artifact correlation without automatically copying FlowRoutes foreign keys into every artifact-owning module.
  - Kept module-owned business behavior behind public actions/services/contracts.
  - Deferred polished Route Management UX and CRM provenance/debug views at that phase; a first Routes editor baseline has since been implemented.
- [x] Phase 5 — FlowRoutes event-wait / task-completed resume implementation.
  - Resumes from neutral `task.completed` `AutomationEventRecorded` events.
  - Keeps Tasks independent from FlowRoutes.
  - FlowRoutes listens to generic `AutomationEventRecorded` and resumes matching event_wait/progress/plan/progress items internally.
  - Does not rely on contact-only fallback for task-completed waits.
  - Supports unambiguous route-created Task artifact matching.
  - Supports explicit event_wait correlation for routes that may create multiple tasks.
  - Covers the real CompleteTaskAction → TaskCompleted → AutomationEventRecorded → FlowRoutes listener chain.
- [x] Phase 6 — Config validation / setup validation. Complete; 6A–6E are green.
  - [x] Phase 6A — Documentation audit and contract normalization.
    - Docs define authoritative terminology, config ownership, validation ownership, severity direction, extension seams, and source-of-truth rules.
    - Internal/runtime identifiers use `contact`; client-facing UI/copy may use configured business nouns.
  - [x] Phase 6B — Config normalization.
    - Normalized preset groups/definitions, Messaging definitions, Webinars config, reference registries, token contexts, and canonical contact terminology.
    - Removed stale legacy keys and schedule-specific shared message-type drift.
  - [x] Phase 6C — Schema/model audit.
    - Completed ContactStatus customization contract.
    - Completed TaskTemplate defaults/durability and live Task template identity.
    - Completed FlowRoute capability source of truth.
    - Completed FlowRoute current-version/revision contract.
    - Completed live route-instance reconciliation and plan revision history.
    - Completed Campaign schema/variant/enrollment ownership audit.
    - Completed Messaging template/authoring identity and stale config-owned preset reconciliation.
    - Completed Messaging validation seam audit; no additional schema needed for validation.
    - Completed Webinar schedule-profile customization/default uniqueness contract.
    - Completed global preset orchestration reconciliation.
    - Fresh migrations passed.
    - Global `presets:sync` passed.
    - Focused sync/durability tests passed.
    - Adjacent module/runtime boundary tests passed.
    - Broader end-phase test sweep passed.
    - No additional schema additions are recommended before Phase 6D.
  - [x] Phase 6D — Contributor-based validation/runtime code.
    - Add a central `SetupValidationManager` orchestrator.
    - Add registered module/app validation contributors.
    - Reuse/adapt existing validators such as Messaging `MessageConfigValidator`.
    - Return structured findings with severity, code, message, source, path, module, context, and compact diagnostic meta where useful.
    - Make the same seam reusable by CLI now and future authoring/readiness UI later.
    - Validate Task presets and TaskTemplate references.
    - Validate FlowRoute presets, point types, capability references, handler/module availability, route graphs, and route-instance assumptions.
    - Validate Campaign references, variants, strategies, and dependency rules.
    - Validate Messaging/template references and available field/token context.
    - Validate Webinar schedule-profile integrity and conflicting active defaults.
    - Validate vertical references and unsupported point/module combinations.
    - Treat invalid/impossible/unsafe selected runtime behavior as a hard error.
    - Treat safe-but-dormant/unused/surprising behavior as a warning.
    - Make hard errors fail the command and block staging/client handoff; warnings remain non-blocking and actionable.
    - Validate reference registries for drift without treating stale registries as the sole runtime truth.
    - Do not persist validation findings/history unless a real operator workflow requires it.
  - [x] Phase 6E — Validation tests and final handoff coverage.
    - Focused setup-validation suite is green.
    - Adjacent Campaigns, FlowRoutes, Messaging, Tasks, Webinars, Workflow, module-boundary, and client-fallback regression coverage is green.
    - Broader default-preset/client fallback coverage is green.
    - Final docs/handoff reconciliation completed.

- [x] Phase 7 — Permission invitation accepted automation event.
  - Accepted invitations emit neutral `permission_invitation.accepted` events.
  - Acceptance locks/rechecks the invitation row inside a transaction for idempotency.
  - SMS phone updates, consent creation, and invitation accepted state are committed together.
  - The event emits only after the acceptance transaction succeeds.
  - Already accepted invitations do not emit the event again.
  - Messaging remains independent from downstream consumers.
  - No schema change was required.
- [x] Phase 8 — Permission invitation cancellation / skip / failure bookkeeping.
  - Pre-claim skips/cancellations create no invitation row. Post-claim scheduled-message skips reconcile matching claimed invitations to failed. Provider/runtime failures remain failed across delivery and invitation state. No schema change or new invitation statuses were required.
- [x] Phase 9 — Webinar message readiness check.
  - Added computed readiness visibility for registration confirmations, registration opt-ins, reminders, waitlist alerts, waitlist opt-ins, and post-event follow-ups.
  - Readiness uses runtime Messaging resolution, channel availability, active schedule-profile effects, explicit disablement, selected-profile validity, active-default conflicts, and post-event outcome-message enablement.
  - Readiness is not persisted.
- [x] Phase 10 — Manual status-change automation warning foundation.
  - Added plural selected FlowRoute resolution for ContactStatus triggers.
  - Added a read-only `ContactStatusAutomationImpactResolver` reporting whether automation would run and which selected routes are involved.
  - Inactive bindings/routes are ignored.
  - Preview resolution does not start route progress or mutate Workflow/Contact state.
  - No schema, controller, or Blade changes were required.
  - The actual operator warning/confirmation UX remains part of Phase 11.
- [ ] Phase 11 — Automation Opportunities + Routes / FlowRoutes product completion.
  - [x] Automation Opportunities backend foundation complete for the current producer/evidence slice.
  - [x] Route Management product-completeness audit complete.
  - [x] Global `Point` model/table/template layer removed.
  - [x] `FlowRoutePoint` now directly owns concrete action/wait/condition type and configuration.
  - [x] Route authoring direction chosen: Route-centric concrete `FlowRoutePoint` creation with clone-only reuse from another current Route.
  - [x] Module-first preset contribution architecture implemented.
  - [x] `PresetContributionRegistry`, `PresetPackageResolver`, `PresetCompositionResolver`, and `ResolvedPresetDomain` are the shared preset-composition seams.
  - [x] `Routes -> Manage Routes / Assignments` information architecture implemented.
  - [x] Business-language Route cards and one-step Automatic Behavior presentation implemented.
  - [x] Existing Route editing implemented in a modal.
  - [x] Existing Point editing implemented in a modal.
  - [x] Current authorable Point subset implemented: Wait, Change Status, Create Task, Send Message, Start Campaign, Stop Campaign.
  - [x] Drag-and-drop ordering with explicit `Save order` implemented.
  - [x] Move up/down fallback controls retained.
  - [x] Direct Remove actions retained.
  - [x] Stop Campaign is contextually hidden until the Route already contains Start Campaign.
  - [x] Direct Route message-template eligibility is explicit opt-in through `meta.route_authoring.eligible = true`; internal-purpose templates remain ineligible.
  - [x] Linear Route product boundary locked: no arbitrary branching canvas, joins, nested branch trees, connectors, generic node editor, or arbitrary jump-back loops.
  - [x] Point placement policy implemented and enforced server-side:
    - Wait cannot be terminal.
    - Change Status must be terminal.
    - Add/remove/move/reorder validate the proposed resulting sequence.
  - [x] Placement UX guardrails implemented:
    - Terminal Change Status has no drag handle.
    - Invalid move controls are disabled.
    - Removing the Point after a Wait is disabled when it would leave Wait terminal, with explanatory hover/focus text.
    - Invalid terminal Wait drag feedback is local to the terminal position rather than a page-level warning.
  - [ ] Continue focused Routes product work.
    - New Route creation.
    - Route duplication.
    - Activate/deactivate.
    - Trigger changes.
    - Clone Point from another current Route without shared linkage.
    - Task assignment/default authoring inside create-task Point UX.
    - Business-day/business-hour waits.
    - Manual status-change consequence warning UX.
    - Contextual Automation Opportunity suggestion UX.
    - Simple future Point eligibility / Route continuation rules only if they remain linear and understandable.
- [x] Phase 12 — Standalone and multi-link Tasks. Complete and full-suite green.
  - [x] Audit current Task schema, models, creation paths, UI assumptions, notifications/digests, automation events, FlowRoutes integration, tests, and docs.
  - [x] Lock Task mental model as independent dimensions: template/no-template, zero-to-many domain links, manual/automation origin.
  - [x] Enforce no-template -> manual only; automation-created -> template-backed.
  - [x] Replace the single `related` morph with zero-to-many `task_links`.
  - [x] Implement generic TaskLink roles: `subject`, `context`, `result`.
  - [x] Replace `TaskTemplate.related_subject` with `TaskTemplate.link_defaults` using generic `current_contact` / `current_subject` creation context.
  - [x] Preserve unlinked Tasks and Contact-linked Tasks through TaskLinks.
  - [x] Prove non-Contact Task linking with Appointment-linked coverage.
  - [x] Support multi-link Tasks and links that may grow over time, including `result` links.
  - [x] Add Tasks-owned linked-record presentation resolver/provider seams and safe fallback behavior.
  - [x] Keep linked modules responsible for presenting their own records without Tasks importing optional module models.
  - [x] Add dedicated Task index and Task show routes/controllers/views.
  - [x] Keep Task workspace valid for zero, one, or many links.
  - [x] Generalize Contact-show and dashboard Task rendering around TaskLinks without including unrelated Tasks.
  - [x] Enforce Core-only Task operation for creation/lifecycle/templates/links/index/show/events.
  - [x] Move TeamMember/InternalNotifications assignment, assignee options, recipient resolution, and notification scheduling behind optional contributed seams.
  - [x] Keep notification copy/CTA behavior valid for unlinked Tasks and digests independent of any specific linked module.
  - [x] Remove Task-owned FlowRoutes foreign keys/model imports.
  - [x] Preserve FlowRoutes-created Task identity/correlation through FlowRoutes-owned progress state and neutral Task events.
  - [x] Require `task_template_key` for automatic `create_task` Points.
  - [x] Update Task completion event payload/context for TaskLinks while preserving valid contactless events.
  - [x] Make repeated similar manual no-template Tasks the primary generic Task-created Automation Opportunity signal.
  - [x] Preserve useful Contact-specific compound opportunity behavior through TaskLinks/public linked-record context.
  - [x] Update Task setup validation/config contracts for link defaults, roles, sources, and assignment strategy resolution without optional-module imports.
  - [x] Add focused tests for unlinked, Contact-linked, Appointment-linked, multi-link, template/manual/automation invariants, workspace surfaces, optional integrations, setup validation, events, and opportunities.
  - [x] Complete expanded FlowRoutes modularity refactor: contributor-owned Point definition schema/validation, neutral business-action execution, generic FlowRoutes action adapter, and module-owned authoring UX.
  - [x] Remove central Tasks/Messaging/Campaigns Point-definition/validation/authoring switchboards from FlowRoutes.
  - [x] Make normal `create_task` Route authoring template-required and explicitly explain repeated automatic creation vs one-time Task creation.
  - [x] Run focused and full automated test suites after 7A and 7B; green.
  - [x] Run final docs/config audit and reconcile stale Phase 12/FlowRoutes architecture language.
- [ ] Phase 13 — Dashboard / contact workspace polish audit.
  - Review orientation surfaces after core runtime pieces settle.
  - Add persisted preferences/acknowledgements only if proven necessary.
- [ ] Phase 14 — FOSS-informed module schema audit.
  - Compare module schemas against mature FOSS patterns to catch likely missing persisted concepts before production.
  - Pull FlowRoutes-specific FOSS/OSS pattern review earlier into Phase 4 if useful.
  - Split by module group rather than one monster branch.

Deferred launch hardening:

- [ ] DB Snapshot / Export Safety Tool.
  - Do not build during active pre-prod schema discovery unless real data preservation becomes necessary.
  - Build when schemas are stabilizing and production rollout is near.
  - Keep command-line only; no CRM UI.
  - SQL dump should be the primary restore safety net.
  - JSONL table exports should be for inspection, diffing, selective recovery, and debugging.

### Automation opportunity foundation

- [x] Add `docs/automation-opportunities.md` and keep architecture/product rules current.
- [x] Add durable `automation_behavior_occurrences` and `automation_opportunities` schema using current migration conventions.
- [x] Add models and shared `app/Support/AutomationOpportunities` infrastructure.
- [x] Keep `AutomationEventRecorded`, behavior/correlation evidence, and aggregate opportunities distinct.
- [x] Require explicit producer/evidence opt-in; do not add clickstream/global Eloquent observation.
- [x] Keep fingerprint semantics producer-owned and hashing/normalization shared.
- [x] Keep the generic evaluator free of domain/event-specific branching.
- [x] Use stable capability keys where applicable instead of canonically depending on FlowRoutes DB IDs.
- [x] Add manual Contact-associated Task creation as the first evaluated producer.
- [x] Add manual status change -> manual Task creation compound detection.
- [x] Add manual Task completion evidence and manual Task completion -> manual status change compound detection.
- [x] Do not create a generic standalone `contact.status_changed_manually` opportunity.
- [x] Add selected `AutomationEventRecorded` evidence retention and supported event -> manual Task correlation.
- [x] Add `inbound_message.normal_reply` as a neutral automation event only for known-Contact normal replies; keep HELP/STOP outside opportunity evidence.
- [x] Use current generic defaults of 3 occurrences, 3 distinct subjects, and a 30-day observation window.
- [x] Use the current 10-minute window for implemented compound correlations.
- [x] Add focused tests, adjacent module tests, boundary protection, and real CRM/manual smoke validation.
- [x] Verify negative cases: unsupported events ignored, old evidence does not correlate, same-Contact repetition stays observing, system-created Tasks do not count as manual behavior.
- [ ] Add dynamic suggestion-time checks for current capability availability, equivalent existing automation, snooze/dismissal availability, conversion state, context validity, and attribution ambiguity when the first user-facing suggestion surface needs them.
- [ ] Continue contextual suggestion UX against the implemented Routes baseline; do not create a parallel automation builder or recommendation feed.

## Error Tracking

These are open code/runtime investigations surfaced by the first production Webinar run and the follow-up audit. Operational incidents that were setup/deployment issues belong in the staging/production checklist and troubleshooting docs rather than being mislabeled as product bugs.

### Messaging, scheduling, and queue diagnostics

- [X] Verify `ScheduledMessage.send_at` persists the same absolute instant as the timezone-aware queued delay.
  - `ScheduleMessageAction` now normalizes the source instant to UTC before both persistence and queue delay registration and retains source/UTC values in `meta.message_scheduling`.
- [X] Make queued send-time diagnostics timezone-explicit.
  - Horizon/debug `send_at` metadata now uses an ISO-8601 UTC value with an explicit offset.
- [X] Normalize multi-CTA support across config validation and runtime unresolved-token validation.
  - Config validation accepted `ctas` with `{cta}` while runtime unresolved-token validation did not consistently accept the same shape.
- [ ] Add a safer first-class operational recovery mechanism for exact skipped/failed `ScheduledMessage` rows.
  - Current recovery still depends on surgical Tinker commands for narrow incidents.
  - Preserve the current safety principle: identify exact rows, exact channel, exact status, and exact reason; never broaden recovery into indiscriminate retries or queue resets.
- [ ] Improve first-class queued-job diagnostics.
  - Surface effective queue connection, queue name, Redis prefix, delayed/reserved key identity, Horizon metadata, and delayed-until information so operators do not need manual Redis spelunking.
  - Deployment docs already require the restart; the remaining question is whether tooling should reduce the chance of human omission.

### Setup validation

- [ ] Verify the production preset/module false positive is resolved.
  - A production setup using the selected `mortgage` preset incorrectly reported the required Mortgage module as unavailable.
  - Reproduce with current preset composition and enabled-module configuration before changing code.
  - If current `setup:validate` accepts the valid configuration, mark this resolved rather than creating a new fix.

### Webinar join-signal integrity

- [x] Separate raw join-link resolution from trusted human interaction.
  - Public GET and HEAD requests now render a confirmation page without mutating registration metadata, skipping reminders, or redirecting directly to the provider.
  - Only the short-lived relative signed POST records trusted join interaction and suppresses eligible pending reminders.
- [x] Preserve minimum useful trusted join history without adding event-history schema.
  - Compatible `join_clicked_at` and `join_click_count` fields remain available.
  - Structured metadata preserves the first confirmation, latest confirmation, confirmation count, and trusted interaction source.
  - Raw scanner/prefetch hits are intentionally not persisted because the read-only resolver does not treat them as operational evidence.

### Webinar duplicate registration and conflicting outcome safety

- [ ] Add a safe first-class duplicate-outcome suppression mechanism before contradictory attended/missed follow-ups are created.
  - Duplicate registrations for the same likely person and Webinar can independently generate conflicting automation, follow-up, status, and Campaign paths.
  - Do not solve this by globally merging contacts solely because phone numbers match.
- [ ] Define an explicit Webinar-scoped precedence rule for likely duplicate conflicting outcomes.
  - Candidate safe rule: attended wins over missed for likely duplicates within the same Webinar.
  - Identity matching must remain narrow, auditable, and separate from global Contact merge semantics.

### Webinar attendance and post-event provider reliability

- [ ] Make post-event sequencing and recovery intent easier to inspect.
  - `webinar.ended` handles attendance while recording completion resolves playback and dispatches follow-ups.
  - Attendance snapshot authority and unresolved reasons are now visible in CRM Webinar history. Add explicit replay/recovery controls only after the desired operator authorization and audit contract is defined.

## Reporting foundation documentation and audit

This is the next focused documentation branch. Do not begin Reporting implementation until the durable module contract and first phased plan are written.

- [ ] Audit current first-party observability inputs before proposing schema.
  - Existing Nginx/access logs and server-side request facts.
  - Current Webinar landing, registration, waitlist, join, attendance, replay, and message outcomes.
  - Current module events, public read seams, and any existing Reporting module files/tests.
- [ ] Use mature FOSS analytics/reporting systems as feature-shape references, not implementation sources.
- [ ] Expand `docs/modules/reporting.md` into a durable optional-module contract.
  - Reporting owns collection/normalization/aggregation/read models and report surfaces that are genuinely Reporting-specific.
  - Producer modules own their domain state and emit public events/read data; Reporting must not mutate producer state or absorb producer business logic.
  - The module must remain independently enableable and malleable across mortgage, music, pet services, and other clients.
- [ ] Lock privacy-first identity and retention rules.
  - No covert personal-data collection or sale.
  - No cross-domain identity stitching by default.
  - Prefer anonymous/session-level first-party measurement until a deliberate consented Contact correlation exists.
  - Define IP/user-agent handling, bot classification, raw-event retention, aggregation retention, deletion, and access boundaries.
- [ ] Define the initial event and attribution contract.
  - Page/request observations.
  - Webinar view, CTA, registration-start, registration-complete, waitlist, join, attendance, replay, and downstream conversion milestones.
  - Source/referrer/UTM/campaign/content identifiers.
  - Anonymous session, request, page-view, webinar, registration, Contact, message, and campaign correlation rules.
  - Human-vs-bot classification and confidence/reason provenance without pretending uncertain traffic is definitively human.
- [ ] Define the first report and phased implementation plan.
  - First report: Webinar traffic and conversion funnel, including likely-human views, registration conversion, source attribution, join/attendance/replay outcomes, and explicit denominator rules.
  - Phase collection and ingestion separately from normalized events, rollups, query/read services, dashboards, and external-site tracking client work.
  - Include testing, data-volume, dedupe/idempotency, retry, privacy, and persistence-size requirements.
- [ ] Capture unresolved product/schema decisions explicitly instead of guessing.

## One-off backlog

### Messaging opt-in management and email format support

- [ ] Add a dedicated `Messaging → Opt-In Messages` management surface separate from all ordinary module message-template pages.
  - Treat acknowledgement definitions and delivery policy as Messaging-owned compliance primitives.
  - Support `Automatic`, `Always standalone`, and `Prefer selected message type` behavior with an explicit fallback.
  - Preview the final composed lifecycle message plus acknowledgement fragments.
  - Show which consent intents and MessageConsent IDs the final delivery satisfies.
  - Do not expose a generic disabled option where acknowledgement delivery is required.
- [ ] Make module readiness presentation delivery-path aware.
  - Distinguish standalone, consolidated, fallback-covered, and truly missing acknowledgement paths.
  - Do not show `Needs attention` merely because zero standalone opt-in templates exist.
  - Webinar Messages should link to the Messaging-owned Opt-In Messages surface rather than own acknowledgement copy.
- [ ] Add Messaging-level plain-text email support for all email sources.
  - Generate a readable plain-text version from formatted content.
  - Allow an optional editable override.
  - Send `multipart/alternative` with both `text/plain` and `text/html`.
  - Preserve CTA, secondary-link, and unsubscribe URLs in readable text.
  - Add validation and provider tests for both MIME alternatives.
- [ ] Verify generated Webinar URL schemes.
  - Audit `webinar_join_url`, `cancel_registration_url`, and related signed URL builders.
  - Ensure persisted/rendered customer-facing URLs are absolute and include the correct HTTP/HTTPS scheme.
  - Confirm email and SMS renderers do not rely on accidental normalization.


### Rob production Webinar contact migration

Immediate production-prep checkpoint:

- [ ] Re-verify `ConsentDomainRegistry` behavior before touching real contacts.
  - Exact mapping wins.
  - Longest prefix wins.
  - Equal-specificity ambiguity fails loudly.
  - Unknown unmapped scopes remain narrow.
- [ ] Re-verify Webinar consent domain behavior.
  - `webinar`, `webinar_waitlist`, and `webinar_nurture` resolve to the intended `webinar` consent domain.
  - No per-scope Webinar `opt_ins` definitions are required.
  - Generic/module/client acknowledgement copy resolves correctly.
- [ ] Re-verify normal consent grant behavior.
  - Correct domain row is created/updated.
  - Duplicate related-scope grants do not create duplicate consent identities.
  - Appropriate acknowledgement behavior is resolved.
- [ ] Re-verify imported consent behavior.
  - `ImportMessageConsentAction` normalizes to the consent domain.
  - No `MessageConsentGranted` event.
  - No opt-in acknowledgement send.
- [ ] Confirm Rob runtime is clean.
  - `php artisan presets:sync`
  - `php artisan setup:validate`
- [ ] Review/finalize import command dry-run-by-default and explicit `--apply`.
- [ ] Confirm malformed phone + SMS-consent rows produce actionable row-level output.
- [ ] Prepare/verify the exact 11-row CSV.
- [ ] Dry-run and inspect exact output before apply.
- [ ] After apply, verify:
  - 11 contacts.
  - expected consent rows/domains.
  - 11 Webinar registrations.
  - no opt-in acknowledgement sends.
  - no registration confirmations.
  - only future-valid reminders.
  - no duplicates after idempotent rerun.

### CRM dashboard and contact workspace

Completed baseline:

- Dashboard is config-driven by module slots and preset priorities.
- Modules contribute dashboard panels through provider seams.
- Empty actionable panels can show calm caught-up states.
- Empty passive context panels hide.
- Module tones provide muted dashboard wayfinding.
- Contact show is a Core-owned shell with module-provided panels and sections.
- Contact show uses module wayfinding and improved client-facing section labels.

Remaining polish audit:

- [ ] Review shared orientation surfaces after runtime behavior settles.
- [ ] Add persisted preferences/acknowledgements only when real workflows require them.
- [ ] Avoid turning dashboard/contact workspace into a platform cockpit.

### Client self-serve readiness

- [ ] Do not treat controlled beta readiness as full client self-serve readiness.
  - Controlled beta can assume operator-assisted setup/configuration.
  - Full self-serve requires more polished builders, safer config validation, clearer import review tools, and stronger client-facing admin UX.
- [ ] Identify which admin surfaces must exist before clients can operate without developer/operator help.
  - Route builder/editor.
  - Campaign/template management.
  - Task template management.
  - Import mapping/review.
  - Broadcast/permission invitation setup.
  - Provider/channel settings.

### Vertical module planning

- [ ] Plan the PetServices vertical module.
  - Own pets/dogs, pet profiles, training programs, training goals, dog behavior notes, trainer-specific domain rules, and pet-service-specific workflows.
  - Consume Scheduling for dog training appointments/sessions.
  - Consume Portal for customer access.
  - Consume Forms for dog intake forms.
  - Consume Documents for vaccination records, waivers, and other uploads.
  - Contribute vertical-specific route presets/task templates/capability labels through public seams.
  - Keep pet-specific fields out of Core contacts.
- [ ] Plan the Music vertical module.
  - Own music-specific fan/customer meaning, release/fan campaign strategy, music product interest categories, and music-specific segmentation rules.
  - Consume Commerce for Shopify/purchase facts.
  - Consume Location later for show-radius targeting if needed.
  - Consume Campaigns, Broadcasts, Messaging, and FlowRoutes for fan communication/automation.
  - Contribute vertical-specific route presets/task templates/capability labels through public seams.
  - Keep music-specific purchase/interest state out of Core contacts unless represented through a proper universal module relation.

### Documentation maintenance

- [ ] Regenerate `core-project-tree.txt` from the repo after structural module/file changes.
  - Do not hand-maintain it.
- [ ] Keep `module-boundaries.md` architectural, not a backlog.
  - Move actionable backlog items into this TODO file.
  - Delete completed TODOs instead of accumulating historical notes.
- [ ] Add/update feature-specific docs when a feature crosses module boundaries.
  - Permission invitations already has a dedicated doc.
  - Similar docs may be useful for Broadcasts, Campaigns, FlowRoutes, Tasks, and Imports once each stabilizes.
- [ ] Remove the hand-maintained full module-registry copy from `docs/config-templates/modules-template.php`.
  - Keep `config/modules.php` as the one external registry of installed module existence.
  - The template should document registry shape and selected-client runtime module configuration without requiring every module key, provider, and dependency to be copied manually.
- [ ] Avoid parallel documentation inventories of executable module and preset registry facts.
  - Keep examples that explain architecture and config shape.
  - Do not create secondary authoritative lists that must be updated every time a module is added.
  - Prefer deriving future generated reference documentation from executable registries/contracts where appropriate.

### Testing backlog

- [x] Add test coverage for client config fallback behavior.
  - Missing optional content/style keys should not break public pages.
  - Client copy changes should not break tests that only need behavioral assertions.
- [ ] Refactor the hard-coded installed-module inventory assertion in `tests/Feature/Modules/ModuleDependencyBoundaryTest.php`.
  - Validate registered module definitions generically instead of enumerating every installed module key in a second hard-coded list.
  - Adding a new module should not require updating another module-existence inventory outside `config/modules.php`.

## Captured UX polish backlog

These notes are intentionally retained while the schema-discovery phases continue. Do not pull them ahead of higher schema-risk work unless implementation proves a missing table, registry, provider, or runtime service is needed.

### Shared field/token insertion

- [ ] Add a shared available-field/token picker pattern for message/template/config authoring surfaces.
  - Use client-facing language such as `Insert field` or `Add field`, not `token`, on normal operator screens.
  - Preserve cursor/focus in the input/textarea when the picker opens.
  - Provide autocomplete search from available fields for the current context.
  - Insert the current runtime syntax such as `{first_name}` without requiring operators to type braces or exact keys manually.
  - Do not let users select fields that the current message/context cannot resolve.
  - Consume `TokenContractRegistry` and `MessageTemplateTokenValidator`; do not create a second UI-only field list or validator.

### Contextual hints

- [ ] Add a reusable hover/focus hint pattern for confusing fields, settings, and navigation items.
  - Hints should explain what the setting does in plain language.
  - Hints should not expose schema/config/event keys as the main explanation.
  - Hints do not replace consequence previews for automation-triggering changes.

### Routes product completion

- [ ] Continue from the current `Routes -> Manage Routes / Assignments` information architecture.
  - Keep `FlowRoutes` in developer/module docs where precision matters.
  - Keep `Runs when` and trigger-selection detail in Assignments rather than repeating it in Route details.
  - Preserve modal Route editing and modal Point editing.
  - Preserve drag-and-drop with explicit `Save order` only after the order changes.
  - Preserve direct visible Remove actions and contextual disabled-state explanations.
  - Preserve `Campaign` terminology in Route UI; do not relabel Campaigns as follow-up sequences.
  - Add new Route creation before treating the current authoring surface as product-complete.
  - Consider duplication, activate/deactivate, trigger changes, and clone-from-another-Route as focused follow-up slices.
  - Keep advanced internal Point types out of normal authoring unless a later product decision explicitly introduces a simple non-canvas rule.

### Broadcasts UX polish

- [ ] Make imported-contact opt-in invitations secondary to normal Broadcast authoring.
  - Consider a button such as `Send opt-in invitation to imported contacts`.
  - Do not show opt-in options/import batches when eligible invitation count is zero.
- [ ] Collapse `Avoid Duplicate Sends` by default with a clear summary.
- [ ] Explore a guided Broadcast authoring flow:
  - channel;
  - channel-specific payload;
  - recipients;
  - duplicate protection/review.
- [ ] Add `Make a new broadcast from this` when useful for repeating a Broadcast to a new channel/audience.
  - Add lineage schema such as `cloned_from_broadcast_id` only if audit/debug/product needs prove it.

### Campaigns UX polish

- [ ] Replace `delivery options` wording with clearer `message steps` and channel wording.
- [ ] Collapse campaign steps by default.
- [ ] Show only the most useful collapsed step information:
  - step number/title;
  - available channel badges;
  - human-readable timing;
  - selected template readiness.
- [ ] Hide technical specs behind details/debug affordances.
- [ ] Replace raw timing such as `Delay 10 minutes` with human-readable schedule summaries.
- [ ] Clean up repeated dropdown labels such as `Step 1 Email — Webinar Attended Nurture — Step 1 Email`.