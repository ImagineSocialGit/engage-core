# Engage Core Config Authoring Guide

This guide is for creating or reviewing Engage Core default configs and client-specific configs.

Primary references:

- `config/reference/keys.php`
- `config/reference/tokens.php`
- Optional client extensions:
  - `client/{client-key}/config/reference/keys.php`
  - `client/{client-key}/config/reference/tokens.php`

## Core rules

1. Messaging configs own reusable message copy and delivery templates.
2. Campaign presets own journeys, order, timing, and references to message templates.
3. Campaign presets do not own or override reusable subject/body/CTA payloads.
4. Campaign message templates resolve by campaign key and step number, not author-created per-step message names.
5. FlowRoute presets own automation/control-flow routing and point definitions.
6. Webinar post-event config owns provider event orchestration, not message copy.
7. Task presets create DB-owned task template definitions only. They do not create live tasks.
8. Use `lead/leads` in CRM/client-facing copy unless explicitly told otherwise.
9. Do not invent new keys until checking the key registry.
10. Do not use undocumented tokens in client-facing message copy.
11. Avoid backward compatibility/legacy aliases unless explicitly chosen.

## Before creating a config

Answer these questions:

1. What module owns this behavior?
2. Is this message transactional, marketing, or internal?
3. What scope does this belong to?
4. Is there already a dispatch key for this behavior?
5. Is this a normal message definition, a campaign step template, a FlowRoute automation point, or a post-provider event?
6. Which token context applies?
7. Are any requested tokens missing from the token registry?
8. Should a new key/token be added, or should the request use an existing one?
9. Is the copy vertical-neutral or vertical-specific?
10. If it is vertical-specific, does it live under a vertical-specific scope?

## Purpose and scope decisions

Use these pairs unless there is a reason not to:

```text
transactional:webinar
    Webinar confirmations
    Webinar reminders
    Live join reminders
    Replay/recording follow-ups

marketing:webinar_nurture
    Generic attended webinar nurture campaigns
    Generic missed webinar nurture campaigns
    Generic post-webinar nurture

marketing:mortgage_homebuyer_nurture
    Mortgage-specific long-term homebuyer nurture

marketing:webinar_waitlist
    Waitlist availability messages

internal:inbound_messages
    Team-facing inbound reply notifications

internal:tasks
    Task assignment/digest notifications
```

Default webinar messaging should stay vertical-neutral.

Mortgage-specific language belongs in mortgage-specific scopes such as:

```text
marketing:mortgage_homebuyer_nurture
```

Client overrides may be vertical-specific when the client itself is vertical-specific.

## Key selection process

When a client asks for new messaging or automation:

1. Check `config/reference/keys.php`.
2. Use an existing key when behavior matches.
3. Add a new key only when behavior is meaningfully distinct.
4. If the key is client-specific, add it to the client key registry first.
5. If the key is reusable across clients, add it to the default registry.

Examples:

```text
Client asks: “Send a reminder before the webinar.”
Use existing dispatch_key: registration_created
Use the webinar reminder definitions under transactional:webinar reminders.

Client asks: “Send a replay after the webinar.”
Use existing dispatch_key: webinar_ended
Use message definitions: post_attended or post_missed

Client asks: “Send a five-email attended nurture sequence.”
Use existing dispatch_key: campaign_step_due
Create/update campaign steps in the campaign preset.
Create/update matching Messaging templates under:
marketing:webinar_nurture campaigns.{campaign_key}.steps.{step_number}

Client asks: “When someone attends, enroll them in follow-up.”
Use automation_event_key: webinar.attended
Use FlowRoute point type: enroll_campaign
Use campaign_key: webinar_attended_nurture
```

## When to add a new key

Add a new key when:

- The event/behavior is meaningfully different from an existing key.
- The runtime action needs to distinguish it.
- Config authors would otherwise overload an unrelated key.
- A future debug UI needs to show a distinct lifecycle event.

Do not add a new key when:

- Only the wording/copy changed.
- Only the schedule changed.
- Only the client brand/personality changed.
- The behavior already fits an existing dispatch key.
- A campaign journey simply needs another numbered step.

## Client-defined keys

Clients may need their own keys.

Preferred path:

```text
client/{client-key}/config/reference/keys.php
client/{client-key}/config/reference/tokens.php
```

Client keys should be clearly named and should not redefine core behavior.

Good:

```text
slam_dunk.va_buyer_follow_up
slam_dunk.credit_review_requested
rob_mortgage_coach.bridge_loan_follow_up
```

Bad:

```text
registration_created
```

if the client intends it to mean something different from the core registration-created behavior.

