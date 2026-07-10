# Engage Core Config Authoring Guide

This guide is for creating or reviewing Engage Core default configs and client-specific configs.

Primary references:

- `config/reference/keys.php`
- `config/reference/tokens.php`
- Optional client extensions:
  - `client/{client-key}/config/reference/keys.php`
  - `client/{client-key}/config/reference/tokens.php`

## Core rules

1. Messaging configs own reusable message copy and delivery-template metadata.
2. Reusable Messaging templates do not own business timing, lifecycle conditions, sequencing, dependencies, lifecycle enablement, or module-specific skip behavior for module-owned flows.
3. The consuming module owns whether a message should exist, when it should send, and the lifecycle rules that govern it.
4. `ResolvedMessageDispatchBuilder` is the universal Messaging-owned runtime assembly seam; it combines reusable template data with behavior already resolved by the owning module and produces a `ResolvedMessageDispatch` with an exact `send_at`.
5. Resolved dispatches do not have an implicit immediate fallback. Callers must provide either exact `sendAt` or explicit caller-owned behavior.
6. Module-owned dispatch paths should provide stable `occurrenceKey` identity for retries/idempotency. The same logical occurrence keeps the same key even if `send_at` changes.
7. Campaign presets own journeys, order, timing, conditions, dependency behavior, and references to message templates.
8. Campaign presets do not own or override reusable subject/body/CTA payloads.
9. Campaign message templates resolve by `channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key`, not author-created per-step message names.
10. Campaign step variants reference Messaging-owned template presets/assignments and must not own reusable payload copy.
11. Messaging template presets own reusable copy and safe DB-editable message payloads.
12. Messaging template catalog entries own browsing/grouping metadata for template review; they do not own runtime behavior.
13. Webinar schedule profiles/profile items own all Webinar lifecycle message timing, schedules, conditions, enablement, and Webinar-specific skip behavior, including immediate lifecycle messages.
14. FlowRoute presets own automation/control-flow routing and reusable point definitions; reaching a `send_message` point determines when that action occurs unless the point itself explicitly owns additional behavior.
15. Broadcasts own their exact `send_at`, audience, channel choice, and batch intent.
16. Webinar post-event config owns provider event orchestration, not message copy.
17. Task presets create DB-owned task template definitions only. They do not create live tasks.
18. Internal/runtime identifiers must use the universal platform concept `contact`, never `lead`, unless a vertical truly owns a distinct domain concept named lead. This applies to keys, preset identifiers, task-template keys, route keys, event keys, triggers, reference registries, config paths, and generic definitions.
19. Client-facing UI/copy may use the configured business noun such as Lead, Customer, Fan, Borrower, Owner, or another client/vertical label. Display terminology must not redefine internal identifiers.
20. Do not invent new keys until checking the key registry and the actual owning config/runtime definitions.
21. Do not use undocumented tokens in client-facing message copy.
22. Avoid backward compatibility/legacy aliases unless explicitly chosen.
23. Normal Broadcasts require normal Messaging consent. Do not use Broadcasts as a general imported-contact consent bypass.
24. Imported-contact opt-in invitations are a distinct one-time Messaging flow with configurable public copy/style.
25. SMS capabilities may exist in code while SMS UI options are hidden by client/surface config.
26. Module docs are the source of truth for module ownership and client-facing scope. Configs should not create a module feature that the owning module does not support.
27. Commerce and Location configs should support admin convenience and integrations; do not turn Engage Core into a storefront, checkout, GIS, routing, or map product.
## Universal internal terminology vs configured client nouns

Engage Core has one universal internal person concept: `Contact`.

Use `contact` for internal/runtime identifiers such as:

```text
config keys
preset keys
task template keys
FlowRoute keys and point keys
automation event keys
trigger keys
reference registry keys
payload/context field names
service/action/DTO names when the concept is generic
```

Do not create universal identifiers such as:

```text
new_lead
call_lead
review_lead_notes
lead.converted
lead_follow_up_route
```

Prefer:

```text
new
call_contact
review_contact_notes
contact.converted, only when that event is truly Core-owned and supported
contact_follow_up_route
```

Client-facing UI and copy may use the configured business noun. Examples:

```text
Lead
Customer
Fan
Borrower
Pet owner
Member
```

That display choice must stay at the presentation/copy layer and must not leak into generic runtime identifiers. Vertical modules may use vertical-owned terminology only for real vertical concepts, not as aliases for Core Contact.

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
11. Are internal keys/identifiers still universal and `contact`-based even when client-facing copy uses another noun?

## Purpose and scope decisions

Use these pairs unless there is a reason not to:

```text
transactional:webinar
    Webinar confirmations
    Webinar reminders
    Live join reminders
    Replay/recording follow-ups

transactional:permission_invitation
    One-time imported-contact opt-in invitation emails
    Public preference confirmation flow

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
marketing:webinar_nurture campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}

Client asks: “When someone attends, enroll them in follow-up.”
Use automation_event_key: webinar.attended
Use FlowRoute point type: enroll_campaign
Use campaign_key: webinar_attended_nurture
```


