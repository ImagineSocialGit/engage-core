# Engage Core Config Templates

These files are reference templates for generating default and client config files without drifting shapes.

Use these rules when converting a client request into config:

1. Messaging configs own reusable delivery templates and message copy.
2. Campaign presets own journeys, step order, timing, and message template references.
3. Campaign presets do not own payload/copy and do not override payload/copy.
4. Campaign message templates resolve by `campaign_key + step_number` under:
   `messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}`.
5. FlowRoute presets own automation/control-flow routing and point definitions.
6. Webinar post-event config owns provider event orchestration, not message copy.
7. Root `config/presets.php` owns preset package composition and sync order.
8. `config/modules.php` owns enabled modules and dependency visibility.
9. Use `lead/leads` in CRM/client-facing text unless explicitly told otherwise.
10. Default webinar configs should be vertical-neutral. Vertical-specific copy belongs in vertical-specific scopes.

## Preferred purpose/scope pairs

- `transactional:webinar` for confirmations, reminders, join reminders, replay/recording follow-ups.
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

Legacy only:

- `marketing_message_sent` — do not use for new configs.

## Current known queues

- `confirmation_messages`
- `opt_in_messages`
- `reminders`
- `post_event`
- `marketing`
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

Token references are documented in `TOKEN_REFERENCE.md`.