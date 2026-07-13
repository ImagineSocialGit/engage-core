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

Consent acknowledgement contexts are different: they resolve through Messaging's consent-domain and opt-in-definition services. Do not create or select scope-specific `opt_ins` templates merely to represent registration or waitlist consent acknowledgements. Schedule selection remains Webinars-owned and separate from copy editing.

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
```

Current states:

```text
Ready
Needs attention
Optional / disabled
```

Registration consent acknowledgement readiness is required when transactional webinar registration messaging has at least one available channel.

Waitlist consent acknowledgement readiness is required when webinar-waitlist marketing messaging has at least one available channel.

When the corresponding messaging surface is unavailable, the consent acknowledgement area is `Optional / disabled` rather than a false blocker.

Consent-event acknowledgements dispatched by Messaging are Messaging-owned behavior, even when consent is collected on a Webinar surface. They resolve through the `webinar` consent domain and `ConsentOptInDefinitionResolver`, not through per-scope Webinar `opt_ins` template groups and not through Webinar schedule-profile items. Webinars owns the registration/waitlist surface, the human-readable consent topic, and the readiness context; Messaging owns consent-domain normalization, the consent-granted event, acknowledgement resolution, and delivery safety.

## Selectable webinar schedule profiles

Webinars supports DB-owned selectable schedule profiles for webinar-owned messages.

Schedule profiles decide whether, when, and under what Webinar lifecycle conditions messages are sent. Messaging template presets decide what those messages say and provide reusable delivery-template metadata.

Every Webinar lifecycle message dispatched through the Webinar lifecycle should get its behavior from a `WebinarScheduleProfileItem`, including immediate registration confirmations, waitlist alerts, and post-event follow-ups. Messaging-owned consent-domain acknowledgements are the explicit exception because the consent-granted lifecycle belongs to Messaging.

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


For post-event follow-ups, Webinars should pass `webinar.ends_at` as the schedule anchor. This keeps `next_day_at` tied to the webinar's actual ending calendar day even when provider webhook processing is delayed past midnight.

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
