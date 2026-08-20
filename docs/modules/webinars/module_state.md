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

This module reference owns the detailed responsibility, dependency, and boundary notes for this module. Keep global architectural rules in `docs/module-boundaries.md`; keep actionable module backlog in this directory's `TODO.md` when one exists.

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

## Optional capability boundaries

Messaging is a hard Webinar module dependency because Webinars uses Messaging-owned template, consent, chain, and dispatch infrastructure. That dependency does not make every Webinar message mandatory. Registration confirmations, reminders, waitlist notices, and post-event transactional messages remain controlled by Webinar message-area and schedule-profile enablement. Intentionally disabled message areas must not create definition-readiness errors.

Attendance tracking and provider recording resolution are independent Webinar-owned post-event capabilities:

```text
webinars.post_event.attendance.enabled
webinars.post_event.recordings.enabled
```

When attendance tracking is disabled, Webinars does not require attendance webhook mappings or provider attendance reconciliation readiness. When recording resolution is disabled, Webinars does not require the recording-completed webhook mapping or provider recording lookup readiness. If both capabilities are disabled, Zoom webhook-secret, timestamp-drift, and post-event webhook-mapping readiness are not installation requirements.

Campaigns is not a Webinar dependency. Webinars may emit neutral automation events such as `webinar.attended`, `webinar.missed`, `webinar.ended`, and `webinar.replay_available`; Campaigns or FlowRoutes may consume those events when their own modules and definitions are enabled. Enabling Webinars must not install or require Campaigns.

Reporting is not a Webinar dependency. Webinars owns registration, attendance, and public-page source facts; Reporting may consume those facts when enabled, but Webinar registration and public pages must continue to work without Reporting.

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

`setup:validate` separates structural provider configuration from environment/provider readiness when Zoom is the selected Webinar provider.

Structural problems remain errors in every environment. These include malformed or insecure API/OAuth endpoints, invalid OAuth token TTL, missing/invalid provider event-type adapter definitions, and invalid webhook mappings for capabilities that are enabled.

Missing Server-to-Server OAuth account ID, client ID, or client secret is a provider-readiness finding rather than a schema/installability failure. It is a warning in `local` and `testing`, where a developer may legitimately install and exercise Webinar-owned behavior without a usable Zoom account. It is an error in `staging` and `production`, where provider-backed Webinar operations are expected to be deployable.

Webhook readiness follows the enabled post-event capabilities. Attendance tracking requires `webinar.ended` and `meeting.ended` mappings. Recording resolution requires the `recording.completed` mapping. A webhook secret and valid timestamp-drift setting are required only when at least one of those provider-webhook capabilities is enabled. Missing webhook credentials use the same local/testing warning and staging/production error policy as missing OAuth credentials.

Setup validation does not prove that Zoom granted the required Marketplace scopes or account-role permissions. Deployment must still exercise the exact provider calls and a real signed webhook when those capabilities are enabled. The authoritative scope/event checklist lives in `docs/operations/client-third-party-services-checklist.md`.

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

The registration bucket owns the reusable form contract and its presentation mode:

```text
presentation = modal | inline
page_revision = producer-owned bounded revision identifier
```

Core defaults to `modal`. A client or permitted series override may select `inline` without changing registration persistence, validation, consent handling, provider synchronization, Messaging behavior, or Reporting event semantics. The public registration view passes the same resolved registration content, style, and runtime tokens into the shared registration-form component in either mode.

In `modal` mode, registration CTAs open the dialog. In `inline` mode, the form is rendered in the landing-page hero and CTAs scroll/focus that form instead of opening a second selling step. Reporting continues to receive the configured `presentation` dimension; `webinar.modal.open` is emitted only for the modal experience.

Registration-owned presentation includes:

