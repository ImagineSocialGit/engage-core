# Engage Core Messaging Token Reference

Tokens are resolved from the payload passed to `DispatchMessageAction`, plus recipient/context data supplied by recipient payload providers.

Do not invent token names unless the caller payload/provider is also updated to supply them.

## Common contact tokens

Use these in email/SMS payload text when the recipient is a Contact:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`

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
- `{webinar_join_url}`
- `{cta}` when `payload.cta` exists

## Post-webinar transactional tokens

Use these for replay/follow-up messages after playback is resolved:

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

Campaign messages should use tokens available from the Contact payload and campaign start context.

Common current safe tokens:

- `{first_name}`
- `{last_name}`
- `{name}`
- `{email}`
- `{phone}`
- `{cta}` when `payload.cta` exists

Use these only when the campaign start context/caller supplies them and the campaign/context documents them:

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

## Canonical replacements

Prefer these replacements:

- `{webinar_starts_at}` -> `{webinar_start_date}` + `{webinar_start_time}`
- `{webinar_replay_url}` -> `{webinar_playback_url}`
- `{playback_url}` -> `{webinar_playback_url}`
- `{registration_url}` -> `{webinar_registration_url}` or `{webinar_join_url}`, depending on behavior