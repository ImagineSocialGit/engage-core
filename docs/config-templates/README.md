# Engage Core Config Templates

These files are reference templates for generating default and client config files without drifting shapes.

Use these rules when converting a client request into config:

1. Messaging configs own reusable delivery templates and message copy.
2. Campaign presets own journeys, step order, timing, and message template references.
3. Campaign presets do not own payload/copy and do not override payload/copy.
4. Campaign preset steps reference Messaging templates with first-class `channel`, `purpose`, and `scope` keys.
5. Campaign message templates resolve by `campaign_key + step_number` under:
   `messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}`.
6. Future campaign channel variants must reference Messaging-owned template presets or assignments rather than embedding copy.
7. Messaging template presets own reusable/editable message copy.
8. Messaging template catalog entries own browsing/grouping metadata for the template catalog.
9. Messaging template assignments own selected runtime template choices.
10. FlowRoute trigger bindings should select active route behavior; `FlowRoute.is_active` means available/allowed.
11. Do not use `meta.message` for new Campaign preset step message references.
12. FlowRoute presets own automation/control-flow routing and point definitions.
13. Webinar post-event config owns provider event orchestration, not message copy.
14. Webinar schedule profile configs own webinar lifecycle timing/slot identity, not message copy.
15. Runtime artifact payloads should stay compact: send payloads and token maps should not include full model arrays, loaded relationships, schedule profile item collections, provider settings, or large raw blobs unless the column is explicitly for raw provider data.
16. Root `config/presets.php` owns preset package composition and sync order.
15. `config/modules.php` owns enabled modules and dependency visibility.
16. Use `lead/leads` in CRM/client-facing text unless explicitly told otherwise.
17. Default webinar configs should be vertical-neutral. Vertical-specific copy belongs in vertical-specific scopes.
18. SMS capabilities may exist in code even when SMS is hidden in client/admin UI. UI exposure for SMS options should be controlled through Messaging channel availability.
19. Normal Broadcasts are single-channel sends. Email Broadcasts use `payload.subject` and `payload.body`; SMS Broadcasts use `payload.message`.
20. Normal Broadcasts require normal Messaging consent. Imported-contact opt-in invitations are a distinct one-time email flow, not a general Broadcast consent bypass.
21. While a branch is still pre-rollout, replace current branch migrations instead of adding modify-table migrations. After rollout, use normal append-only migrations.
22. Module-specific behavior belongs in the owning module doc. Config templates should not invent unsupported module behavior.
23. Commerce and Location should be used for client/admin convenience and integrations, not as storefront/GIS replacements.


## Runtime-selectable definitions

Future config templates should distinguish between available definitions and active runtime selections.

Config files define available options.

Preset/template sync imports those available options into DB-owned definitions.

CRM/admin UI selects active options through assignments/bindings.

Runtime resolvers read the selected DB-owned assignment/binding.

Examples:

```text
FlowRouteTriggerBinding selects which FlowRoute runs for a trigger/context.
MessageTemplateCatalogEntry organizes Messaging templates for browsing by channel, purpose, module/surface, group, and item.
MessageTemplatePresetAssignment selects which Messaging template preset is used for a message context.
Webinar schedule profile assignments select confirmation/reminder/waitlist/post-event schedules.
```

Avoid using destructive config swapping as the long-term way to test or change client behavior.

## Config template files

Current reference templates:

```text
TOKEN_REFERENCE.md
campaign-presets-template.php
client-request-intake-template.md
contact-status-presets-template.php
flow-routes-template.php
messaging-email-template.php
messaging-sms-template.php
modules-template.php
permission-invitations-template.php
presets-root-template.php
task-presets-template.php
webinar-post-event-template.php
webinar-schedule-profiles-template.php
```

`permission-invitations-template.php` is the config shape for `config/messaging/permission_invitations.php`. The broader feature/process reference remains `docs/permission-invitations.md`.

## Preferred purpose/scope pairs

