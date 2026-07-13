# Engage Core Messaging Token Reference

## Executable authority

This page explains the token contract, but the executable source of truth is
`App\Support\TokenContracts\TokenContractRegistry`. Module service providers register
`TokenSourceProvider`, `TokenContextProvider`, and, where necessary,
`ComputedTokenValueProvider` implementations.

A model-backed token must correspond to a real, non-sensitive table column. Do not expose
arbitrary `meta`, raw provider payloads, credentials, join/playback tokens, or provider settings
as general authoring tokens. A derived value must have an explicit computed-value provider and a
producer context that guarantees it can be resolved.

The registry currently includes Contact, Messaging, Campaign, and Webinar sources and contexts.
Use it for selectable fields and validation; do not maintain a separate UI-only token list.


`MessageTemplateTokenValidator` is the canonical reusable validator for authorable Messaging copy. It extracts referenced tokens, resolves the allowed set from `TokenContractRegistry` for the exact producer context, and reports unknown or registered-but-unavailable tokens as hard errors.

The same validator is reused by:

```text
Messaging config/setup validation
MessageTemplatePreset sync
CRM Message Template create/update validation
future authoring/export consumers
```

Do not pass arbitrary caller-owned `allowedTokens` lists. If `config/reference/tokens.php` or another prose/reference file exists, treat it as documentation only; it is not the executable allowlist.

Current schema corrections:

- `webinar.status` is not valid because `webinars` has no `status` column.
- waitlist source data is `source_page`, not `source`.
- join, cancel, registration, and playback links are available only in registered runtime
  contexts that explicitly compute or supply them.

Messaging tokens are resolved from:

1. universal recipient/contact message data supplied by Messaging;
2. module-specific message data supplied by the producer module;
3. caller/enrollment/start-context payload explicitly passed into `DispatchMessageAction`.

Do not invent token names unless the owning runtime payload/data object is also updated to supply them.

Do not use runtime-only URL tokens in static config unless the source path that supplies the token is documented.

## Canonical contact fields and client-facing aliases

The runtime source of truth uses canonical Contact fields and tokens.

Authoring UI may expose friendlier, client-specific aliases such as:

```text
fan_first_name    -> contact.first_name
lead_first_name   -> contact.first_name
customer_name     -> contact.name
borrower_email    -> contact.email
```

The alias is a presentation/authoring convenience. It must not create a new runtime field, payload contract, database column, event key, route key, preset key, or validation branch.

Validation should normalize a recognized alias to its canonical field before checking availability for the current authoring context. Unknown aliases should produce actionable validation findings.

## Message template preset token rules

MessageTemplatePreset records must use the same documented token rules as config-defined templates.

Editing copy in CRM/admin UI does not make new tokens valid.

Token validation should apply to:

```text
payload.subject
payload.body
payload.message
payload.cta.url
payload.secondary_link.url
```

Runtime-only URL tokens must still be supplied by the owning runtime data object or caller payload. A DB-customized template must not guess URLs that the runtime context does not provide.

## Consent acknowledgement system markers are not message tokens

Consent acknowledgement copy is resolved through `ConsentOptInDefinitionResolver`, not through ordinary per-scope Messaging templates.

System markers such as:

```text
:client_name
:consent_topic
```

belong to that consent acknowledgement resolver. They are not normal authorable `{token}` values and should not appear in regular reusable message templates.

Do not substitute `{client_name}` or another brace token unless `TokenContractRegistry` explicitly registers it for the exact producer context.

## Token ownership model

### Universal Messaging / Contact tokens

Messaging owns universal Contact-recipient tokens.

These should be available whenever the scheduled message recipient is a `Contact`, regardless of whether the message came from Broadcasts, Campaigns, FlowRoutes, Webinars, or another module.