## Token selection process

When writing message copy:

1. Identify the dispatch context.
2. Look up the context in `config/reference/tokens.php`.
3. Use approved aliases for copywriting.
4. Use dot-notation model fields only when needed and documented.
5. Do not use meta/raw/provider fields unless explicitly exposed.
6. If a requested token is missing, decide whether to add it to the runtime payload or change the copy.

## Token source principle

Available tokens are based on intentional non-meta model fields plus friendly aliases.

Examples:

```text
{contact.first_name}
{webinar.title}
{webinar.starts_at}
```

Friendly aliases are preferred in client-facing copy:

```text
{first_name}
{webinar_title}
{webinar_start_date}
{webinar_start_time}
```

## Canonical webinar tokens

Use these for registration confirmations/reminders:

```text
{first_name}
{last_name}
{name}
{email}
{phone}
{webinar_title}
{webinar_slug}
{webinar_start_date}
{webinar_start_time}
{webinar_timezone}
{webinar_join_url}
{cancel_registration_url}
{cta}
```

Use these for post-webinar transactional follow-ups:

```text
{first_name}
{last_name}
{name}
{email}
{phone}
{webinar_title}
{webinar_slug}
{webinar_start_date}
{webinar_start_time}
{webinar_timezone}
{webinar_playback_url}
{registration_attended_at}
{cta}
```

Use these for basic campaign messages:

```text
{first_name}
{last_name}
{name}
{email}
{phone}
```

Campaign messages may use additional tokens only when the enrollment caller supplies them through payload/start_context and they are documented for that campaign/context.

## Avoid or replace these tokens

Prefer canonical replacements:

```text
{webinar_starts_at}        -> {webinar_start_date} + {webinar_start_time}
{webinar_replay_url}       -> {webinar_playback_url}
{playback_url}             -> {webinar_playback_url}
{registration_url}         -> {webinar_registration_url} or {webinar_join_url}, depending on behavior
```

Use only when explicitly supplied:

```text
{application_url}
{contact_url}
{next_step_url}
{webinar_registration_url}
```

## Messaging config locations

Messaging configs live under:

```text
config/messaging/{channel}/{purpose}/{scope}.php
client/{client-key}/config/messaging/{channel}/{purpose}/{scope}.php
```

Examples:

```text
config/messaging/email/transactional/webinar.php
config/messaging/email/marketing/webinar_nurture.php
config/messaging/email/marketing/mortgage_homebuyer_nurture.php
client/slam-dunk-crm/config/messaging/email/transactional/webinar.php
client/slam-dunk-crm/config/messaging/email/marketing/webinar_nurture.php
```

## Normal Messaging definition shape

Use this shape for non-campaign definitions such as confirmations, opt-ins, reminders, waitlist messages, and post-event transactional messages:

```php
'confirmations' => [
    [
        'dispatch_key' => 'registration_created',
        'channel' => 'email',
        'purpose' => 'transactional',
        'scope' => 'webinar',

        'conditions' => [],

        'timing' => 'scheduled',
        'payload_class' => EmailPayload::class,
        'queue' => 'confirmation_messages',

        'schedule' => [
            'type' => 'delay',
            'minutes' => 15,
        ],

        'payload' => [
            'subject' => 'Subject with {first_name}',
            'body' => 'Body with {webinar_title}',
            'cta' => [
                'label' => 'Button Label',
                'url' => '{webinar_join_url}',
            ],
        ],
    ],
],
```

For webinar transactional emails, use a uniform shape across defaults and clients:

```text
confirmations
opt_ins
reminders
post_attended
post_missed
```

Do not mix old named reminder keys with the canonical `reminders` array.

Avoid:

```text
reminder_10_day
reminder_7_day
reminder_24_hour
reminder_30_minute
reminder_10_minute
reminder_live
```

## Campaign Messaging template shape

Campaign message copy still lives in Messaging, but campaign step templates use a nested campaign path:

```text
messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}
```

Example:

```php
'campaigns' => [
    'webinar_attended_nurture' => [
        'steps' => [
            1 => [
                'dispatch_key' => 'campaign_step_due',
                'payload_class' => EmailPayload::class,
                'queue' => 'marketing',

                'payload' => [
                    'subject' => 'Thanks for joining',
                    'body' => 'Hi {first_name}, thanks for joining the webinar.',
                    'cta' => [
                        'label' => 'Continue',
                        'url' => '{next_step_url}',
                    ],
                ],
            ],
        ],
    ],
],
```

Campaign Messaging templates should not own campaign timing. Campaign presets own campaign timing.