```text
presentation and page revision
form-card copy
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

### Registration-question placement and open responses

Registration questions remain Webinar-owned, configuration-driven definitions persisted as `WebinarRegistrationResponse` snapshots. A question may declare:

```text
placement = registration | post_registration
```

Missing `placement` remains backward-compatible with `registration`. Initial-placement questions participate in the registration POST exactly as before. `post_registration` questions are deliberately excluded from the registration request so a successful local registration is never contingent on answering enrichment questions afterward.

Supported question types include:

```text
select
textarea
```

`textarea` definitions own their `required` and `max_length` rules in configuration. Persisted open responses use the existing response table: the stable answer key/label identify an open response while the attendee's text is stored in `answer_text`. No separate response table or client-specific columns are required.

When effective post-registration questions exist, a successful registration redirects to a signed, replacement-aware Page 2 before the normal thank-you page. That page resolves the canonical registration and preserves the existing truthful public registration states (`processing`, `confirmed`, `delayed`, `cancelled`). Submitting the questions updates only that registration's configured post-registration response snapshots and then continues to the existing thank-you page. Leaving Page 2 without submitting does not cancel, roll back, or otherwise invalidate the already-created registration.

The `registration.fields.last_name.enabled` boolean may hide and prohibit last-name input for clients that intentionally want a shorter registration form. Core defaults it to enabled. This is presentation/validation configuration only; it does not change Contact identity rules or make arbitrary core identity fields configurable through Webinar copy.

## Webinar message chains and bindings

Webinars owns the business events that start Webinar-related message chains.

Messaging owns:

```text
MessageTemplates and immutable versions
MessageChains and immutable versions
chain steps/variants
chain enrollments
compact ScheduledMessages
delivery attempts
```

Webinars owns module-specific bindings such as:

```text
registration created -> registration message chain
waitlist availability -> waitlist message chain
attendance recorded as attended -> attended follow-up chain
attendance recorded as missed -> missed follow-up chain
```

A message chain is reusable and does not own the Webinar trigger.

### Target binding persistence

Webinar chain selection should use a small Webinars-owned binding record or equivalent first-class relationships.

The migration/model batch should support:

```text
WebinarSeries or Webinar occurrence
trigger key
MessageChain
active/default precedence
timestamps
```

Exact series-versus-occurrence columns should preserve current behavior:

```text
occurrence-specific selection
    overrides series selection

series selection
    overrides module/client default

default
    used only when no explicit valid selection exists
```

Do not store chain/profile/template labels, config paths, or definition snapshots in the binding.

### Registration and waitlist

A successful registration should create a `MessageChainEnrollment` for the selected registration chain.

Target enrollment identity:

```text
recipient = Contact
context = WebinarRegistration
origin = WebinarRegistration
```

A waitlist availability outcome starts the selected waitlist chain using the waitlist signup or resulting registration as context according to the final runtime contract.

Webinars should not materialize the entire reminder cadence as ScheduledMessages during registration.

Normal progression should create only the next actionable chain wave.

### Recurring future-availability subscriptions

A missed-attendee future-Webinar subscription reuses `webinar_waitlist_signups`; it does not introduce a second subscription or notification-history table.

The durable lifecycle fields are:

```text
notification_mode = once | recurring
expires_at
ended_at
```

`notified_at` remains the first successfully planned availability notification. For a one-shot signup it also removes the row from future eligibility. For a recurring signup it is historical context only; the subscription remains eligible until `expires_at` or `ended_at` closes it.

Automatic missed-attendee subscription creation does not grant Messaging consent. Webinars may retain only configured channels that are available on the `webinar_waitlists` surface and already have active marketing consent in the resolved Webinar consent domain. Revocation, suppression, provider availability, and send-time gates remain Messaging-owned.

Recurring availability delivery reuses the existing waitlist MessageChain. Stable per-occurrence dedupe remains:

```text
waitlist signup + Webinar occurrence + waitlist message area
    -> one MessageChainEnrollment identity