Use these in email/SMS payload text when the recipient is a Contact:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{contact.first_name}`
- `{contact.last_name}`
- `{contact.name}`
- `{contact.email}`
- `{contact.phone}`

### Module-specific tokens

Producer modules own module-specific token data objects.

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

A module-specific token may be used only when that module’s dispatch path supplies the matching message data.

### Campaign/enrollment-context tokens

Campaigns do not invent message tokens.

Campaigns may carry start/enrollment payload forward and pass it into Messaging, but the payload must come from the producer/caller that enrolled the contact or started the journey.

Use campaign/context tokens only when the campaign start context/caller supplies them and the campaign/context documents them.

Examples of campaign/context tokens that must be explicitly supplied:

- `{next_step_url}`
- `{application_url}`
- `{contact_url}`
- `{webinar_registration_url}`
- `{webinar_playback_url}` when not coming from a Webinars post-event dispatch path

## Unresolved-token behavior

Messaging should detect unresolved `{token}` values before provider send.

Recommended behavior:

- unresolved token in subject: skip/fail before send;
- unresolved token in `cta.url`: skip/fail before send;
- unresolved token in `secondary_link.url`: skip/fail before send;
- unresolved token in SMS message: skip/fail before send;
- unresolved token in marketing body: skip before send with operator/debug reason;
- unresolved token in transactional body: fail or skip before send with operator/debug reason.

Do not silently remove lines containing unresolved tokens by default.

Optional sections may be supported later with an explicit config shape such as `optional_sections`, but line removal should be intentional, not automatic.

## Imported-contact permission invitation tokens

Permission invitation emails receive runtime payload data from `ContactPermissionInvitationService::publicEmailPayload()` immediately before send.

Use these in the email body when direct URL text is needed:

- `{permission_invitation.url}`
- `:permission_invitation.url`
- `{contact.first_name}`
- `{contact.last_name}`
- `{contact.name}`
- `{contact.email}`

The email template also receives a first-class CTA array and secondary link array:

```text
cta.label
cta.url
secondary_link.label
secondary_link.url
```

Prefer placing `{cta}` on its own line in the email body when the button should render inline. If `{cta}` is omitted and a CTA is present, the default email view renders the button after the body.

Permission invitation URLs should be generated by Messaging runtime code. Do not hand-author or guess tokens.

## Webinar registration tokens

Expected from `WebinarMessageData::fromRegistration($registration)->toArray()`:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{webinar_title}`
- `{webinar_slug}`
- `{webinar_start_date}`
- `{webinar_start_time}`
- `{webinar_timezone}`
- `{webinar_join_url}`
- `{cancel_registration_url}`
- `{cta}` when `payload.cta` exists

## Webinar waitlist tokens

Expected from `WebinarMessageData::fromWaitlistSignup($signup, $webinar)->toArray()`:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{webinar_title}`
- `{webinar_slug}`
- `{webinar_start_date}`
- `{webinar_start_time}`
- `{webinar_timezone}`
- `{webinar_registration_url}`
- `{cta}` when `payload.cta` exists

Avoid:

- `{registration_url}`

Use:

- `{webinar_registration_url}`

## Post-webinar transactional tokens

Use these for replay/follow-up messages after playback is resolved by Webinars runtime code:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{webinar_title}`
- `{webinar_slug}`
- `{webinar_start_date}`
- `{webinar_start_time}`
- `{webinar_timezone}`
- `{webinar_playback_url}`
- `{registration_attended_at}`
- `{cta}` when `payload.cta` exists

Prefer:

- `{webinar_playback_url}`

Avoid in copy unless explicitly supplied for compatibility:

- `{playback_url}`

## Campaign/nurture tokens

Campaign messages may always use universal Contact tokens when the recipient is a Contact:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{contact.first_name}`
- `{contact.last_name}`
- `{contact.name}`
- `{contact.email}`
- `{contact.phone}`
- `{cta}` when `payload.cta` exists and the CTA URL resolves

Campaign messages may use additional tokens only when the enrollment caller supplies them through payload/start context and the campaign/context documents them.

Use these only when explicitly supplied:

- `{webinar_title}`
- `{webinar_slug}`
- `{webinar_start_date}`
- `{webinar_start_time}`
- `{webinar_timezone}`
- `{webinar_playback_url}`
- `{next_step_url}`
- `{application_url}`
- `{contact_url}`
- `{webinar_registration_url}`

Default campaign/nurture configs should not include runtime-only URL tokens unless the default preset also documents and supplies the source payload.

## Canonical replacements

Prefer these replacements:

- `{webinar_starts_at}` -> `{webinar_start_date}` + `{webinar_start_time}`
- `{webinar_replay_url}` -> `{webinar_playback_url}`
- `{playback_url}` -> `{webinar_playback_url}`
- `{registration_url}` -> `{webinar_registration_url}` or `{webinar_join_url}`, depending on behavior

## Available field picker direction

Client/operator editors should expose available tokens from `TokenContractRegistry` as friendly
fields through an `Insert field` / `Add field` interaction.

The picker should show friendly labels and insert stable syntax such as:

```text
{first_name}
{webinar_title}
{contact.email}
```

Use the current runtime token syntax. The UI should hide syntax complexity from operators where possible, but stored copy must remain directly valid for `MessageTemplateTokenValidator`.

Do not add a field to the picker unless the current message/context can actually supply it.
