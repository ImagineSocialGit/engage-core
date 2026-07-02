# Engage Core Config Templates

These files are reference templates for generating default and client config files without drifting shapes.

Use these rules when converting a client request into config:

1. Messaging configs own reusable delivery templates and message copy.
2. Campaign presets own journeys, step order, timing, and message template references.
3. Campaign presets do not own payload/copy and do not override payload/copy.
4. Campaign preset steps reference Messaging templates with first-class `channel`, `purpose`, and `scope` keys.
5. Campaign message templates resolve by `campaign_key + step_number` under:
   `messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}`.
6. Do not use `meta.message` for new Campaign preset step message references.
7. FlowRoute presets own automation/control-flow routing and point definitions.
8. Webinar post-event config owns provider event orchestration, not message copy.
9. Root `config/presets.php` owns preset package composition and sync order.
10. `config/modules.php` owns enabled modules and dependency visibility.
11. Use `lead/leads` in CRM/client-facing text unless explicitly told otherwise.
12. Default webinar configs should be vertical-neutral. Vertical-specific copy belongs in vertical-specific scopes.
13. SMS capabilities may exist in code even when SMS is hidden in client/admin UI. UI exposure for SMS options should be controlled by config.
14. Normal Broadcasts require normal Messaging consent. Imported-contact opt-in invitations are a distinct one-time email flow, not a general Broadcast consent bypass.
15. While a branch is still pre-rollout, replace current branch migrations instead of adding modify-table migrations. After rollout, use normal append-only migrations.

## Preferred purpose/scope pairs

- `transactional:webinar` for confirmations, reminders, join reminders, replay/recording follow-ups.
- `transactional:permission_invitation` for imported-contact one-time opt-in invitation emails.
- `marketing:webinar_nurture` for attended/missed webinar nurture campaigns.
- `marketing:webinar_waitlist` for waitlist notifications.
- `marketing:mortgage_homebuyer_nurture` for mortgage-specific long-term homebuyer nurture.
- `internal:inbound_messages` for team-facing inbound reply notifications.
- `internal:tasks` for task assignment/digest notifications.

## Current known dispatch keys

- `registration_created` — webinar registration confirmation/reminder trigger.
- `consent_granted` — Messaging consent opt-in trigger.
- `webinar_added` — webinar waitlist availability trigger.
- `webinar_ended` — post-webinar transactional follow-up trigger.
- `campaign_step_due` — campaign step message trigger.
- `broadcast_send` — one-time Broadcast send trigger.
- `imported_contact_permission_invitation` — one-time imported-contact preference confirmation email trigger.

Legacy only:

- `marketing_message_sent` — do not use for new configs.

## Current known queues

- `confirmation_messages`
- `opt_in_messages`
- `reminders`
- `post_event`
- `marketing`
- `emails`
- `waitlist`
- `notifications`

## Current timing values

Messaging definitions:

- `immediate`
- `scheduled`

Messaging schedule values:

- `type = delay` with positive `minutes` from trigger time.
- `type = anchored` with positive/negative `minutes` from anchor time.

Campaign-authored timing values:

- `type = immediate`
- `type = delay` plus `minutes`, `hours`, or `days`
- `type = anchored` plus `minutes`, `hours`, or `days`

Campaign step timing is authored in Campaign presets and normalized before dispatching through Messaging.


## Client override and validation expectations

Client config files may partially override default config. Associative arrays should merge over defaults so omitted nested copy/style keys keep safe defaults. Numeric arrays should replace defaults when present, especially ordered definitions and lists such as consent scopes.

Before shipping a client config, confirm:

- Required keys are present directly or through default fallback.
- Unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
- Dispatch keys and tokens are documented in the default or client reference registries.
- Runtime-only URLs/tokens are injected by runtime services, not guessed in static config.
- Missing optional public-page content/style keys do not break rendering.
- SMS visibility is controlled through Messaging channel availability for the relevant surface.

Permission invitation config should preserve the email-only one-time bypass send, explicit SMS opt-in, and configured consent scopes. Normal Broadcasts still require normal Messaging consent.

## Broadcast recipient filter shapes

Core owns generic contact filter resolution. Broadcasts store recipient selection metadata in `broadcasts.recipient_filter` and delegate base contact lookup/resolution to Core.

Supported current base shapes:

```json
{"type":"all"}
```

```json
{"type":"tag","tags":["homebuyer"]}
```

```json
{"type":"contact_ids","contact_ids":[1,2,3]}
```

```json
{"type":"imported"}
```

`imported` means contacts where `source = import`, `meta.imported = true`, or `meta.imported_at` exists.

Broadcast recipient filters may also include Broadcast-owned exclusions:

```json
{
  "type": "all",
  "exclude": {
    "broadcast_ids": [12, 13],
    "statuses": ["scheduled", "sent"]
  }
}
```

`exclude.broadcast_ids` removes contacts that already have matching `BroadcastRecipient` records for those prior Broadcasts.

`exclude.statuses` currently supports:

```text
scheduled
sent
```

Use this for duplicate-send protection when sending related single-channel Broadcasts, such as sending SMS first and then emailing everyone who was not already scheduled or sent the SMS Broadcast.

Broadcasts remain single-channel sends. Do not use one normal Broadcast as an implicit email+SMS fanout.

## Imported-contact opt-in invitations

Imported contacts may receive exactly one permission invitation email through the special Messaging permission-invitation flow.

This is not a normal marketing Broadcast bypass.

Rules:

- The invitation send is email-only.
- The invitation uses `message_type = imported_contact_permission_invitation`.
- The invitation uses `dispatch_key = imported_contact_permission_invitation`.
- The invitation uses `purpose = transactional` and `scope = permission_invitation`.
- `contact_permission_invitations` enforces one invitation per imported contact/source/channel.
- The public preference page can let the contact opt into email, SMS, or both.
- SMS opt-in requires explicit choice and a phone number.
- Accepted channels create `MessageConsent` rows for configured scopes.
- A normal marketing Broadcast to imported contacts still requires normal Messaging consent.

Token references are documented in `TOKEN_REFERENCE.md`.