- `transactional:webinar` for confirmations, reminders, join reminders, replay/recording follow-ups.
- `transactional:permission_invitation` for imported-contact one-time opt-in invitation emails.
- `marketing:webinar_nurture` for attended/missed webinar nurture campaigns.
- `marketing:webinar_waitlist` for waitlist notifications.
- `marketing:broadcast` for normal one-time Broadcast sends.
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



## Runtime artifact payload hygiene

Configs and templates should produce compact runtime artifacts.

Persisted runtime artifacts include scheduled messages, automation events, FlowRoute progress/context data, task metadata, broadcast recipient metadata, inbound/outbound message metadata, and provider event records.

Store compact data:

```text
IDs
scalar token values
compact context arrays
source_config_path
definition_config_path
stable debug/source metadata
```

Do not store accidental hydrated object graphs:

```text
full Eloquent model arrays
loaded relationship graphs
profile/item collections
provider_settings
large raw blobs outside explicit raw columns
```

For Messaging scheduled messages, `payload` should contain send-ready payload fields and compact token/context maps. Source/profile/template/debug identity belongs in `meta`.

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

```json
{"type":"import_batch","import_batch_ids":[1,2,3]}
```

`imported` means contacts where `source = import`, `meta.imported = true`, `meta.imported_at` exists, or `contact_import_batch_id` is present.

`import_batch` means contacts from specific Core-owned `contact_import_batches` records.

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

Broadcasts remain single-channel sends.

Email Broadcasts store ad hoc payload copy as:

```json
{"subject":"Subject","body":"Message body"}
```

SMS Broadcasts store ad hoc payload copy as:

```json
{"message":"SMS body"}
```

Do not use one normal Broadcast as an implicit email+SMS fanout.

## Imported-contact opt-in invitations

Imported contacts may receive exactly one permission invitation email through the special Messaging permission-invitation flow.

This is not a normal marketing Broadcast bypass.

Rules:

- The invitation send is email-only.
- The invitation uses `message_type = imported_contact_permission_invitation`.
- The invitation uses `dispatch_key = imported_contact_permission_invitation`.
- The current import-batch invitation scheduling path uses `purpose = marketing` and `scope = broadcast`, while still requiring the imported-contact permission invitation message type and consent policy.
- `contact_permission_invitations` enforces one invitation per imported contact/source/channel.
- The public preference page can let the contact opt into email, SMS, or both.
- SMS opt-in requires explicit choice and a phone number.
- Accepted channels create `MessageConsent` rows for configured scopes.
- The import-batch scheduling action belongs to Messaging and should not be implemented as Core-owned Messaging record creation.
- A normal marketing Broadcast to imported contacts still requires normal Messaging consent.

Token references are documented in `TOKEN_REFERENCE.md`.

## Config validation posture

Engage Core config validation should distinguish unsafe config from review-needed config.

Hard errors should block staging/client handoff when config cannot be safely interpreted. Examples include missing required keys, unknown registry keys, malformed schedules, invalid channel/purpose/scope combinations, missing Messaging templates referenced by Campaign presets, or permission invitation configs that break email-only one-time sending or explicit SMS opt-in.

Warnings should surface review-needed but interpretable config. Examples include deprecated tokens, optional copy/style omissions with safe defaults, planned or legacy registry keys, caller-supplied tokens that need documentation, SMS configured but hidden for a surface, or vertical-specific copy living in a generic config.

Future validation should be exposed through an operator-facing command such as:

```bash
php artisan config:validate-engage
php artisan config:validate-engage --client=client-key
```

Validation output should include severity, config path, reason, and a suggested fix when obvious.

## Module and feature scope

Config templates may reference current universal modules, but they should not imply those modules are enabled by default. `config/modules.php` controls feature visibility. Shared schema may exist even when a module is not visible to the client.

When a client asks for purchase-history targeting, service-area targeting, document collection, portal upload, or form/submission behavior, first check the owning module doc before adding config keys or templates.