Client asks: “We imported contacts and need them to confirm whether they want email or SMS.”
Use dispatch_key: imported_contact_permission_invitation
Use purpose/scope: transactional:permission_invitation
Use config/messaging/permission_invitations.php for public page and email CTA copy/style.
Do not treat this as a normal marketing Broadcast bypass.

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

## Canonical Contact fields and client-facing aliases

Internal field identity should remain canonical and universal.

Use canonical Contact fields internally, for example:

```text
contact.first_name
contact.last_name
contact.email
```

Client/operator authoring UI may expose friendly aliases based on the configured business noun, for example:

```text
lead_first_name
fan_first_name
customer_first_name
```

Those aliases are presentation/authoring conveniences only. They should normalize through one documented seam to the canonical Contact field before validation/rendering or otherwise resolve unambiguously to that canonical field.

Do not create separate runtime payload fields, database columns, event keys, preset keys, or validation concepts for Lead, Fan, Customer, Borrower, Owner, or similar presentation nouns when they represent the same Core Contact field.

The available-field source of truth should be able to expose a canonical key, client-facing label, accepted aliases, owning provider/module, available contexts, and runtime source.

## Token selection process

When writing message copy:

1. Identify the dispatch context.
2. Look up the context in `config/reference/tokens.php`.
3. Use approved aliases for copywriting.
4. Use dot-notation model fields only when needed and documented.
5. Do not use meta/raw/provider fields unless explicitly exposed.
6. If a requested token is missing, decide whether to add it to the runtime payload or change the copy.

## Token source principle

Available tokens come from explicit message data.

Token sources are layered:

```text
Universal Messaging / Contact message data
    Always available when the recipient is a Contact.

Producer module message data
    Available only when that module supplies its data object.

Caller/enrollment/start-context payload
    Available only when the action that starts the message or journey passes it.
```

Messaging owns universal Contact-recipient tokens.

Producer modules own module-specific message data objects.

Examples:

```text
Webinars
    WebinarMessageData

Scheduling, future
    SchedulingMessageData

Tasks, future
    TaskMessageData

Mortgage, future
    MortgageMessageData
```

Campaigns do not invent message tokens. Campaigns may carry enrollment/start payload forward and pass it into Messaging, but the payload must come from the producer/caller that enrolled the contact or started the journey.

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

## Universal Contact tokens

Use these when the recipient is a Contact:

```text
{first_name}
{last_name}
{name}
{email}
{phone}
{contact.first_name}
{contact.last_name}
{contact.name}
{contact.email}
{contact.phone}
```

## Canonical webinar tokens

Use these for registration confirmations/reminders when the Webinars dispatch path supplies `WebinarMessageData::fromRegistration(...)`:

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

Use these for waitlist availability messages when the Webinars waitlist path supplies `WebinarMessageData::fromWaitlistSignup(...)`:

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
{webinar_registration_url}
{cta}
```

Use these for post-webinar transactional follow-ups when Webinars resolves playback/follow-up data:

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

Campaign messages may use additional tokens only when the enrollment caller supplies them through payload/start context and they are documented for that campaign/context.

## Avoid or replace these tokens

Prefer canonical replacements:

```text
{webinar_starts_at}        -> {webinar_start_date} + {webinar_start_time}
{webinar_replay_url}       -> {webinar_playback_url}
{playback_url}             -> {webinar_playback_url}
{registration_url}         -> {webinar_registration_url} or {webinar_join_url}, depending on behavior
```

Use only when explicitly supplied by runtime payload/start context:

```text
{application_url}
{contact_url}
{next_step_url}
{webinar_registration_url}
```

Default campaign/nurture configs should not include runtime-only URL tokens unless the default preset also documents and supplies the source payload.

If a requested token is missing, either:

1. add the token to the owning runtime message data object; or
2. change the copy to use currently documented tokens.

Do not guess URLs in static config.

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
config/messaging/permission_invitations.php
client/slam-dunk-crm/config/messaging/email/transactional/webinar.php
client/slam-dunk-crm/config/messaging/email/marketing/webinar_nurture.php
```

## Normal Messaging template definition shape

Messaging config definitions are reusable content/delivery templates. They should contain only template identity, delivery-template metadata, and reusable payload copy.

Example:

```php
'confirmations' => [
    [
        'key' => 'confirmation',
        'dispatch_key' => 'registration_created',
        'message_type' => 'confirmation',
        'channel' => 'email',
        'purpose' => 'transactional',
        'scope' => 'webinar',
        'payload_class' => EmailPayload::class,
        'queue' => 'confirmation_messages',
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

For module-owned flows, do not put these fields on reusable Messaging templates:

```text
timing
schedule
conditions
lifecycle enablement
sequencing/dependencies
module-specific skip rules
```

Those values belong to the module that owns the lifecycle. Examples:

```text
Webinars
    WebinarScheduleProfile / WebinarScheduleProfileItem