```

The existing MessageChainEnrollment/ScheduledMessage history is authoritative evidence for an occurrence. Do not add a parallel per-occurrence notification-history table or use `send_at` as occurrence identity.

Provider sync may dispatch a recurring-only waitlist notification for each newly created registerable occurrence. The historical one-shot path remains series-based and continues to stop after its first planned notification.

### Attendance outcomes

Attended and missed follow-up chains are separate business-trigger bindings.

This keeps the generic MessageChain engine from becoming a Webinar attendance classifier.

Webinars records attendance first, then starts the chain selected for that outcome.

Transactional replay/follow-up chains remain distinct from marketing nurture Campaigns.

### Copy ownership

Webinar setup may open Messaging template/chain editors in Webinar context.

Webinars does not duplicate reusable copy editing in Webinar-owned tables.

Editing copy creates a new immutable `MessageTemplateVersion`.

Editing cadence/conditions creates a new immutable `MessageChainVersion`.

Existing registrations/enrollments remain pinned to their prior versions.

### Chain duplication for a series

The CRM action discussed for Webinar series should duplicate the selected MessageChain using copy-on-write:

```text
create new MessageChain identity
create one MessageChainVersion
copy small step/variant definitions
reuse existing MessageTemplateVersion IDs
assign the new chain to the target series/trigger binding
create new template versions only when the operator customizes copy
```

Do not clone scheduled messages, render contexts, delivery attempts, catalog snapshots, or full payloads.

## Webinar message readiness

Webinars owns computed readiness for its chain bindings and required trigger contexts.

Readiness is not persisted.

Target readiness areas:

```text
registration chain
registration consent-acknowledgement delivery path
waitlist chain
waitlist consent-acknowledgement delivery path
attended follow-up chain
missed follow-up chain
```

Readiness uses current truth:

```text
effective series/occurrence/default binding
selected MessageChain existence and active/current version
chain step/variant integrity
template-version/channel compatibility
Messaging channel availability
required token context availability
consent-domain acknowledgement composition or standalone fallback
post-event outcome enablement
```

States remain:

```text
Ready
Needs attention
Optional / disabled
```

A required acknowledgement is ready when it has:

```text
a valid composed path through ScheduledMessage components
or
a valid standalone fallback
```

A missing standalone acknowledgement template is not itself a failure when the required intent is covered safely through composition.

When a channel/surface is intentionally unavailable, the corresponding optional area should not become a false blocker.

Readiness should explain the business problem, not expose raw template-version, chain-version, or binding IDs as the primary message.

## Current schedule-profile and chain architecture

Webinar schedule profiles remain the DB-owned authoring/selection surface:

```text
webinar_schedule_profiles
webinar_schedule_profile_items
```

They are no longer the direct runtime delivery engine. Preset/profile sync compiles active profile definitions into Messaging-owned immutable chains:

```text
profile + message area
    -> stable MessageChain

profile cadence/items
    -> immutable MessageChainVersion / steps / variants

profile message-area selection
    -> WebinarScheduleProfileChainBinding

series customization
    -> WebinarSeriesMessageChainBinding with copy-on-write template/chain ownership
