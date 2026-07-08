# Webinars Module

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

Webinars may use Messaging to send registration confirmations, reminders, opt-ins, and post-webinar transactional follow-ups.

## Webinar message/template/schedule setup

Webinars provides the owning setup surface for webinar-owned message contexts.

Messaging template presets decide what those messages say.

Webinars decide when those messages are sent and which webinar context they apply to.

The Webinars setup surface should support contexts such as:

```text
registration confirmation
reminders
waitlist availability messages
post-attended transactional follow-up
post-missed transactional follow-up
```

That surface should show the current selected Messaging template for each context, allow choosing a compatible `MessageTemplatePreset`, save the selected `MessageTemplatePresetAssignment`, and link back to Message Templates for copy editing. Schedule selection remains Webinars-owned and separate from copy editing.

## Selectable webinar schedule profiles

Webinars supports DB-owned selectable schedule profiles for webinar-owned messages.

Schedule profiles decide when webinar lifecycle messages are sent. Messaging template presets decide what those messages say.

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

Assignments may be default/global or context-specific. The current durable selection points are webinar series and individual webinar, with individual webinar selection taking precedence over series selection.

A schedule profile item references runtime dimensions such as dispatch key, message type, channel, purpose, scope, surface, source config path, timing, schedule, conditions, and metadata. It should not embed reusable message copy.

Multiple reminder slots may share the same reusable Messaging behavior, for example `message_type = reminder`. The schedule profile item key and source config path identify the specific reminder slot, such as 30 minutes before start. Messaging should not encode reminder timing into schedule-specific message types such as `reminder_30_minute`.

Scheduled-message payloads created by Webinars must remain compact. They should include send-ready payload fields, compact token maps, and compact context arrays. They must not include full Eloquent model arrays, loaded relationships, webinar schedule profile objects, or profile item collections. Schedule profile/source identity belongs in scheduled-message metadata.

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
2. Webinars uses Messaging for webinar-owned transactional messages such as confirmations, reminders, and replay follow-ups.
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

## Automatic Follow-ups webinar usage

Webinar outcomes should continue to emit neutral automation events such as:

```text
webinar.registered
webinar.attended
webinar.missed
webinar.ended
```

Automatic Follow-ups UI should translate those event keys into human-readable activity labels, such as:

```text
Someone registers for a webinar.
Someone attends a webinar.
Someone misses a webinar.
```

Raw event keys may be shown as secondary diagnostic metadata when useful.

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