Campaigns
    CampaignStep / CampaignStepVariant

Broadcasts
    Broadcast.send_at and Broadcast-owned audience/batch behavior

FlowRoutes
    FlowRoutePoint and route execution state

Tasks / InternalNotifications
    task/digest trigger and notification scheduling behavior
```

The owning module resolves its business behavior first and passes the reusable template plus resolved behavior to `ResolvedMessageDispatchBuilder`. The resulting `ResolvedMessageDispatch` should contain an exact `send_at`; Messaging should not infer module lifecycle timing from template config.

A missing module-owned behavior record must not silently fall back to an implicit immediate send or hidden template schedule. Treat missing required behavior as validation/runtime setup failure according to the owning module's contract.

At runtime, some owning-module resolvers may attach transient `resolved_behavior` and `behavior_owner` values to a resolved definition before `DispatchMessageAction` runs. Those are runtime handoff values, not reusable template fields. `DispatchMessageAction` consumes them before the content-only template reaches `ResolvedMessageDispatchBuilder`.

Stable occurrence identity is also caller-owned. Use a stable `occurrenceKey` for the same logical message occurrence across retries or send-time recalculation. Do not use `send_at` as the sole occurrence identity.


For webinar transactional emails, use a uniform reusable-content shape across defaults and clients:

```text
confirmations
opt_ins
reminders
post_attended
post_missed
```

Do not encode reminder timing into schedule-specific `message_type` values. Multiple reminder slots may share `message_type = reminder`; Webinar schedule profile items identify the specific lifecycle slots.

## Messaging template presets, catalog entries, and assignments

The target architecture stores reusable message definitions in DB-owned Messaging template presets.

Config remains the seed/source for available presets.

Use this conceptual split:

```text
MessageTemplatePreset
    reusable content/delivery template

MessageTemplateCatalogEntry
    browsing/grouping record for the Messaging template catalog

MessageTemplatePresetAssignment
    selected preset for a runtime message context
```

`MessageTemplateCatalogEntry` is not a message group owner and is not a runtime behavior record. It exists so CRM/admin UI can browse related templates cleanly by channel, purpose, module/surface, group, and item.

Examples of catalog paths:

```text
Email -> Marketing -> Campaigns -> Webinar Attended Nurture -> Step 1 Email
Email -> Transactional -> Webinars -> Webinar Reminders -> 30-Minute Reminder Email
SMS -> Marketing -> Campaigns -> Mortgage Homebuyer Nurture -> Step 1 SMS
```

Catalog entries should be generated from stable source definition context such as:

```text
channel
purpose
scope
surface
message_type
campaign_key
campaign_step
source_config_path
```

Assignments should be structured by stable runtime dimensions such as:

```text
channel
purpose
scope
surface
message_type
campaign_key
campaign_step
campaign_step_variant_key
source_config_path
context_type / context_id
```

Do not replace this with opaque string-path guessing.

Sync should not overwrite DB-customized message copy unless explicitly forced.

Catalog entries may be regenerated from config/source context because they are browsing metadata, not edited message copy.

Template assignment changes belong in the consuming module setup screen when practical:

```text
Campaign step editor
Webinar schedule/profile editor
Automatic Follow-ups / send-message point editor
```

The Messaging template editor should primarily edit/review reusable copy and show read-only usage links.

## DB-owned definition sync semantics

Do not assume all preset families sync identically.

Current contract:

```text
ContactStatus
    preserve customized on normal sync
    force supported

TaskTemplate
    preserve customized on normal sync
    force supported

MessageTemplatePreset
    preserve customized on normal sync
    force supported
    remove stale config-owned non-customized presets
    preserve customized/manual presets

Webinar schedule profiles/items
    preserve customized on normal sync
    force supported
    deactivate stale non-customized items
    preserve stale customized items

Campaigns/Steps/Variants
    preserve customized on normal sync
    remove stale non-customized nested definitions where authoritative
    no force mode

FlowRoute capabilities
    preserve customized rows

FlowRoutes/Points/FlowRoutePoints
    preserve customized definitions according to route semantics
    force supported