So campaign step templates normally omit:

```text
timing
schedule
```

## Campaign preset shape

Campaign presets live under:

```text
config/presets/campaigns.php
client/{client-key}/config/presets/campaigns.php
```

Campaign presets define the journey and reference the Messaging template context.

Campaign presets must not define reusable message copy.

Campaign presets must not define or override payloads.

Campaign messages resolve by:

```text
channel + purpose + scope + campaign_key + step_number
```

Example:

```php
[
    'step_number' => 1,
    'name' => 'Attended thank-you and next step',
    'dispatch_key' => 'campaign_step_due',
    'is_active' => true,

    'criteria' => [
        'timing' => [
            'type' => 'delay',
            'hours' => 2,
        ],
    ],

    'meta' => [
        'type' => 'message',
        'message' => [
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ],
    ],
]
```

Do not require authors to invent per-step `message_type` values for campaign journey steps.

The runtime may derive debug message types such as:

```text
webinar_attended_nurture_step_1
```

That derived value is not the author-facing lookup key.

## Default vs client config behavior

Defaults should be safe, small, and vertical-neutral unless the scope is explicitly vertical-specific.

Client configs may override defaults for client-specific copy and client-specific campaign journeys.

When a client campaign preset defines a campaign with fewer steps than the default campaign, preset sync should treat the client preset as authoritative for non-customized campaigns.

Example:

```text
Default webinar_attended_nurture has 3 steps.
Slam Dunk webinar_attended_nurture has 1 step.
After sync, Slam Dunk should have 1 step, not default steps 2 and 3.
```

Default mortgage campaigns may remain active for a mortgage client until that client creates its own mortgage-specific override.

## FlowRoute config shape

FlowRoutes should reference public actions/capabilities through point definitions.

Examples:

```text
webinar.attended -> enroll_campaign(webinar_attended_nurture)
webinar.missed -> enroll_campaign(webinar_missed_nurture)
task.completed -> resume event_wait point, when configured
```

Do not make producer modules import FlowRoutes.

## Post-event config shape

Webinar post-event config should coordinate provider events:

```text
record attendance
resolve playback
dispatch transactional follow-ups
emit automation events
```

It should not own reusable message copy.

Message copy belongs in:

```text
config/messaging/email/transactional/webinar.php
config/messaging/sms/transactional/webinar.php
```

Transactional post-event follow-ups use:

```text
dispatch_key = webinar_ended
purpose = transactional
scope = webinar
```

Marketing nurture after attendance/missed outcomes should happen separately through FlowRoutes enrolling Campaigns.

## Review checklist before committing configs

- [ ] Does every config key exist in the key registry or client key registry?
- [ ] Does every token exist in the token registry or client token registry?
- [ ] Are Campaign presets free of reusable subject/body/CTA copy?
- [ ] Are Campaign presets free of payload overrides?
- [ ] Are Campaign Messaging templates under `campaigns.{campaign_key}.steps.{step_number}`?
- [ ] Are normal message configs using the canonical definition shape?
- [ ] Are webinar transactional configs using `confirmations`, `opt_ins`, `reminders`, `post_attended`, and `post_missed`?
- [ ] Are purpose/scope pairs correct?
- [ ] Are marketing webinar campaigns using `marketing:webinar_nurture`?
- [ ] Are transactional webinar messages using `transactional:webinar`?
- [ ] Is mortgage-specific copy isolated to mortgage-specific scopes or client overrides?
- [ ] Are SMS messages short and supplemental?
- [ ] Are client-specific keys clearly client-owned?
- [ ] Are new keys genuinely necessary?

## Recommended prompt for new config-generation threads

```text
We are generating Engage Core configs.

Read these first:
- config/reference/keys.php
- config/reference/tokens.php
- docs/config-authoring-guide.md

Rules:
- Use existing keys when behavior matches.
- Recommend a new key only when behavior is meaningfully distinct.
- Messaging configs own reusable message copy.
- Campaign presets own journeys/timing/template references.
- Campaign presets must not own or override payloads.
- Campaign message templates resolve by campaign_key + step_number.
- FlowRoute presets own automation/control flow.
- Webinar post-event config owns provider orchestration, not message copy.
- Default webinar copy should be vertical-neutral.
- Mortgage-specific copy should use mortgage-specific scopes or client overrides.
- Use documented tokens only.
- If the client request needs a missing token, recommend adding that token to the runtime payload or changing the copy.
- Use lead/leads in CRM/client-facing text.

Client request:
[PASTE REQUEST HERE]

Return complete config files and list any recommended new keys/tokens separately.
```