```

Registration and post-event paths start version-pinned MessageChainEnrollments through Webinars-owned bindings. The generic chain runner materializes only the actionable delivery wave.

The current hybrid is deliberate:

- schedule profiles provide operator-friendly Webinar cadence authoring and selection;
- immutable MessageChains provide runtime behavior/history;
- Webinars owns trigger/binding precedence;
- Messaging owns chain progression, template versions, delivery attempts, and terminal outbox;
- ScheduledMessages created by the chain contain destination/runtime differences and chain FKs, not copied profile/template/condition snapshots.

Do not add a second direct profile-to-ScheduledMessage runtime path. A later product simplification may replace the profile authoring tables, but it must preserve the current usable authoring surface and is not required for runtime correctness.

## Timing and timezone

Generic chain timing must preserve the existing useful schedule concepts:

```text
delay
anchored
next_day_at
```

Authoring may use minutes/hours/days.

Canonical persistence should use:

```text
timing type
anchor key
offset seconds
```

Calendar-based `next_day_at` behavior uses `config('client.timezone')`.

Do not duplicate timezone on every chain step.

For post-event chains, Webinars should use `webinar.ends_at` as the business anchor so delayed webhook processing does not shift the intended next-morning date.

The chain runner normalizes the final `next_action_at`/`send_at` instant to UTC.

## Stable occurrence identity

Registration, waitlist, attended, and missed chain starts require stable dedupe identity based on the owning Webinar record and trigger occurrence.

Do not use `send_at` as logical identity.

Retrying the same chain start must resolve the existing enrollment rather than create a second chain because the calculated timestamp changed.

## Persistence constraints

Webinar message runtime rows must not contain:

```text
full Contact snapshots
full Webinar/WebinarSeries/WebinarRegistration snapshots
profile or profile-item objects
template assignment/catalog snapshots
canonical chain condition arrays copied per message
delivery-consolidation recipes
human labels or config branches
```

Current chain-created ScheduledMessages use relationships:

```text
WebinarRegistration or other Webinar context
Webinars-owned chain binding
MessageChainEnrollment
MessageChainStepVariant
MessageTemplateVersion
ScheduledMessage
```

Their payload is limited to runtime differences such as the current destination; reusable copy comes from the immutable template version. Their generic metadata is empty for the normal chain path. Runtime token values are frozen lazily in ScheduledMessageRenderContext.

Direct non-chain Messaging paths may retain bounded payload/meta under the Messaging persistence contract, but Webinars must not reintroduce copied profile/template provenance there.

## Webinars setup validation ownership

Webinars contributes Webinars-owned checks through `WebinarsSetupValidationContributor`.

Target validation uses Webinars-owned bindings and Messaging public template/chain resolution seams.

At minimum, validate:

```text
effective registration/waitlist/attended/missed binding resolves deliberately
selected MessageChain exists
selected MessageChain has a valid current/published version
chain step keys and order are valid
timing/anchor/offset definitions are valid
next_day_at uses client timezone without per-step timezone duplication
step variants reference compatible immutable MessageTemplateVersions
required channel/purpose/scope contexts are available
required Webinar tokens/URLs can be supplied by the actual context
consent acknowledgement composition/fallback is valid
series/occurrence/default precedence is unambiguous
stable dedupe identity can be produced
no required copy or timing remains only in legacy schedule profiles
```

Validation should also reject:

```text
ScheduledMessage payload/meta snapshots
copied chain conditions on runtime rows
binding/profile labels stored as provenance JSON
two permanent active schedule engines for the same Webinar lifecycle path
```

A required binding that cannot execute safely is a hard error.

Optional intentionally disabled chains/channels may be warnings or omitted according to operator usefulness.

Post-webinar transactional follow-ups remain:

```text
purpose = transactional
scope = webinar
```

Marketing nurture remains Campaign-owned and starts through Campaign/FlowRoutes integration rather than being mislabeled as a transactional Webinar chain.

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

## Post-event replay review and dispatch safety

Post-event replay delivery has two independent safety layers. Clients may enable `webinars.post_event.review.required` to require an operator decision after an occurrence ends. A pending review is surfaced through the Webinar dashboard panel. The operator may approve the current provider recording, approve a completed occurrence from the same WebinarSeries as the alternate replay source, or suppress replay sending for that occurrence. The effective approved playback URL remains stored on the completed Webinar so existing Webinar message tokens and public playback links keep their current contract.

Replay-dependent scheduled messages are also revalidated at actual Messaging dispatch. Webinars contributes a Messaging recipient gate that recognizes WebinarRegistration context and templates containing `{webinar_playback_url}`. The normal Contact consent/suppression gate runs first; the Webinar gate then verifies that any required operator review is approved and asks the authoritative provider for the selected recording again. A missing/deleted recording skips the replay-dependent message with `webinar_recording_unavailable` rather than sending a stale replay. Provider failures other than an authoritative unavailable result continue through the normal Messaging retry/failure path.

The dashboard review is the canonical operator surface. Internal Notifications may later link to the same review task, but Webinars does not depend on Internal Notifications for this lifecycle.