```

Use the owning module's actual sync contract. Do not invent a force option for symmetry.

For FlowRoutes, stable `FlowRoute.key` identifies the logical route and `version` identifies a revision. `is_current_version` selects the current revision. New starts use that revision, while active/waiting instances on older revisions reconcile by durable route-point key. An unmappable current/waiting point is a hard conflict.

## Campaign Messaging template shape

Campaign message copy still lives in Messaging, but campaign step variant templates use a nested campaign path:

```text
messaging.{channel}.{purpose}.{scope}.campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}
```

Example:

```php
'campaigns' => [
    'webinar_attended_nurture' => [
        'steps' => [
            1 => [
                'variants' => [
                    'email' => [
                        'dispatch_key' => 'campaign_step_due',
                        'payload_class' => EmailPayload::class,
                        'queue' => 'marketing',

                        'payload' => [
                            'subject' => 'Thanks for joining',
                            'body' => 'Hi {first_name}, thanks for joining the webinar. Reply with your biggest question and we’ll help you with the next step.',
                        ],
                    ],
                ],
            ],
        ],
    ],
],
```

Do not include CTA URL tokens such as `{next_step_url}`, `{application_url}`, `{contact_url}`, or `{webinar_registration_url}` in default campaign copy unless the campaign enrollment/start path explicitly supplies them.

When a campaign truly needs a CTA, document the source payload and ensure Messaging unresolved-token validation will block sending if the URL is missing.

Campaign Messaging templates should not own campaign timing. Campaign presets own campaign timing.

So campaign step templates normally omit:

```text
timing
schedule
```

## Campaign channel variants

Campaign presets define step groups and channel variants.

A step group is a business moment.

A variant is a channel-specific delivery option for that moment.

Variants should include:

```text
channel
purpose
scope
timing
message template reference or assignment key
dependency rules, when needed
strategy participation
```

Variants must not include reusable subject/body/message copy.

Initial strategies:

```text
first_available
send_all_eligible
dependency_aware
```

Use `first_available` when email/SMS are alternatives.

Use `send_all_eligible` when each eligible channel should send.

Use `dependency_aware` when one variant depends on another variant being scheduled or sent.

## Campaign preset shape

Campaign presets live under:

```text
config/presets/campaigns.php
client/{client-key}/config/presets/campaigns.php
```

Campaign presets define the journey and reference the Messaging template context.

Campaign presets must not define reusable message copy.

Campaign presets must not define or override payloads.

Campaign preset variants reference Messaging templates with first-class variant keys:

```text
key
dispatch_key
channel
purpose
scope
```

Do not use `meta.message` for new Campaign preset step or variant message references.

Campaign messages resolve by:

```text
channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key
```

Example:

```php
[
    'step_number' => 1,
    'name' => 'Attended thank-you and next step',
    'variant_strategy' => 'first_available',
    'is_active' => true,

    'criteria' => [
        'timing' => [
            'type' => 'delay',
            'hours' => 2,
        ],
    ],

    'variants' => [
        [
            'key' => 'email',
            'name' => 'Email follow-up',
            'dispatch_key' => 'campaign_step_due',
            'channel' => 'email',
            'purpose' => 'marketing',
            'scope' => 'webinar_nurture',
        ],
    ],

    'meta' => [
        'type' => 'message',
    ],
]
```

Do not require authors to invent per-step `message_type` values for campaign journey steps.

The runtime may derive debug message types such as:

```text
webinar_attended_nurture_step_1
```

That derived value is not the author-facing lookup key.


## Campaign preset sync force behavior

Campaign preset sync intentionally has no force mode right now.

Global preset sync may force FlowRoutes, Tasks, Message Templates, and Webinar schedule profiles where those modules explicitly support force behavior. Campaigns are different because a Campaign is a client-editable journey structure with nested steps and variants.

Current Campaign sync behavior:

```text
create/update non-customized Campaigns
create/update non-customized CampaignSteps
create/update non-customized CampaignStepVariants
remove stale non-customized steps/variants when the preset is authoritative
preserve customized Campaigns, CampaignSteps, and CampaignStepVariants
```

Do not document or use `--force-campaigns` unless a future branch deliberately implements destructive Campaign overwrite semantics.

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

## Client config fallback and validation

Client config overrides should be treated as partial overrides unless a feature explicitly documents that a section is authoritative.

Expected fallback behavior:

- Missing client config files use the default config.
- Client associative-array overrides merge over default config without dropping unspecified nested keys.
- Numeric arrays replace the default array when present. This is intentional for ordered definitions and lists such as `consent.scopes`.
- Missing optional public-page `content` or `style` keys should fall back to safe defaults or render without breaking the page.
- Client copy changes should not break behavioral tests unless exact copy is the behavior under test.

Before accepting a client config, validate:

- Required top-level keys for that config type are present or supplied by default fallback.
- Unsupported keys are rejected, flagged, or intentionally ignored with clear operator/debug feedback.
- Dispatch keys exist in `config/reference/keys.php` or the client key registry.
- Tokens/available fields exist in `config/reference/tokens.php`, the client token registry, or the owning module's available-field provider.
- Runtime-only URLs are supplied by runtime payloads/services and are not guessed in static config.
- SMS channel visibility uses Messaging channel availability for the relevant surface instead of one-off provider checks.
- Permission invitation configs preserve email-only bypass sending, explicit SMS opt-in, and configured consent scopes.

Validation should protect authoring mistakes without turning optional style/copy omissions into runtime failures.



## Dashboard layout config

Dashboard layout config owns panel placement, not panel data.

Use `config/modules.php` dashboard config for:

```text
slots
slot order
max panels per slot
hide_when_empty behavior
preset-specific panel order
preset-specific panel priorities
```

Modules own the code that creates panel data.

Dashboard config should reference stable panel keys such as:

```text
tasks.today
inbound_messaging.replies
webinars.activity
campaigns.movement
broadcasts.recent
```

Do not create DB-owned dashboard layout state unless a concrete client workflow requires runtime customization by an operator.

Do not make disabled modules visible by listing their panels in dashboard config. Panel visibility still follows explicit module enablement and provider registration.

Actionable panels may use `hide_when_empty = false` so the user sees a calm caught-up state. Passive context panels should generally hide when empty.

## Module tone config

Module tones are UI wayfinding config, not semantic state.

Tone entries may provide muted classes for:

```text
panel
panel_focus
item
item_focus
jump
badge
nav
rail
text
```

Use soft borders, background washes, rings, and badges. Do not use tone config for urgency. Overdue, failed, blocked, skipped, or business-critical states should apply separate severity styling that visually wins over module tone.

## Runtime-selectable FlowRoutes

FlowRoute configs should define available route definitions.

Runtime route selection happens through `FlowRouteTriggerBinding`, not by assuming every active matching route should run.

Contact-status triggers normally select one route per context.
Automation-event triggers may select multiple routes per context.

`FlowRoute.is_active` means available/allowed.

A trigger binding means selected for a trigger/context.

FlowRoute ownership should use:

```text
owner_type
owner_id
owner_group
```

Do not use Task `responsible_party` for FlowRoute ownership.


### Automatic Follow-ups UX exploration before config expansion

Before adding new author-facing FlowRoute config keys for Automatic Follow-ups UI, answer the product questions for the surface.

Do not add config just to support a premature builder.

Questions to settle first:

```text
Is the UI selecting existing routes only, editing route points, or both?
Which route point summaries must be derivable for consequence previews?
Which route point types are client-safe, operator-only, or developer-only?
How should module-disabled point types be hidden or explained?
How should send-message points reference Messaging template assignments?
How should selected routes be grouped for a business activity such as webinar.attended?
```

## FlowRoute config shape

FlowRoutes should reference public actions/capabilities through point definitions.

Examples:

```text
webinar.attended -> enroll_campaign(webinar_attended_nurture)
webinar.missed -> enroll_campaign(webinar_missed_nurture)
task.completed -> resume event_wait point, when configured
```

Do not make producer modules import FlowRoutes.

## Webinar schedule profiles

Webinar schedule configs may evolve into selectable schedule profiles.

Schedule profiles decide when webinar-owned messages are sent.

Messaging template presets decide what those messages say.

A webinar series or webinar may later choose profiles such as:

```text
full 10-day schedule
smoke fast schedule
last-minute only schedule
no reminders
```

Schedule profile configs should reference reusable Messaging templates through stable `message_template_key` identity plus the other required runtime dimensions. They must not embed reusable copy. `source_config_path` may be retained as provenance/debug location only and must not be the durable matching identity.

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


## Imported-contact permission invitation config

Imported-contact opt-in invitations are authored separately from normal Broadcast copy.

Use:

```text
config/messaging/permission_invitations.php
client/{client-key}/config/messaging/permission_invitations.php
```

Template reference:

```text
docs/config-templates/permission-invitations-template.php
```

This config owns the permission invitation email subject/body/CTA labels, public preference-page copy, accepted consent scopes, and Tailwind-style class strings for the public page.

Expected top-level shape:

```php
return [
    'email' => [
        'subject' => 'Confirm how you want to hear from us',
        'body' => 'Hi {first_name}, please confirm your communication preferences so we know how you want to hear from us.',
        'cta_label' => 'Confirm my preferences',
        'secondary_link_label' => 'Or copy and paste this link into your browser',
    ],

    'consent' => [
        'scopes' => ['broadcast', 'campaign'],
    ],

    'content' => [
        'title' => 'Confirm how you want to hear from us',
        'heading' => 'Choose how you want to hear from us.',
        'body' => '...',
        'options' => [
            'email' => ['label' => 'Email updates', 'body' => '...'],
            'sms' => ['label' => 'Text message updates', 'body' => '...'],
        ],
        'legal' => '...',
        'accepted_heading' => 'Your preferences are confirmed.',
    ],

    'style' => [
        'section' => '...',
        'inner' => '...',
        'card' => '...',
        'button' => '...',
    ],
];
```

The invitation email should include `{cta}` on its own line when the button should render in the body. The runtime injects `cta.url` and `secondary_link.url` using the invitation token.

Do not hand-author public preference URLs in client copy.

## SMS visibility and availability

SMS provider code and SMS-capable runtime infrastructure may exist even when SMS is not exposed to a client in the UI.

Client/surface config should control whether SMS appears as an option in:

- Broadcast builders
- Campaign builders
- Permission invitation pages
- Other future route/message builders

Hiding SMS in UI does not remove SMS provider integrations, inbound STOP/HELP handling, consent models, or runtime safety checks.

SMS opt-in must be explicit. Do not infer SMS consent from imported contacts, email consent, or general preference confirmation.

The canonical runtime seam for UI/channel choices is Messaging channel availability.

Do not add one-off `sms.enabled` checks directly inside individual views or controllers.

Surfaces should ask the Messaging channel availability service which channels are available for that surface.

Broadcasts are single-channel sends.

When standard SMS Broadcast authoring is exposed, the Broadcast builder should ask Messaging channel availability for the `broadcasts` surface and then let the operator choose one available channel.

Expected payload shapes:

```text
Email Broadcast:
    channel = email
    payload.subject
    payload.body

