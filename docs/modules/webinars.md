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

## Provider event types and adapter selection

Provider family and provider event type are separate identities.

```text
provider family: zoom
provider event type: webinar | meeting
```

`WebinarSeries.provider_event_type` selects the adapter used for future synchronization.
Every synchronized `Webinar` occurrence stores its own immutable `provider_event_type`.
Changing a series from Webinar to Meeting must not mutate historical occurrences or
registrations.

The configured provider must expose explicit adapters for both Zoom event types:

```text
zoom:webinar -> ZoomWebinarProvider
zoom:meeting -> ZoomMeetingProvider
```

Both adapters implement the same `WebinarProvider` contract and share the provider key
`zoom`. The Meeting adapter uses Meeting lookup, registrant, cancellation, attendance,
and recording endpoints; the Webinar adapter uses the corresponding Webinar endpoints.

## Explicit occurrence replacement

A provider event-type switch is not an in-place conversion. Operators explicitly link
an obsolete source occurrence to a new target occurrence in the same series and provider
family. The replacement workflow preserves both occurrences and every historical source
registration.

For each active source registrant, Webinars creates or adopts one replacement
registration, preserves consent provenance, suppresses obsolete pending messages, and
queues provider reprovisioning independently. Successful registrants are not repeated;
failed or reconciliation-required registrants remain individually recoverable.

Replacement chains must remain within the same Webinar series, Contact identity, and
corresponding occurrence-replacement chain. Bounded traversal detects malformed links
and cycles.

Previously issued public links remain valid:

```text
join link       -> latest usable canonical replacement registration
thank-you link  -> canonical occurrence and provider-sync status
cancel link     -> one canonical registration and provider cancellation
```

The original signed registration identity remains provenance. Canonical resolution must
not duplicate consent acknowledgements, confirmations, cancellation events, or provider
cancellation requests.

## Zoom readiness contract

`setup:validate` performs non-network readiness checks when Zoom is the selected Webinar
provider. It validates:

- Server-to-Server OAuth account ID, client ID, and client secret;
- HTTPS API and OAuth endpoints without embedded credentials;
- OAuth token cache TTL between 60 and 3600 seconds;
- explicit Webinar and Meeting adapter classes implementing `WebinarProvider`;
- webhook secret and timestamp-drift configuration;
- native mappings for `webinar.ended`, `meeting.ended`, and `recording.completed`.

Setup validation does not prove that Zoom granted the required Marketplace scopes or
account-role permissions. Deployment must still exercise the exact provider calls and a
real signed webhook. The authoritative scope/event checklist lives in
`docs/client-third-party-services-checklist.md`.

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
1. Verify Zoom capabilities required by every event type in use:
   Meeting/Webinar lookup and registration, Meeting/Webinar attendance reporting, and cloud recording lookup/access.
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

Zoom capability requirements should follow the current provider calls and the dedicated production/provider setup checklist; do not assume basic Meeting or Webinar access also grants participant-report or cloud-recording access.

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