SMS Broadcast:
    channel = sms
    payload.message
```

Permission invitation Broadcasts are an exception: the one-time imported-contact permission invitation send remains email-only, even if the public preference page can offer SMS opt-in.

Do not model a normal Broadcast as default email+SMS fanout.

Do not add multi-channel fallback behavior to Broadcasts without a deliberate channel-strategy design.

Current canonical channel availability surface keys are:

```text
broadcasts
campaigns
permission_invitations
webinar_registrations
webinar_waitlists
internal_notifications
route_send_message_points
```

Use plural noun-phrase surface keys for channel availability.

Do not rename singular scope/source/message/context keys just because a matching surface key is plural.

Examples that should remain singular:

- `scope = permission_invitation`
- `scope = webinar_waitlist`
- `source = webinar_registration`
- `source = webinar_waitlist`
- `transactional:permission_invitation`
- `marketing:webinar_waitlist`
- `imported_contact_permission_invitation`
- `webinar_registration` payload/context keys
- `webinar_waitlist_signup` payload/context keys

## Review checklist before committing configs

- [ ] Does every config key exist in the key registry or client key registry?
- [ ] Are unsupported keys rejected, flagged, or intentionally ignored with clear operator/debug feedback?
- [ ] Does every token/available field exist in the token registry, client token registry, or owning module available-field provider?
- [ ] Are runtime-only URLs/tokens supplied by runtime payloads/services instead of static config guesses?
- [ ] Do missing optional content/style keys fall back safely?
- [ ] Do client config overrides preserve unspecified nested defaults where fallback is expected?
- [ ] Are Campaign presets free of reusable subject/body/CTA copy?
- [ ] Do campaign variants reference Messaging-owned template presets/assignments rather than owning copy?
- [ ] Are Campaign presets free of payload overrides?
- [ ] Are Campaign preset variant message references first-class `key`, `dispatch_key`, `channel`, `purpose`, and `scope` keys?
- [ ] Are new Campaign preset steps free of `meta.message` references?
- [ ] Are Campaign Messaging templates under `campaigns.{campaign_key}.steps.{step_number}.variants.{variant_key}`?
- [ ] Are normal message configs using the canonical definition shape?
- [ ] Are DB-backed MessageTemplatePreset, MessageTemplateCatalogEntry, and MessageTemplatePresetAssignment rules preserved when applicable?
- [ ] Are webinar transactional configs using `confirmations`, `opt_ins`, `reminders`, `post_attended`, and `post_missed`?
- [ ] Are purpose/scope pairs correct?
- [ ] Are marketing webinar campaigns using `marketing:webinar_nurture`?
- [ ] Are transactional webinar messages using `transactional:webinar`?
- [ ] Is mortgage-specific copy isolated to mortgage-specific scopes or client overrides?
- [ ] Are SMS messages short and supplemental?
- [ ] Is SMS UI exposure controlled by config when relevant?
- [ ] Are imported-contact opt-in invitations separated from normal Broadcasts?
- [ ] Does permission invitation copy avoid hand-authored preference URLs?
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
- Campaign preset variants reference Messaging templates with first-class key/dispatch_key/channel/purpose/scope keys.
- Do not use meta.message for new Campaign preset step message references.
- Campaign message templates resolve by channel + purpose + scope + campaign_key + step_number + campaign_step_variant_key.
- FlowRoute presets own automation/control flow.
- Webinar post-event config owns provider orchestration, not message copy.
- Default webinar copy should be vertical-neutral.
- Mortgage-specific copy should use mortgage-specific scopes or client overrides.
- Use documented tokens only.
- If the client request needs a missing token, recommend adding that token to the runtime payload or changing the copy.
- Use the configured client-facing business noun in UI/copy when appropriate, but keep internal keys/identifiers universal and `contact`-based.
- Keep SMS options config-toggleable in UI.
- Keep imported-contact permission invitations separate from normal Broadcasts.

Client request:
[PASTE REQUEST HERE]

Return complete config files and list any recommended new keys/tokens separately.
```





## Setup validation architecture

Setup validation is reusable infrastructure, not a one-off Artisan command full of module-specific conditionals.

Implemented architecture:

```text
SetupValidationManager
    -> tagged setup.validation_contributors
        -> module-owned contributors
        -> app-level module-dependency contributor
        -> app-level reference-registry drift contributor
        -> adapters around existing validators such as MessageConfigValidator
    -> structured SetupValidationFinding records in memory
    -> SetupValidationResult
        -> setup:validate CLI output now
        -> staging/client handoff blocking now
        -> future authoring UI, readiness screens, and builder feedback later
```

Run the non-mutating readiness check with:

```bash
php artisan setup:validate
```

Errors return failure and block staging/client handoff. Warnings remain non-blocking. Clean results return success. Findings are not persisted by default.

The shared finding shape should be stable enough for CLI and UI consumers. At minimum, audit these fields:

```text
severity        error | warning
code            stable machine-readable identifier
message         actionable human-readable explanation
source          owning config/preset/module source
path            precise config/reference path when available
module          owning or affected module when applicable
context         authoring/runtime context when applicable
meta            compact diagnostic details only when useful
```

Do not persist validation findings by default. Add validation-history tables only if a concrete operator/setup workflow needs retained runs, acknowledgements, audit history, or comparison over time.

### Validation ownership

Each module should validate the config/preset concepts it owns.

Examples:

```text
Tasks
    Task preset shape
    Task template definitions
    Task template keys/references

FlowRoutes
    FlowRoute preset shape
    Point types
    Capability references
    Task template / Campaign / Messaging references used by points
    Supported subject types
    Route instance/snapshot assumptions

Campaigns
    Campaign preset shape
    Step/variant strategy
    Variant dependency rules
    Messaging template context references

Messaging
    Message definition shape
    Template payloads
    tokens/available fields by authoring/runtime context
    channel/purpose/scope and template-assignment compatibility
```

The central manager orchestrates validation. It should not absorb every module's private config parser or model rules.

### Hard errors vs warnings

Use this decision rule:

```text
Would the selected/current setup make intended runtime behavior invalid, impossible, ambiguous, or unsafe?
    Yes -> hard error.

Is the setup safe but unused, dormant, unavailable by choice, deprecated-but-resolvable, or merely surprising?
    Yes -> warning.
```

Typical hard errors:

```text
Selected preset package does not exist.
Enabled module key is unknown or required dependency cannot be resolved.
FlowRoute references an unknown/unregistered point type.
FlowRoute references a missing TaskTemplate, Campaign, required Messaging template context, or required capability.
A configured point requires a module/handler that is unavailable, so runtime cannot execute it safely.
Campaign variant strategy/dependency shape is invalid.
A field/token is not valid for the authoring/runtime context that will render it.
A config assumes subject/plan-item behavior the current runtime cannot support.
```

Typical warnings:

```text
A valid template/capability exists but is unused.
A template exists but is not currently assigned.
An optional route preset is available but has no selected trigger binding.
An optional channel/module definition is safely dormant because the client has not enabled that surface.
A discouraged legacy authoring shape still resolves safely and is intentionally tolerated.
```

Hard errors should fail the validation command and block staging/client handoff. Warnings should remain non-blocking but actionable.

### Reference-source authority

Do not use a stale registry as the sole runtime truth.

Use the owning source of truth for executable references:

```text
Task template reference
    -> actual configured/synced TaskTemplate definitions

FlowRoute point type
    -> registered PointHandler/runtime point catalog

FlowRoute capability reference
    -> DB-owned capability catalog/bindings plus handler/module availability

Campaign reference
    -> actual configured/synced Campaign definitions

Messaging template reference
    -> actual Messaging config/preset/assignment context

Available field/token
    -> context-aware registry/provider plus the runtime data source that can actually supply it
```

`config/reference/keys.php` and `config/reference/tokens.php` remain important authoring/reference registries. Setup validation detects reliable registry drift where an owning executable/config source exists, but does not incorrectly reject a valid executable reference merely because a stale documentation registry was treated as the only truth.

### Completed Phase 6 implementation order

Use this order so code is not built around inconsistent docs/configs:

```text
1. Audit and normalize docs.
2. Normalize current default/client configs against the docs.
3. Audit migrations/models against normalized config requirements.
4. Add/replace schema only for proven durable first-class concepts.
5. Implement contributor-based validation/runtime code.
6. Run fast migration/schema checks first when schema changed.
7. Add focused tests, adjacent-module tests, and broader config fallback/preset coverage.
```

Do not use `meta` to avoid adding a proven first-class field. Do not add schema merely to persist validation output.

## Task and FlowRoutes preset validation additions

Task presets create DB-owned task template definitions only. They do not create live tasks.

Task templates must remain generic and reusable. Vertical modules may contribute task template presets, labels, and defaults without making Tasks vertical-specific.

FlowRoute presets own automation/control-flow route definitions and point definitions.

FlowRoute `create_task` points should reference TaskTemplate records or stable task template keys once task-template support is confirmed.

FlowRoute presets should call other module capabilities through public actions/services/contracts, not private table internals.

FlowRoute point capabilities should be DB-owned before production. Capability and capability-binding records are the durable authoring/validation layer for module/vertical actions, waits, conditions, events, labels, supported subject types, inputs, and output context.

Route instance plans are part of the durable FlowRoutes runtime model. Presets may assume that reusable templates seed contact/subject-specific plans with plan items and progress/execution items.

Config/setup validation should check:

```text
Task preset shape.
Task template references from FlowRoute presets.
FlowRoute point types.
Module capability references.
Vertical references.
Campaign references.
Messaging template references.
Unsupported point/module combinations.
Route instance/snapshot assumptions when FlowRoute plans are introduced.
```

Validation should classify findings as hard errors or warnings.

Hard errors should block preset sync, staging handoff, or client launch when runtime behavior would be unsafe.

Warnings should provide useful operator/debug guidance without blocking safe runtime behavior.

Use the contributor-based validation manager/service as the reusable source of truth. Expose it through an Artisan command first, and let future authoring/readiness UI consume the same structured findings. Do not add persistent validation result tables unless a concrete workflow proves retained validation history is needed.

## FlowRoutes capability reference rule

FlowRoute presets should reference durable capabilities when a point represents an authorable action, wait, event, condition, branch, or module/vertical behavior.

The preferred preset authoring shape is:

```text
capability_key
point type
handler key
public action/service contract
module key
optional task template key
optional message/campaign/template reference
optional supported subject type
```

Capability records describe what is available, how it should be labeled, what inputs are required, what subject types are supported, and what output context/fields become available. Point definitions still carry the route-specific configuration.

Avoid route preset shapes that require FlowRoutes to know vertical private model/table details.

Good:

```text
capability_key: tasks.create_task
point: create_task
task_template_key: pet_services.final_behavior_check
```

Good:

```text
capability_key: campaigns.enroll
point: enroll_campaign
campaign_key: webinar_attended_nurture
```

Bad:

```text
point: create_pet_behavior_appointment
pet_behavior_goal_id: 123
```

unless PetServices exposes that behavior as a public capability/action with documented input schema and supported subject types.

## Route instance/snapshot validation rule

Route presets and vertical presets may assume the durable FlowRoutes instance model once Phase 4B is implemented. Validation should check that route-instance assumptions are supported by schema and runtime.

Supported durable assumptions should include:

```text
reusable route templates seed contact/subject-specific plans
operators may later insert/repeat/skip/cancel one plan item without changing the template
event waits and task completion can target specific plan/progress items
created appointments/tasks/messages/campaign enrollments attach back to one route plan/progress item
active route instances preserve point definition/settings snapshots
```

Validation should flag any preset that references a capability, subject type, template, campaign, message assignment, or module action that is unavailable for the current module/client context.

## Available field/token picker and validation

Authoring UI should not make operators memorize token syntax.

Client/operator screens should prefer labels such as:

```text
Insert field
Add field
Available fields
```

instead of making `token` the primary word.

The stored syntax should be stable, explicit, and unlikely to conflict with normal copy. Preferred UI insertion syntax:

```text
{{ first_name }}
{{ webinar_title }}
{{ contact.email }}
```

Runtime may normalize this to the internal token format before validation/rendering, but the source of truth must remain context-aware.

Available fields should come from explicit sources:

```text
Universal Contact/recipient message data
Owning module message data objects
Caller/enrollment/start-context payload
Module-provided available-field registries/providers
Client token registries for documented client-specific extensions
```

Do not invent a selectable field in UI or config unless the owning runtime payload/data object/provider can actually supply it.

Setup validation should check:

```text
field/token exists for the current context
field/token is supplied by the runtime path that will send/render the content
runtime-only URL fields are supplied by runtime payload/service, not guessed in static config
DB-customized template copy follows the same token rules as config copy
unsupported fields produce actionable validation messages
```

Potential provider shape to audit later:

```text
AvailableFieldProvider
AvailableFieldRegistry
AvailableFieldContext
AvailableFieldOption
```

Do not build a polished autocomplete editor before the available-field source of truth and validation behavior are settled.

## Human-readable schedule summaries

Config may store timing in canonical runtime shapes, but client/operator UI should show timing in business language.

Examples:

```text
Sends 10 days after the webinar.
Sends 2 weeks after the previous message.
Sends immediately after someone attends.
Sends when this route reaches the step.
```

Avoid exposing raw runtime timing as the primary label:

```text
Delay 10 minutes
schedule.type = delay
criteria.timing.days = 3
```

Campaigns, Webinars, and FlowRoutes may each need schedule-summary helpers because their anchors differ.

Potential summary inputs:

```text
schedule type
delay/offset amount
anchor source
previous step / route point / webinar start / event occurrence
business context label
```

Do not persist schedule summary text unless a concrete reason appears. Prefer deriving it from the canonical schedule/profile/criteria definition.
